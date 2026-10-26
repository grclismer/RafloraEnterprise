<?php

namespace App\Services;

use App\Models\AssetReturn;
use App\Models\Booking;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\ReturnItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryDispatchService
{
    /**
     * Dispatch items for a booking, optionally with an admin reason.
     *
     * @param Booking $booking
     * @param array $dispatchItems Array of ['inventory_item_id' => int, 'quantity' => float]
     * @param int $performedByUserId
     * @param string|null $adminReason Required if performed by admin
     * @throws \Exception
     */
    public function dispatchItems(Booking $booking, array $dispatchItems, int $performedByUserId, ?string $adminReason = null): void
    {
        // 1. Authorization & Legacy checks
        if (is_null($booking->confirmed_at)) {
            // Unclear legacy state
            throw new \LogicException('Booking confirmation date is UNCLEAR (null). Cannot safely process dispatch for potentially legacy bookings without a defined cutover state. Minimum safe resolution: Backfill confirmed_at based on AuditLogs or reject operation.');
        }

        if (in_array($booking->status, ['event_completed', 'completed', 'cancelled', 'declined', 'rejected', 'pending_return', 'pending_resolution'], true)) {
            throw new \LogicException("Booking #{$booking->id} cannot dispatch items while it is {$booking->status_display_label}.");
        }

        if (!$booking->hasVerifiedPayment()) {
            throw new \LogicException('Cannot dispatch materials until a down payment or full payment has been verified.');
        }

        if (!$booking->hasValidPreparationSchedule()) {
            throw new \LogicException('Cannot dispatch materials until a valid preparation start date is scheduled.');
        }

        DB::transaction(function () use ($booking, $dispatchItems, $performedByUserId, $adminReason) {
            // Lock the booking to prevent concurrent modifications
            $lockedBooking = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            // Extract requested item IDs and sort them to prevent deadlocks
            $itemIds = collect($dispatchItems)->pluck('inventory_item_id')->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->toArray();

            // Lock inventory items in deterministic ascending order
            $lockedItems = !empty($itemIds)
                ? InventoryItem::whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
                : collect();

            $reusableIds = $lockedBooking->reusableBookingItems()
                ->pluck('inventory_item_id')
                ->map(fn ($id) => (int) $id)
                ->unique();

            foreach ($dispatchItems as $dispatchReq) {
                $itemId = $dispatchReq['inventory_item_id'];
                $quantityToDispatch = (float) $dispatchReq['quantity'];

                if ($quantityToDispatch <= 0) {
                    continue; // Skip zero or negative dispatch requests
                }

                if (!$lockedItems->has($itemId)) {
                    $archivedName = InventoryItem::withTrashed()->find($itemId)?->name;
                    throw new \InvalidArgumentException($archivedName
                        ? "\"{$archivedName}\" is archived, so it cannot be dispatched. Restore it in Inventory Management first."
                        : 'An item in this dispatch no longer exists in inventory. Refresh the page and try again.');
                }

                $item = $lockedItems->get($itemId);

                if (!$reusableIds->contains((int) $itemId)) {
                    throw new \LogicException("\"{$item->name}\" is not classified as reusable inventory for this booking.");
                }

                // 2. Validate Reservation and Outstanding Quantity
                // Outstanding = still held for this booking: locks - releases - net dispatch
                // (InventoryItem::reservedForBooking, the same rule as reserved_stock).
                $outstandingReservation = $item->reservedForBooking($booking->id);

                // Messages name the item and its unit so Admin / Staff know exactly which material it is.
                if (round($quantityToDispatch, 4) > round($outstandingReservation, 4)) {
                    throw new \LogicException(sprintf(
                        'Cannot dispatch %s of "%s": only %s reserved for this booking. Reserve more under Preparation, or dispatch fewer.',
                        $this->amount($quantityToDispatch, $item),
                        $item->name,
                        $this->amount($outstandingReservation, $item) . ($this->isOne($outstandingReservation) ? ' is' : ' are')
                    ));
                }

                if (round($quantityToDispatch, 4) > round($item->current_stock, 4)) {
                    throw new \LogicException(sprintf(
                        'Cannot dispatch %s of "%s": only %s in stock. Receive stock in Inventory Management, or dispatch fewer.',
                        $this->amount($quantityToDispatch, $item),
                        $item->name,
                        $this->amount((float) $item->current_stock, $item) . ($this->isOne((float) $item->current_stock) ? ' is' : ' are')
                    ));
                }

                // 3. Deduct Physical Stock
                $item->current_stock -= $quantityToDispatch;
                $item->save();

                // 4. Record Dispatch Transaction
                // Dispatch is recorded as negative quantity_change
                InventoryTransaction::create([
                    'inventory_item_id' => $item->id,
                    'booking_id' => $booking->id,
                    'quantity_change' => -$quantityToDispatch,
                    'transaction_type' => 'dispatch',
                    'performed_by' => $performedByUserId,
                    'reason' => $adminReason ?? 'Staff Dispatch',
                ]);
            }
        });
    }

    /** "240 stems", "1 piece", "2.5 kg": a quantity with the item's unit, without trailing zeros. */
    private function amount(float $quantity, InventoryItem $item): string
    {
        $number = rtrim(rtrim(number_format($quantity, 2, '.', ','), '0'), '.');
        $unit = trim((string) $item->unit);

        return $unit === '' ? $number : $number . ' ' . $unit;
    }

    private function isOne(float $quantity): bool
    {
        return round($quantity, 4) == 1.0;
    }

    /**
     * Admin Correction for a previous dispatch.
     */
    public function correctDispatch(InventoryTransaction $originalDispatch, float $correctionQuantity, int $performedByUserId, string $reason): void
    {
        if ($originalDispatch->transaction_type !== 'dispatch') {
            throw new \InvalidArgumentException("Can only correct a 'dispatch' transaction.");
        }

        DB::transaction(function () use ($originalDispatch, $correctionQuantity, $performedByUserId, $reason) {
            // Lock booking and inventory item deterministically
            $lockedBooking = Booking::where('id', $originalDispatch->booking_id)->lockForUpdate()->firstOrFail();
            $lockedItem = InventoryItem::where('id', $originalDispatch->inventory_item_id)->lockForUpdate()->firstOrFail();

            if ($lockedBooking->status === 'completed') {
                throw new \LogicException("Cannot correct dispatch: booking #{$lockedBooking->id} is already completed.");
            }

            // Check if return audit has already been completed for this booking
            $completedReturn = AssetReturn::where('booking_id', $originalDispatch->booking_id)
                ->where('status', 'Completed')
                ->exists();

            if ($completedReturn) {
                throw new \LogicException("Cannot correct dispatch: return audit for booking #{$originalDispatch->booking_id} has already been completed.");
            }

            if ($correctionQuantity <= 0) {
                throw new \LogicException("Correction quantity must be positive.");
            }

            // Total previously corrected for this original dispatch
            $existingCorrections = InventoryTransaction::where('reference_transaction_id', $originalDispatch->id)
                ->where('transaction_type', 'dispatch_correction')
                ->sum('quantity_change');

            // Original dispatch is negative (e.g., -5). 
            // Maximum correction cannot exceed the absolute value of the original dispatch + existing corrections.
            $maxCorrection = abs((float) $originalDispatch->quantity_change) - $existingCorrections;

            if (round($correctionQuantity, 4) > round($maxCorrection, 4)) {
                throw new \LogicException("Correction quantity {$correctionQuantity} exceeds maximum allowed correction of {$maxCorrection}.");
            }

            // Check return item reconciliation for this specific item
            $returnItems = ReturnItem::whereHas('assetReturn', function ($q) use ($originalDispatch) {
                $q->where('booking_id', $originalDispatch->booking_id);
            })->where('inventory_item_id', $originalDispatch->inventory_item_id)->get();

            $alreadyAccountedQty = 0.0;
            foreach ($returnItems as $ri) {
                $itemAcc = (float) ($ri->quantity_good ?? 0) + (float) ($ri->quantity_damaged ?? 0) + (float) ($ri->quantity_lost ?? 0);
                if ($itemAcc == 0 && in_array($ri->condition, ['good', 'damaged', 'lost'], true)) {
                    $itemAcc = (float) ($ri->quantity_returned ?? 0);
                }
                $alreadyAccountedQty += $itemAcc;
            }

            // Net dispatched for this booking and item across all dispatches and corrections
            $netDispatchedForItem = abs((float) InventoryTransaction::where('booking_id', $originalDispatch->booking_id)
                ->where('inventory_item_id', $originalDispatch->inventory_item_id)
                ->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])
                ->sum('quantity_change'));

            $maxAllowedByReturn = max(0.0, $netDispatchedForItem - $alreadyAccountedQty);

            if (round($correctionQuantity, 4) > round($maxAllowedByReturn, 4)) {
                throw new \LogicException("Correction quantity {$correctionQuantity} exceeds maximum allowed by return audit ({$maxAllowedByReturn}). {$alreadyAccountedQty} unit(s) have already been accounted for in return tracking.");
            }

            // Restore physical stock
            $lockedItem->current_stock += $correctionQuantity;
            $lockedItem->save();

            // Record correction transaction
            InventoryTransaction::create([
                'inventory_item_id' => $lockedItem->id,
                'booking_id' => $originalDispatch->booking_id,
                'reference_transaction_id' => $originalDispatch->id,
                'quantity_change' => $correctionQuantity, // Positive change to reverse the dispatch
                'transaction_type' => 'dispatch_correction',
                'performed_by' => $performedByUserId,
                'reason' => $reason,
            ]);

            // If the booking has already passed event completion (e.g. event_completed, pending_return),
            // releasing the unfulfilled reservation ensures the newly restored undispatched units
            // are immediately cleared from reserved_stock so they are not left lingering.
            if (in_array($lockedBooking->status, ['event_completed', 'pending_return', 'pending_resolution'], true)) {
                $this->releaseUnfulfilledReservations(
                    $lockedBooking,
                    $performedByUserId,
                    "Released unfulfilled reservation after dispatch correction for booking #{$lockedBooking->id}"
                );
            }
        });
    }

    /**
     * Release unfulfilled reservations for a booking.
     *
     * Formula: release quantity = locked quantity - previously released quantity - net dispatched quantity
     *
     * @param Booking $booking
     * @param int|null $performedByUserId
     * @param string|null $reason
     * @return array Array of created InventoryTransaction models
     */
    public function releaseUnfulfilledReservations(Booking $booking, ?int $performedByUserId = null, ?string $reason = null): array
    {
        return DB::transaction(function () use ($booking, $performedByUserId, $reason) {
            // Find all inventory items that have locks or are confirmed for this booking
            $lockedItemIds = InventoryTransaction::where('booking_id', $booking->id)
                ->where('transaction_type', 'booking_lock')
                ->pluck('inventory_item_id')
                ->all();

            $confirmedItemIds = $booking->bookingItems()
                ->whereNotNull('confirmed_at')
                ->pluck('inventory_item_id')
                ->filter()
                ->all();

            $itemIds = collect(array_merge($lockedItemIds, $confirmedItemIds))
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->toArray();

            if (empty($itemIds)) {
                return [];
            }

            // Acquire row locks in deterministic order (ascending PK)
            $inventoryItems = InventoryItem::whereIn('id', $itemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $createdTransactions = [];
            $userId = $performedByUserId ?? (auth()->check() ? auth()->id() : null) ?? $booking->handled_by;

            foreach ($itemIds as $itemId) {
                $item = $inventoryItems->get($itemId);
                if (!$item) {
                    continue;
                }

                // What is still held and was never dispatched (InventoryItem::reservedForBooking).
                $releaseQty = $item->reservedForBooking($booking->id);

                if (round($releaseQty, 4) <= 0) {
                    continue;
                }

                $createdTransactions[] = InventoryTransaction::create([
                    'inventory_item_id' => $itemId,
                    'booking_id' => $booking->id,
                    'quantity_change' => $releaseQty,
                    'transaction_type' => 'booking_release',
                    'reason' => $reason ?? ('Released unfulfilled reservation for booking #' . $booking->id),
                    'performed_by' => $userId,
                ]);
            }

            return $createdTransactions;
        });
    }
}
