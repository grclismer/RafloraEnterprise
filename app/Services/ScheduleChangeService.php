<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\BookingScheduleRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Client "Schedule" change requests. The client asks for a new date/time (client Request
 * Changes); Admin checks Raflora's side (other bookings and material stock on that date),
 * agrees it with the client in the booking chat, then accepts or declines it. Accepting moves
 * the booking's date/time; the quotation is still sent with "Approve & Send Official Quote".
 */
class ScheduleChangeService
{
    /** Booking statuses in which the client can request changes and Admin can decide them: any time before the event starts. */
    public const OPEN_STATUSES = [
        'pending', 'quotation_sent', 'change_requested', 'approved', 'admin_approved', 'payment_pending', 'payment_submitted',
        'downpayment_received', 'confirmed', 'fully_paid', 'in_preparation',
    ];

    /** Statuses in which the quotation is still being settled, so Admin re-sends it after a change. */
    public const QUOTATION_STATUSES = ['pending', 'quotation_sent', 'change_requested'];

    /** Bookings that no longer occupy their event date. */
    private const INACTIVE_STATUSES = ['cancelled', 'declined', 'rejected', 'completed'];

    /** Bookings whose materials are reserved for their date (as in Admin inventory availability). */
    private const RESERVING_STATUSES = ['downpayment_received', 'confirmed', 'in_preparation', 'event_in_progress'];

    /**
     * Record a client's request; any earlier pending request is replaced by the new one.
     */
    public function record(Booking $booking, array $validated, ?BookingMessage $message): BookingScheduleRequest
    {
        $booking->scheduleRequests()
            ->where('status', BookingScheduleRequest::STATUS_PENDING)
            ->update(['status' => BookingScheduleRequest::STATUS_SUPERSEDED]);

        return $booking->scheduleRequests()->create([
            'booking_message_id' => $message?->id,
            'requested_date' => $validated['requested_date'],
            'requested_start_time' => $validated['requested_start_time'] ?? null,
            'requested_end_time' => $validated['requested_end_time'] ?? null,
            'previous_date' => $booking->event_date?->toDateString(),
            'previous_start_time' => $booking->event_time ? substr((string) $booking->event_time, 0, 5) : null,
            'previous_end_time' => $booking->end_time ? substr((string) $booking->end_time, 0, 5) : null,
            'reason' => $validated['change_reason'] ?? null,
            'status' => BookingScheduleRequest::STATUS_PENDING,
        ]);
    }

