<?php

namespace App\Services;

use App\Models\AssetReturn;
use App\Models\Booking;
use App\Models\InventoryTransaction;
use App\Models\ReturnItem;
use Illuminate\Support\Facades\DB;

/**
 * The one way a booking's return record is created: from what was actually dispatched (the
 * inventory ledger), once per booking. Admin booking actions, Admin return tracking and the
 * Staff event page all use it, so they cannot build different item lists or a second record.
 */
class ReturnRecordService
{
    /**
     * Net dispatched quantity per reusable (non-perishable) inventory item, keyed by item id.
     * An item already listed on the booking's return keeps its quantity even if it is perishable
     * now (its type was edited, or a duplicate was merged into a perishable item), so the audit
     * line stays editable.
     *
     * @return array<int, float>
     */
    public function dispatchedQuantities(Booking $booking): array
    {
        $onReturn = ReturnItem::query()
            ->whereIn('return_id', AssetReturn::where('booking_id', $booking->id)->select('id'))
            ->pluck('inventory_item_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $reusableIds = $booking->reusableBookingItems()
            ->pluck('inventory_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        $dispatchItemIds = InventoryTransaction::query()
            ->where('booking_id', $booking->id)
            ->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])
            ->pluck('inventory_item_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique();

        $dispatchableIds = $reusableIds
            ->merge($dispatchItemIds)
            ->unique();

        return InventoryTransaction::with('inventoryItem')
            ->where('booking_id', $booking->id)
            ->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])
            ->get()
            ->filter(function ($tx) use ($dispatchableIds, $onReturn) {
                if (!$tx->inventoryItem) {
                    return false;
                }

                $itemId = (int) $tx->inventory_item_id;
                $isDispatchable = $dispatchableIds->contains($itemId);
                $isKnownReturnItem = in_array($itemId, $onReturn, true);

                // Some historical or hand-created dispatches are not attached to a BookingItem record,
                // but they still represent reusable inventory that should be audited.
                if (!$isDispatchable && !$isKnownReturnItem) {
                    return false;
                }

                // Items already on a return line stay editable even if they became perishable later.
                if ($tx->inventoryItem->is_perishable && !$isKnownReturnItem) {
                    return false;
                }

                return true;
            })
            ->groupBy('inventory_item_id')
            ->map(fn ($txs) => abs((float) $txs->sum('quantity_change')))
            ->filter(fn ($qty) => $qty > 0)
            ->all();
    }

    /**
     * The booking's return record: the existing one, or a new one listing every dispatched
     * reusable item. The booking row is locked first, so concurrent requests create it once.
     * With nothing dispatched it returns null, unless $allowEmpty (an Admin closing a
     * zero-hardware audit).
     */
    public function openFor(Booking $booking, bool $allowEmpty = false): ?AssetReturn
    {
        return DB::transaction(function () use ($booking, $allowEmpty): ?AssetReturn {
            Booking::whereKey($booking->id)->lockForUpdate()->first();

            $existing = AssetReturn::where('booking_id', $booking->id)->latest()->orderByDesc('id')->first();
            if ($existing) {
                return $existing;
            }

            // Delivery orders are sold: nothing comes back unless the order was cancelled after dispatch.
            if ($booking->isDelivery() && $booking->status !== 'cancelled') {
                return null;
            }

            $dispatched = $this->dispatchedQuantities($booking);
            if ($dispatched === [] && !$allowEmpty) {
                return null;
            }

            $return = AssetReturn::create([
                'booking_id' => $booking->id,
                'assigned_staff_id' => $booking->staff_id,
                'status' => 'Pending',
                'total_damage_charge' => 0,
                'approval_status' => 'not_required',
            ]);

            foreach (array_keys($dispatched) as $inventoryItemId) {
                ReturnItem::create([
                    'return_id' => $return->id,
                    'inventory_item_id' => $inventoryItemId,
                    'quantity_returned' => 0,
                    'quantity_good' => 0,
                    'quantity_damaged' => 0,
                    'quantity_lost' => 0,
                    'condition' => 'pending',
                    'final_amount' => 0,
                    'damage_charge' => 0,
                ]);
            }

            return $return;
        });
    }
}
