<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\TemporaryGuestBooking;
use Illuminate\Support\Collection;

/**
 * Authoritative end-to-end booking workflow (README.md):
 *
 *   Guest Workflow  : Request Submitted → Awaiting Claim
 *   Client Workflow : Raflora Review → Material Preparation / Validation → Quotation
 *                     → Approval → Payment → Confirmed
 *   Staff Workflow  : Preparation & Reservation → Dispatch → Event Execution
 *                     → Material Return → Inventory Reconciliation → Completion
 *
 * Every stage is derived only from persisted business state (claim state, booking
 * status, review/material confirmation, quotations, payments, inventory transactions
 * and return audits). Views render this resolution instead of re-deriving stage logic.
 */
class BookingWorkflowService
{
    public const STATE_COMPLETE = 'complete';
    public const STATE_CURRENT = 'current';
    public const STATE_UPCOMING = 'upcoming';
    public const STATE_STOPPED = 'stopped';

    public const PHASES = [
        'guest' => 'Guest Workflow',
        'client' => 'Client Workflow',
        'staff' => 'Staff Workflow',
    ];

    public const STAGES = [
        'request_submitted' => ['phase' => 'guest', 'label' => 'Request Submitted'],
        'awaiting_claim' => ['phase' => 'guest', 'label' => 'Awaiting Claim'],
        'raflora_review' => ['phase' => 'client', 'label' => 'Raflora Review'],
        'material_validation' => ['phase' => 'client', 'label' => 'Material Preparation / Validation'],
        'quotation' => ['phase' => 'client', 'label' => 'Quotation'],
        'approval' => ['phase' => 'client', 'label' => 'Approval'],
        'payment' => ['phase' => 'client', 'label' => 'Payment'],
        'confirmed' => ['phase' => 'client', 'label' => 'Confirmed'],
        'preparation_reservation' => ['phase' => 'staff', 'label' => 'Preparation & Reservation'],
        'dispatch' => ['phase' => 'staff', 'label' => 'Dispatch'],
        'event_execution' => ['phase' => 'staff', 'label' => 'Event Execution'],
        'material_return' => ['phase' => 'staff', 'label' => 'Material Return'],
        'inventory_reconciliation' => ['phase' => 'staff', 'label' => 'Inventory Reconciliation'],
        'completion' => ['phase' => 'staff', 'label' => 'Completion'],
    ];

    private const CONFIRMED_STATUSES = ['downpayment_received', 'confirmed', 'fully_paid', 'in_preparation'];

    private const POST_EVENT_STATUSES = ['event_completed', 'pending_return', 'pending_resolution'];

    private const TERMINAL_STATUSES = ['cancelled', 'declined', 'rejected'];

    public static function stageLabel(string $key): string
    {
        return self::STAGES[$key]['label'] ?? $key;
    }

    public static function stageNumber(string $key): int
    {
        $index = array_search($key, array_keys(self::STAGES), true);

        return $index === false ? 0 : $index + 1;
    }

    public static function stagePhase(string $key): ?string
    {
        return self::STAGES[$key]['phase'] ?? null;
    }

    public function resolve(Booking|TemporaryGuestBooking $booking): array
    {
        return $booking instanceof TemporaryGuestBooking
            ? $this->resolveTemporary($booking)
            : $this->forOrderType($booking, $this->resolveBooking($booking));
    }

    /**
     * Delivery orders (Booking::isDelivery()) are sold, not rented: Event Execution becomes
     * "Delivery" and there is no Material Return or Inventory Reconciliation, so those stages
     * are left out and the remaining stages are renumbered (DeliveryOrderService).
     */
    private function forOrderType(Booking $booking, array $workflow): array
    {
        if (!$booking->isDelivery()) {
            return $workflow;
        }

        unset($workflow['stages']['material_return'], $workflow['stages']['inventory_reconciliation']);
        $workflow['stages']['event_execution']['label'] = 'Delivery';

        $number = 0;
        foreach ($workflow['stages'] as $key => $stage) {
            $workflow['stages'][$key]['number'] = ++$number;
        }

        $workflow['phases'] = $this->phasesFor($workflow['stages']);
        $workflow['total'] = count($workflow['stages']);
        if ($workflow['current'] && isset($workflow['stages'][$workflow['current']])) {
            $workflow['current_number'] = $workflow['stages'][$workflow['current']]['number'];
            $workflow['current_label'] = $workflow['stages'][$workflow['current']]['label'];
        }

        return $workflow;
    }

