<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Quotation;
use App\Models\QuotationHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class QuotationIssuanceService
{
    public function __construct(
        private readonly QuotationPricingService $pricingService
    ) {}

    /**
     * Issue an authoritative quotation snapshot for the given booking.
     *
     * This method is atomic — all DB writes happen inside a transaction.
     * On failure an exception is thrown and the caller should roll back.
     *
     * Business rules enforced:
     *  - Cannot reissue if the active quotation is already accepted by the client.
     *  - Every confirmed material item must have a quoted_unit_price > 0
     *    (i.e., Admin has reviewed the price before issuance).
     *  - AI recommendations (ai_recommended_price) are NEVER used as the
     *    client-facing price — only Admin-approved quoted_unit_price is.
     *  - Package bookings use the existing final_quoted_price without
     *    recalculating through the raw-materials engine.
     *  - The quotation's price and item snapshot come from the same quotable
     *    material lines (QuotationPricingService::quotableItems()).
     *  - An Admin override price, when given, is the quotation's final price; the
     *    booking takes the issued figures so balances and payments follow the quotation.
     *
     * @param  Booking  $booking
     * @param  int      $issuedByUserId     The admin user who is issuing.
     * @param  string|null $validUntil      Optional validity date; defaults to 7 days from today.
     * @param  float|null  $overridePrice   The final price the Admin set by hand; null uses the calculated total.
     * @return Quotation  The newly-created, immutable quotation record.
     *
     * @throws \RuntimeException  if validation fails (unreviewed items, no items, already accepted, etc.)
     * @throws \Throwable         on any DB / unexpected error (triggers rollback)
     */
    public function issue(Booking $booking, int $issuedByUserId, ?string $validUntil = null, bool $isPriceReconfirmation = false, ?float $overridePrice = null): Quotation
    {
        return DB::transaction(function () use ($booking, $issuedByUserId, $validUntil, $isPriceReconfirmation, $overridePrice): Quotation {
            $downpaymentPct = (float) \App\Models\Setting::getSetting('downpayment_percentage', 50.0);

            // ------------------------------------------------------------------
            // 0. Guard: cannot reissue if client has already accepted a quotation
            //    (unless explicitly performing price reconfirmation revision)
            // ------------------------------------------------------------------
            if (!$isPriceReconfirmation) {
                $hasAccepted = Quotation::where('booking_id', $booking->id)
                    ->where('status', Quotation::STATUS_ACCEPTED)
                    ->exists();

                if ($hasAccepted) {
                    throw new \RuntimeException(
                        'Cannot reissue: the client has already accepted a quotation for this booking. ' .
                        'Please contact the client before issuing a new quotation.'
                    );
                }
            }

            // ------------------------------------------------------------------
            // 1. Load the quotable booking items (confirmed, with a quantity, not removed)
            // ------------------------------------------------------------------
            $booking->load('bookingItems.inventoryItem');
            $confirmedItems = $this->pricingService->quotableItems($booking);

            if ($confirmedItems->isEmpty()) {
                throw new \RuntimeException('Cannot issue a quotation: no confirmed materials exist for this booking.');
            }

            // ------------------------------------------------------------------
            // 2. AI review boundary — no unreviewed zero-priced AI items
            // ------------------------------------------------------------------
            // Applies whether or not Gemini supplied a price: an AI row without an Admin price
            // must never reach the client as a ₱0 line.
            $unpricedAiItems = $confirmedItems->filter(
                fn (BookingItem $item): bool =>
                    $item->is_ai_suggested &&
                    (float) $item->quoted_unit_price <= 0.0
            );

            if ($unpricedAiItems->isNotEmpty()) {
                $names = $unpricedAiItems->pluck('item_name')->join(', ');
                throw new \RuntimeException(
                    "Cannot issue quotation: the following AI-suggested items have not been priced by Admin yet: {$names}. " .
                    "Set a quoted_unit_price for each before issuing."
                );
            }

            // ------------------------------------------------------------------
            // 3. Price the same items via the centralized pricing service
            // ------------------------------------------------------------------
            $totals = $this->pricingService->quotationTotals($booking, $confirmedItems);
            $rawSum = $totals['raw_materials_sum'];
            $multiplier = $totals['multiplier'];
            $laborAmount = $totals['labor_amount'];
            $finalPrice = $overridePrice !== null ? round($overridePrice, 2) : $totals['total'];

            // The booking carries the issued figures, so balances and payments follow this
            // quotation rather than an earlier working price (saved with step 8).
            if (empty($booking->package_id)) {
                $booking->raw_materials_sum = $rawSum;
                $booking->multiplier = $multiplier;
            }
            $booking->final_quoted_price = $finalPrice;
            $booking->total_quoted = $finalPrice;

            // ------------------------------------------------------------------
            // 4. Build the items snapshot (Admin-approved prices only, no AI metadata)
            // ------------------------------------------------------------------
            $itemsSnapshot = $confirmedItems
                ->groupBy(fn (BookingItem $item): string => $item->inventory_item_id
                    ? 'inventory:' . $item->inventory_item_id
                    : 'booking-line:' . $item->id)
                ->map(function (Collection $lines) use ($multiplier): array {
                    /** @var BookingItem $first */
                    $first = $lines->first();
                    $quantity = (float) $lines->sum(fn (BookingItem $item) => (float) $item->quantity);
                    $amount = round((float) $lines->sum(
                        fn (BookingItem $item) => (float) $item->quantity * (float) $item->quoted_unit_price
                    ), 2);
                    $quotedUnitPrice = $quantity > 0
                        ? $amount / $quantity
                        : (float) $first->quoted_unit_price;
                    $sellingUnitPrice = $quotedUnitPrice * $multiplier;

                    return [
                        'item_name' => $first->inventoryItem?->name ?? $first->item_name,
                        'quantity' => $quantity,
                        'quoted_unit_price' => $quotedUnitPrice,
                        'amount' => $amount,
                        'selling_unit_price' => $sellingUnitPrice,
                        'selling_amount' => round($amount * $multiplier, 2),
                    ];
                })
                ->values()
                ->toArray();

            // ------------------------------------------------------------------
            // 6. Determine next version number
            // ------------------------------------------------------------------
            $maxVersion = Quotation::where('booking_id', $booking->id)->max('version') ?? 0;
            $nextVersion = (int) $maxVersion + 1;

            // ------------------------------------------------------------------
            // 7. Mark superseded quotations
            // ------------------------------------------------------------------
            if ($isPriceReconfirmation) {
                Quotation::where('booking_id', $booking->id)
                    ->whereIn('status', [Quotation::STATUS_ISSUED, Quotation::STATUS_ACCEPTED])
                    ->update(['status' => Quotation::STATUS_SUPERSEDED]);
            } else {
                Quotation::where('booking_id', $booking->id)
                    ->where('status', Quotation::STATUS_ISSUED)
                    ->update(['status' => Quotation::STATUS_SUPERSEDED]);
            }

            // ------------------------------------------------------------------
            // 8. Establish synchronized validity window (authoritative 7-day rule)
            // ------------------------------------------------------------------
            $resolvedValidUntil = $validUntil !== null
                ? \Carbon\Carbon::parse($validUntil)->toDateString()
                : \Carbon\Carbon::today()->addDays(7)->toDateString();

            $booking->price_valid_until = $resolvedValidUntil;
            if ($isPriceReconfirmation) {
                $booking->status = 'quotation_sent';
            }
            $booking->save();

            // ------------------------------------------------------------------
            // 8b. Determine tentative status & reconfirmation timestamp
            // ------------------------------------------------------------------
            if ($isPriceReconfirmation) {
                $isTentative = false;
                $reconfirmedAt = \Carbon\Carbon::now();
            } else {
                $longTermThreshold = \App\Models\Setting::getLongTermBookingThresholdDays();
                $isTentative = $booking->event_date && \Carbon\Carbon::today()->diffInDays($booking->event_date, false) > $longTermThreshold;
                $reconfirmedAt = null;
            }

            // ------------------------------------------------------------------
            // 9. Create the new immutable quotation record
            // ------------------------------------------------------------------
            $quotation = Quotation::create([
                'booking_id'             => $booking->id,
                'issued_by'              => $issuedByUserId,
                'status'                 => Quotation::STATUS_ISSUED,
                'version'                => $nextVersion,
                'raw_materials_sum'      => $rawSum,
                'multiplier'             => $multiplier,
                'labor_method'           => $booking->labor_method,
                'labor_rate'             => $booking->labor_rate,
                'labor_amount'           => $laborAmount,
                'final_quoted_price'     => $finalPrice,
                'downpayment_percentage' => round(max(0, min(100, $downpaymentPct)), 2),
                'valid_until'            => $resolvedValidUntil,
                'items_snapshot'         => $itemsSnapshot,
                'is_tentative'           => (bool) $isTentative,
                'reconfirmed_at'         => $reconfirmedAt,
            ]);

            // ------------------------------------------------------------------
            // 10. Record issuance event in quotation_history & audit log
            // ------------------------------------------------------------------
            $reason = $isPriceReconfirmation
                ? "Quotation v{$nextVersion} issued as revised price reconfirmation."
                : "Quotation v{$nextVersion} issued by admin.";

            QuotationHistory::create([
                'booking_id'   => $booking->id,
                'field_changed'=> 'quotation_issued',
                'old_value'    => null,
                'new_value'    => [
                    'quotation_id'      => $quotation->id,
                    'version'           => $nextVersion,
                    'final_quoted_price'=> $quotation->final_quoted_price,
                    'is_tentative'      => (bool) $isTentative,
                ],
                'reason'     => $reason,
                'changed_by' => $issuedByUserId,
            ]);

            if ($isPriceReconfirmation) {
                \App\Models\AdminAlert::where('type', 'price_reconfirmation_due')
                    ->where('booking_id', $booking->id)
                    ->where('is_read', false)
                    ->update(['is_read' => true]);

                \App\Models\AuditLog::record(
                    $issuedByUserId,
                    'price_reconfirmed_revised',
                    "Revised quotation v{$nextVersion} issued following price reconfirmation with total ₱" . number_format((float) $quotation->final_quoted_price, 2),
                    'quotation_reconfirmation',
                    [
                        'quotation_id' => $quotation->id,
                        'version' => $nextVersion,
                        'final_quoted_price' => (float) $quotation->final_quoted_price,
                    ]
                );
            }

            return $quotation;
        });
    }

    /**
     * Issue a revised quotation version specifically during long-term price reconfirmation.
     */
    public function reconfirmWithRevision(Booking $booking, int $issuedByUserId, ?string $validUntil = null, ?float $overridePrice = null): Quotation
    {
        return $this->issue($booking, $issuedByUserId, $validUntil, true, $overridePrice);
    }
}
