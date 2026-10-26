<?php

namespace App\Services;

use App\Mail\BookingStatusChanged;
use App\Models\Booking;
use App\Models\ClientNotification;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Keeps registered clients informed of every booking status change, whichever path (Admin, Staff,
 * Client, system) made it: one portal notification and one email per change.
 *
 * Paths that already write a tailored notification or email keep doing so; the automatic one
 * steps aside for them (see DEDUPE_WINDOW_SECONDS) so the client never gets the same news twice.
 */
class ClientStatusNotifier
{
    /** Notifications/emails for the same booking within this window describe the same change. */
    public const DEDUPE_WINDOW_SECONDS = 10;

    /** Portal notification type used for automatic status updates. */
    public const TYPE = 'status_update';

    /**
     * Client-facing title and message for a status.
     *
     * @return array{0: string, 1: string}
     */
    public static function content(string $status): array
    {
        return match ($status) {
            'pending' => ['Booking request under review', 'Your booking request is being reviewed by Raflora Enterprises. We will send your quotation once it is ready.'],
            'quotation_sent' => ['Your quotation is ready', 'Your quotation is ready for review. Please open your booking to accept it or request changes.'],
            'change_requested' => ['Change request received', 'We received your change request and will review it shortly.'],
            'approved' => ['Quotation accepted', 'Thank you for accepting the quotation. Raflora Enterprises will complete the final approval shortly.'],
            'admin_approved' => ['Booking approved', 'Your booking has been approved. Please submit your payment to secure your event date.'],
            'payment_submitted' => ['Payment submitted', 'We received your payment reference and will verify it shortly.'],
            'payment_pending' => ['Booking accepted — payment is pending', 'Your booking has been accepted and is now awaiting payment verification.'],
            'downpayment_received' => ['Downpayment received', 'Your downpayment has been verified and your booking is confirmed.'],
            'fully_paid' => ['Payment complete', 'Your payment has been verified and your booking is fully paid.'],
            'confirmed' => ['Booking confirmed', 'Your booking is confirmed. Our team is preparing for your event.'],
            'in_preparation' => ['Event preparation started', 'Our team has started preparing for your event.'],
            'event_in_progress' => ['Your event is in progress', 'Our team is now setting up and running your event.'],
            'event_completed' => ['Event completed', 'Your event has been completed. Thank you for choosing Raflora Enterprises!'],
            'pending_return' => ['Awaiting item return', 'Your event is done and the rented items are now being returned and checked.'],
            'pending_resolution' => ['Action needed on returned items', 'Our return check found items that need resolution. Please open your booking for the details and any balance due.'],
            'completed' => ['Booking completed', 'Your booking has been completed. Thank you for choosing Raflora Enterprises!'],
            'cancellation_requested' => ['Cancellation request received', 'We received your cancellation request and will review it shortly.'],
            'cancelled' => ['Booking cancelled', 'Your booking has been cancelled.'],
            'declined' => ['Your booking request was declined', 'The booking request was declined. Please review the details for next steps.'],
            'expired' => ['Quotation expired', 'Your quotation has expired. Please contact us if you would like a new one.'],
            default => ['Booking status updated', 'Your booking status is now ' . (new Booking())->forceFill(['status' => $status])->client_status_label . '.'],
        };
    }

    /**
     * Notify the client of a status change already committed on $booking.
     */
    public static function statusChanged(Booking $booking, CarbonInterface $changedAt): void
    {
        $status = (string) $booking->status;
        if ($status === '' || is_null($booking->client_id)) {
            return;
        }

        try {
            self::createPortalNotification($booking, $status, $changedAt);
        } catch (\Throwable $e) {
            Log::error('Client status notification failed: ' . $e->getMessage(), ['booking_id' => $booking->id]);
        }

        $payment = in_array($status, ['downpayment_received', 'fully_paid', 'confirmed'], true)
            ? $booking->payments()->latest('id')->first()
            : null;

        self::email($booking, $status, $payment);
    }

    /**
     * Send the status email unless the same status was already emailed for this booking just now.
     */
    public static function email(Booking $booking, string $status, ?Payment $payment = null): void
    {
        $email = $booking->client?->email;
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // Cache::add only succeeds for the first sender inside the window.
        if (! Cache::add('client-status-email:' . $booking->id . ':' . $status, true, self::DEDUPE_WINDOW_SECONDS)) {
            return;
        }

        try {
            Mail::to($email)->send(new BookingStatusChanged($booking, $payment, $status));
        } catch (\Throwable $e) {
            // The booking update stands even when mail delivery fails.
            Log::error('Booking status email failed: ' . $e->getMessage(), ['booking_id' => $booking->id, 'status' => $status]);
        }
    }

    /**
     * A tailored notification about the booking replaces the automatic one written moments ago.
     */
    public static function supersede(ClientNotification $notification): void
    {
        if ($notification->type === self::TYPE || ! $notification->booking_id) {
            return;
        }

        ClientNotification::where('user_id', $notification->user_id)
            ->where('booking_id', $notification->booking_id)
            ->where('type', self::TYPE)
            ->where('created_at', '>=', now()->subSeconds(self::DEDUPE_WINDOW_SECONDS))
            ->delete();
    }

    public static function resolveUser(Booking $booking): ?User
    {
        $client = $booking->client;
        if (! $client) {
            return null;
        }

        $user = filter_var($client->email, FILTER_VALIDATE_EMAIL)
            ? User::where('email', $client->email)->first()
            : null;

        if (! $user && ! empty($client->user_id)) {
            $user = User::find($client->user_id);
        }

        return $user;
    }

    private static function createPortalNotification(Booking $booking, string $status, CarbonInterface $changedAt): void
    {
        $user = self::resolveUser($booking);
        if (! $user) {
            return;
        }

        // A tailored notification for this change already exists; don't repeat it.
        $alreadyNotified = ClientNotification::where('user_id', $user->id)
            ->where('booking_id', $booking->id)
            ->where('type', '!=', self::TYPE)
            ->where('created_at', '>=', $changedAt->copy()->subSeconds(self::DEDUPE_WINDOW_SECONDS))
            ->exists();

        if ($alreadyNotified) {
            return;
        }

        [$title, $message] = self::content($status);

        ClientNotification::create([
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'type' => self::TYPE,
            'title' => $title,
            'message' => $message,
            'is_read' => false,
        ]);
    }
}