    private function resolveTemporary(TemporaryGuestBooking $booking): array
    {
        $details = [
            'request_submitted' => $booking->created_at
                ? 'Submitted on ' . $booking->created_at->format('M j, Y g:i A') . '.'
                : 'Your booking request has been received.',
        ];

        if ($booking->isClaimed()) {
            $details['awaiting_claim'] = 'Claimed and linked to a registered client account.';
            $states = $this->statesWith(['request_submitted', 'awaiting_claim'], self::STATE_COMPLETE, self::STATE_UPCOMING);

            return $this->assemble($states, $details, null);
        }

        if ($booking->isExpired()) {
            $details['awaiting_claim'] = 'Expired unclaimed'
                . ($booking->expires_at ? ' on ' . $booking->expires_at->format('M j, Y g:i A') : '')
                . '. Submit a new booking request to continue.';
            $states = $this->statesWith(['request_submitted'], self::STATE_COMPLETE, self::STATE_STOPPED);

            return $this->assemble($states, $details, null, [
                'terminal' => 'expired',
                'terminal_label' => 'Guest Request Expired',
                'terminal_detail' => 'This guest request was not claimed before it expired, so the booking workflow cannot continue.',
            ]);
        }

        $details['awaiting_claim'] = 'Create an account or log in with the booking email to claim this request'
            . ($booking->expires_at ? ' before ' . $booking->expires_at->format('M j, Y g:i A') : '')
            . '. Raflora Review begins once it is claimed.';

        return $this->assemble($this->linearStates('awaiting_claim'), $details, 'awaiting_claim');
    }

    private function resolveBooking(Booking $booking): array
    {
        $status = $booking->status ?: 'pending';
        $claimed = !is_null($booking->client_id);

        $details = [
            'request_submitted' => $booking->created_at
                ? 'Submitted on ' . $booking->created_at->format('M j, Y g:i A') . '.'
                : 'The booking request has been received.',
            'awaiting_claim' => $claimed
                ? (!empty($booking->guest_access_token)
                    ? 'Claimed and linked to the client account.'
                    : 'Submitted from a registered client account — no claim needed.')
                : 'Create an account or log in with the booking email to claim this booking and continue.',
        ];

        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            if (!$claimed) {
                $details['awaiting_claim'] = null;
            }

            $states = $this->statesWith(
                $claimed ? ['request_submitted', 'awaiting_claim'] : ['request_submitted'],
                self::STATE_COMPLETE,
                self::STATE_STOPPED
            );

            $label = $status === 'cancelled' ? 'Booking Cancelled' : ($status === 'rejected' ? 'Booking Rejected' : 'Booking Declined');
            $reason = trim((string) ($booking->cancellation_reason ?? ''));

            return $this->assemble($states, $details, null, [
                'terminal' => $status,
                'terminal_label' => $label,
                'terminal_detail' => 'This booking process has been stopped and is no longer active.' . ($reason !== '' ? ' Reason: ' . $reason : ''),
            ]);
        }

        if ($status === 'completed') {
            $details['completion'] = 'Booking completed and records closed.';

            return $this->assemble($this->linearStates('completion', true), $details, 'completion', ['finished' => true]);
        }

        [$stageKey, $stageDetail] = $this->stageForStatus($booking, $status);
        $details[$stageKey] = $stageDetail;

        if (!$claimed) {
            // README: a guest must register and claim the booking before the client
            // workflow can continue. Stages Raflora already finished stay complete.
            $details[$stageKey] = trim($stageDetail . ' Continues after the booking is claimed.');
            $stageNumber = self::stageNumber($stageKey);
            $states = [];
            foreach (array_keys(self::STAGES) as $key) {
                $number = self::stageNumber($key);
                $states[$key] = match (true) {
                    $key === 'request_submitted' => self::STATE_COMPLETE,
                    $key === 'awaiting_claim' => self::STATE_CURRENT,
                    $number < $stageNumber => self::STATE_COMPLETE,
                    default => self::STATE_UPCOMING,
                };
            }

            return $this->assemble($states, $details, 'awaiting_claim');
        }