    public function pendingFor(Booking $booking): ?BookingScheduleRequest
    {
        return $booking->scheduleRequests()
            ->where('status', BookingScheduleRequest::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * Raflora's side of the new date: other bookings that day and whether this booking's
     * linked reusable materials are still in stock after bookings already reserved for it.
     *
     * @return array{other_bookings: \Illuminate\Support\Collection, materials: list<array{name: string, needed: float, available: float, ok: bool}>, short: int}
     */
    public function availability(Booking $booking, Carbon $date): array
    {
        $sameDay = Booking::query()
            ->whereDate('event_date', $date->toDateString())
            ->where('id', '!=', $booking->id)
            ->whereNotIn('status', self::INACTIVE_STATUSES)
            ->with('inventoryItems')
            ->orderBy('event_time')
            ->get();

        $reserving = $sameDay->whereIn('status', self::RESERVING_STATUSES);

        $materials = $booking->bookingItems()
            ->with('inventoryItem')
            ->whereNotNull('inventory_item_id')
            ->where('quantity', '>', 0)
            ->get()
            ->filter(fn ($item) => $item->inventoryItem && !$item->inventoryItem->is_perishable)
            ->groupBy('inventory_item_id')
            ->map(function ($items) use ($reserving) {
                $inventoryItem = $items->first()->inventoryItem;
                $reserved = $reserving->sum(function (Booking $other) use ($inventoryItem) {
                    $pivot = $other->inventoryItems->firstWhere('id', $inventoryItem->id);

                    return $pivot ? (float) $pivot->pivot->quantity : 0.0;
                });
                $needed = (float) $items->sum('quantity');
                $available = max(0.0, (float) $inventoryItem->current_stock - $reserved);

                return ['name' => $inventoryItem->name, 'needed' => $needed, 'available' => $available, 'ok' => $available >= $needed];
            })
            ->values()
            ->all();

        return [
            'other_bookings' => $sameDay,
            'materials' => $materials,
            'short' => count(array_filter($materials, fn (array $row) => !$row['ok'])),
        ];
    }

    /**
     * Move the booking to the requested date/time. Returns the accepted request.
     *
     * @throws RuntimeException
     */
    public function accept(Booking $booking, BookingScheduleRequest $request, int $adminId, ?string $note = null): BookingScheduleRequest
    {
        return DB::transaction(function () use ($booking, $request, $adminId, $note): BookingScheduleRequest {
            [$locked, $lockedBooking] = $this->lockForDecision($booking, $request);

            if ($locked->requested_date->copy()->startOfDay()->isPast() && !$locked->requested_date->isToday()) {
                throw new RuntimeException('The requested date has already passed. Ask the client for a new date.');
            }

            $lockedBooking->event_date = $locked->requested_date->toDateString();
            if ($locked->requested_start_time) {
                $lockedBooking->event_time = substr((string) $locked->requested_start_time, 0, 5);
            }
            if ($locked->requested_end_time) {
                $lockedBooking->end_time = substr((string) $locked->requested_end_time, 0, 5);
            }
            // A preparation start after the new event date would no longer make sense.
            if ($lockedBooking->preparation_start_date && !$lockedBooking->isPreparationDateAllowed($lockedBooking->preparation_start_date, $locked->requested_date)) {
                $lockedBooking->preparation_start_date = null;
            }
            $lockedBooking->save();

            $this->decide($locked, BookingScheduleRequest::STATUS_ACCEPTED, $adminId, $note);

            $this->postMessage($lockedBooking, $adminId, 'Schedule change accepted: your event is now on '
                . $locked->requestedLabel() . ' (was ' . $locked->previousLabel() . ').'
                . (in_array($lockedBooking->status, self::QUOTATION_STATUSES, true) ? ' Raflora will send the updated quotation.' : '')
                . ($note ? "\nNote: " . $note : ''));

            AuditLog::record($adminId, 'schedule_change_accepted', 'Schedule change for booking #' . $lockedBooking->id
                . ' accepted: ' . $locked->previousLabel() . ' → ' . $locked->requestedLabel() . '.', 'booking', [
                    'booking_id' => $lockedBooking->id,
                    'schedule_request_id' => $locked->id,
                ]);

            $booking->setRawAttributes($lockedBooking->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Keep the booking's schedule and tell the client why.
     *
     * @throws RuntimeException
     */
    public function decline(Booking $booking, BookingScheduleRequest $request, int $adminId, ?string $note = null): BookingScheduleRequest
    {
        return DB::transaction(function () use ($booking, $request, $adminId, $note): BookingScheduleRequest {
            [$locked, $lockedBooking] = $this->lockForDecision($booking, $request);

            $this->decide($locked, BookingScheduleRequest::STATUS_DECLINED, $adminId, $note);

            $this->postMessage($lockedBooking, $adminId, 'Schedule change declined: your event stays on '
                . $locked->previousLabel() . '.' . ($note ? "\nReason: " . $note : ''));

            AuditLog::record($adminId, 'schedule_change_declined', 'Schedule change for booking #' . $lockedBooking->id
                . ' to ' . $locked->requestedLabel() . ' declined.', 'booking', [
                    'booking_id' => $lockedBooking->id,
                    'schedule_request_id' => $locked->id,
                ]);

            return $locked;
        });
    }

    /**
     * @return array{0: BookingScheduleRequest, 1: Booking}
     */
    private function lockForDecision(Booking $booking, BookingScheduleRequest $request): array
    {
        $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $locked = BookingScheduleRequest::whereKey($request->id)->lockForUpdate()->first();

        if (!$locked || (int) $locked->booking_id !== (int) $lockedBooking->id) {
            throw new RuntimeException('This schedule request does not belong to this booking.');
        }
        if (!$locked->isPending()) {
            throw new RuntimeException('This schedule request has already been ' . $locked->status . '.');
        }
        if (!in_array($lockedBooking->status, self::OPEN_STATUSES, true)) {
            throw new RuntimeException('Schedule requests can only be decided before the event starts.');
        }

        return [$locked, $lockedBooking];
    }

    private function decide(BookingScheduleRequest $request, string $status, int $adminId, ?string $note): void
    {
        $request->forceFill([
            'status' => $status,
            'decided_by' => $adminId,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();
    }

    private function postMessage(Booking $booking, int $adminId, string $text): void
    {
        BookingMessage::create([
            'booking_id' => $booking->id,
            'sender_type' => 'admin',
            'sender_id' => $adminId,
            'message' => $text,
            'visibility' => 'client_admin',
            'related_quotation_version' => $booking->activeQuotation()->first()?->version,
        ]);
    }
}