        return $this->assemble($this->linearStates($stageKey), $details, $stageKey);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function stageForStatus(Booking $booking, string $status, bool $allowCancellationLookup = true): array
    {
        if ($status === 'cancellation_requested') {
            $prior = Booking::normalizeStatus($booking->pre_cancellation_status);
            [$key, $detail] = ($allowCancellationLookup && $prior && $prior !== 'cancellation_requested' && !in_array($prior, self::TERMINAL_STATUSES, true) && $prior !== 'completed')
                ? $this->stageForStatus($booking, $prior, false)
                : ['raflora_review', ''];

            return [$key, trim('Cancellation requested — awaiting Raflora decision. ' . $detail)];
        }

        if (in_array($status, self::CONFIRMED_STATUSES, true)) {
            return $this->operationsStage($booking, $status);
        }

        if ($booking->isDelivery() && $status === 'event_in_progress') {
            return ['event_execution', 'Out for delivery — awaiting confirmation that the client received the items.'];
        }

        if ($booking->isDelivery() && in_array($status, self::POST_EVENT_STATUSES, true)) {
            // No return audit for delivery orders: received and paid closes the booking.
            return ['completion', $booking->isDeliveryReceived()
                ? 'Items received — awaiting final balance settlement.'
                : 'Awaiting confirmation that the client received the items.'];
        }

        if (in_array($status, self::POST_EVENT_STATUSES, true)) {
            return $this->postEventStage($booking, $status);
        }

        return match ($status) {
            'pending' => $this->reviewStage($booking),
            'change_requested' => ['quotation', 'Changes requested by the client — Raflora is revising the quotation.'],
            'quotation_sent' => ['quotation', $this->quotationDetail($booking)],
            'approved' => ['approval', 'Quotation accepted by the client — awaiting Raflora final approval.'],
            'admin_approved' => ['payment', $this->latestPaymentStatus($booking) === 'rejected'
                ? 'The previous payment reference was not verified — a corrected reference is required.'
                : 'Quotation approved by Raflora — downpayment submission is required.'],
            'payment_submitted', 'payment_pending' => ['payment', 'Payment reference submitted — awaiting Raflora verification.'],
            'event_in_progress' => ['event_execution', 'Event setup and execution are in progress.'],
            default => ['raflora_review', 'Current status: ' . $booking->status_display_label . '.'],
        };
    }

    /**
     * Raflora Review → Material Preparation / Validation → Quotation preparation (status: pending).
     *
     * @return array{0: string, 1: string}
     */
    private function reviewStage(Booking $booking): array
    {
        $items = $this->bookingItems($booking)->filter(fn ($item) => (float) $item->quantity > 0);

        // Legacy evidence: AI-suggested materials are only confirmed through Admin action.
        $hasAdminConfirmedAiItem = $items->contains(fn ($item) => (bool) $item->is_ai_suggested && !is_null($item->confirmed_at));

        if (!$booking->isReviewed() && !$hasAdminConfirmedAiItem) {
            return ['raflora_review', 'Raflora is reviewing the event details and inspiration before validating materials.'];
        }

        $total = $items->count();
        if ($total === 0) {
            return ['material_validation', 'Raflora is preparing the material list for this event.'];
        }

        $unconfirmed = $items->filter(fn ($item) => is_null($item->confirmed_at))->count();
        if ($unconfirmed > 0) {
            return ['material_validation', sprintf('%d of %d materials validated by Raflora.', $total - $unconfirmed, $total)];
        }

        return ['quotation', 'All materials validated — the official quotation is being prepared.'];
    }

    private function quotationDetail(Booking $booking): string
    {
        $quotation = $booking->activeQuotation;
        $validUntil = $quotation?->valid_until ?? $booking->price_valid_until;

        if ($validUntil && $validUntil->copy()->endOfDay()->isPast()) {
            return 'Quotation expired on ' . $validUntil->format('M j, Y') . ' — awaiting an updated quotation from Raflora.';
        }

        return 'Quotation' . ($quotation ? ' v' . $quotation->version : '') . ' issued — awaiting client review and acceptance'
            . ($validUntil ? ' (valid until ' . $validUntil->format('M j, Y') . ')' : '') . '.';
    }

    /**
     * Confirmed → Preparation & Reservation → Dispatch (verified payment statuses).
     *
     * @return array{0: string, 1: string}
     */
    private function operationsStage(Booking $booking, string $status): array
    {
        $reusable = $this->reusableTransactions($booking);
        $locked = $this->netLocked($reusable);
        $dispatched = $this->netDispatched($reusable);

        if ($dispatched > 0) {
            $outstanding = max(0.0, $locked - $dispatched);

            return ['dispatch', $outstanding > 0
                ? sprintf('Dispatch in progress — %s reserved unit(s) still to dispatch.', $this->formatQuantity($outstanding))
                : 'All reserved materials dispatched — ready for event execution.'];
        }

        $preparationStarted = $status === 'in_preparation'
            || in_array($booking->preparation_status, ['in_preparation', 'ready'], true)
            || $locked > 0
            || $booking->isInPreparationPeriod();

        if ($preparationStarted) {
            if ($locked > 0) {
                $detail = 'Reusable materials reserved — preparing for dispatch.';
            } elseif ($booking->hasReusableMaterials()) {
                $detail = 'Preparation period started — reusable materials are awaiting reservation.';
            } else {
                $detail = 'Preparation in progress — no reusable materials require reservation.';
            }

            $checklist = $booking->relationLoaded('staffChecklistItems')
                ? $booking->staffChecklistItems
                : $booking->staffChecklistItems()->get();
            if ($checklist->isNotEmpty()) {
                $detail .= sprintf(' Staff checklist: %d/%d complete.', $checklist->where('is_completed', true)->count(), $checklist->count());
            }

            return ['preparation_reservation', $detail];
        }

        return ['confirmed', $booking->preparation_start_date
            ? 'Booking confirmed — preparation starts on ' . $booking->preparation_start_date->format('M j, Y') . '.'
            : 'Booking confirmed — Raflora will schedule the preparation period.'];
    }

    /**
     * Material Return → Inventory Reconciliation → Completion (post-event statuses).
     *
     * @return array{0: string, 1: string}
     */
    private function postEventStage(Booking $booking, string $status): array
    {
        $returns = $booking->relationLoaded('returns')
            ? $booking->returns
            : $booking->returns()->with('returnItems')->get();
        $returnItems = $returns->flatMap(fn ($return) => $return->returnItems);

        $hasPendingResolution = $returnItems->contains(function ($item): bool {
            return $item->charge_decision === 'pending'
                && (in_array($item->condition, ['damaged', 'lost', 'mixed'], true)
                    || (float) $item->quantity_damaged > 0
                    || (float) $item->quantity_lost > 0);
        });

        if ($status === 'pending_resolution' || $hasPendingResolution) {
            return ['inventory_reconciliation', 'Damage or loss charges are awaiting Raflora resolution.'];
        }

        $returnCompleted = $returns->isNotEmpty() && $returns->every(fn ($return) => $return->status === 'Completed');
        if ($returnCompleted) {
            return ['completion', (float) $booking->remaining_balance > 0
                ? 'Return audit complete — awaiting final balance settlement.'
                : 'Return audit complete — awaiting booking completion.'];
        }

        $dispatchedByItem = $this->reusableTransactions($booking)
            ->filter(fn ($tx) => in_array($tx->transaction_type, ['dispatch', 'dispatch_correction'], true))
            ->groupBy('inventory_item_id')
            ->map(fn ($txs) => abs((float) $txs->sum('quantity_change')))
            ->filter(fn ($quantity) => $quantity > 0);

        if ($dispatchedByItem->isEmpty()) {
            // Zero-hardware event: Raflora closes an empty return audit (ReturnTrackingController::manage/update).
            return ['material_return', 'No reusable materials were dispatched — awaiting Raflora to close the return audit.'];
        }

        $latestReturn = $returns->sortByDesc('id')->first();
        if (!$latestReturn) {
            return ['material_return', 'Awaiting the return of dispatched reusable materials.'];
        }

        $accounted = 0.0;
        $allAccounted = true;
        foreach ($dispatchedByItem as $inventoryItemId => $dispatchedQuantity) {
            $itemAccounted = (float) $latestReturn->returnItems
                ->where('inventory_item_id', $inventoryItemId)
                ->sum(function ($item): float {
                    $quantity = (float) $item->accounted_quantity;
                    if ($quantity == 0.0 && in_array($item->condition, ['good', 'damaged', 'lost'], true)) {
                        $quantity = (float) $item->quantity_returned;
                    }

                    return $quantity;
                });

            $accounted += min($dispatchedQuantity, $itemAccounted);
            if ($itemAccounted < $dispatchedQuantity) {
                $allAccounted = false;
            }
        }

        if ($allAccounted) {
            return ['inventory_reconciliation', 'Returned materials recorded — awaiting condition assessment and inventory reconciliation.'];
        }

        return ['material_return', sprintf(
            '%s of %s dispatched unit(s) accounted for.',
            $this->formatQuantity($accounted),
            $this->formatQuantity((float) $dispatchedByItem->sum())
        )];
    }

    private function bookingItems(Booking $booking): Collection
    {
        return $booking->relationLoaded('bookingItems') ? $booking->bookingItems : $booking->bookingItems()->get();
    }

    private function reusableTransactions(Booking $booking): Collection
    {
        $transactions = $booking->relationLoaded('inventoryTransactions')
            ? $booking->inventoryTransactions
            : $booking->inventoryTransactions()->with('inventoryItem')->get();

        $reusableIds = $booking->reusableBookingItems()
            ->pluck('inventory_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $transactions->filter(fn ($tx) => $tx->inventoryItem && $reusableIds->contains((int) $tx->inventory_item_id))->values();
    }

    private function netLocked(Collection $transactions): float
    {
        $locked = abs((float) $transactions
            ->where('transaction_type', 'booking_lock')
            ->filter(fn ($tx) => (float) $tx->quantity_change < 0)
            ->sum('quantity_change'));
        $released = (float) $transactions
            ->where('transaction_type', 'booking_release')
            ->filter(fn ($tx) => (float) $tx->quantity_change > 0)
            ->sum('quantity_change');

        return max(0.0, $locked - $released);
    }

    private function netDispatched(Collection $transactions): float
    {
        return abs((float) $transactions
            ->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])
            ->sum('quantity_change'));
    }

    private function latestPaymentStatus(Booking $booking): ?string
    {
        $payment = $booking->relationLoaded('payments')
            ? $booking->payments->sortByDesc(fn ($p) => [$p->created_at?->timestamp ?? 0, $p->id])->first()
            : $booking->payments()->latest()->orderByDesc('id')->first();

        return $payment?->status;
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, '.', ','), '0'), '.');
    }

    /**
     * @return array<string, string>
     */
    private function linearStates(string $currentKey, bool $finished = false): array
    {
        $states = [];
        $passedCurrent = false;

        foreach (array_keys(self::STAGES) as $key) {
            if ($finished) {
                $states[$key] = self::STATE_COMPLETE;
                continue;
            }

            if ($key === $currentKey) {
                $states[$key] = self::STATE_CURRENT;
                $passedCurrent = true;
                continue;
            }

            $states[$key] = $passedCurrent ? self::STATE_UPCOMING : self::STATE_COMPLETE;
        }

        return $states;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function statesWith(array $keys, string $state, string $otherwise): array
    {
        $states = [];
        foreach (array_keys(self::STAGES) as $key) {
            $states[$key] = in_array($key, $keys, true) ? $state : $otherwise;
        }

        return $states;
    }

    /**
     * @param  array<string, string>  $states
     * @param  array<string, string>  $details
     */
    private function assemble(array $states, array $details, ?string $currentKey, array $meta = []): array
    {
        $stages = [];
        foreach (self::STAGES as $key => $definition) {
            $stages[$key] = [
                'key' => $key,
                'number' => self::stageNumber($key),
                'label' => $definition['label'],
                'phase' => $definition['phase'],
                'state' => $states[$key],
                'detail' => $details[$key] ?? null,
            ];
        }

        return array_merge([
            'stages' => $stages,
            'phases' => $this->phasesFor($stages),
            'current' => $currentKey,
            'current_label' => $currentKey ? self::stageLabel($currentKey) : null,
            'current_number' => $currentKey ? self::stageNumber($currentKey) : null,
            'current_phase' => $currentKey ? self::stagePhase($currentKey) : null,
            'current_detail' => $currentKey ? ($details[$currentKey] ?? null) : null,
            'total' => count(self::STAGES),
            'finished' => false,
            'terminal' => null,
            'terminal_label' => null,
            'terminal_detail' => null,
        ], $meta);
    }

    /**
     * @param  array<string, array>  $stages
     */
    private function phasesFor(array $stages): array
    {
        $phases = [];
        foreach (self::PHASES as $phaseKey => $phaseLabel) {
            $phaseStages = array_values(array_filter($stages, fn (array $stage) => $stage['phase'] === $phaseKey));
            $phaseStates = array_column($phaseStages, 'state');
            $completed = count(array_filter($phaseStates, fn (string $state) => $state === self::STATE_COMPLETE));

            $phases[$phaseKey] = [
                'key' => $phaseKey,
                'label' => $phaseLabel,
                'state' => match (true) {
                    in_array(self::STATE_CURRENT, $phaseStates, true) => self::STATE_CURRENT,
                    $completed === count($phaseStates) => self::STATE_COMPLETE,
                    in_array(self::STATE_STOPPED, $phaseStates, true) => self::STATE_STOPPED,
                    default => self::STATE_UPCOMING,
                },
                'stages' => array_column($phaseStages, 'key'),
                'completed' => $completed,
                'total' => count($phaseStates),
            ];
        }

        return $phases;
    }
}
