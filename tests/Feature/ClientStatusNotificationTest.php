<?php

namespace Tests\Feature;

use App\Mail\BookingStatusChanged;
use App\Models\Booking;
use App\Models\Client;
use App\Models\ClientNotification;
use App\Models\User;
use App\Services\ClientStatusNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $clientUser;
    protected Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientUser = User::factory()->create(['role' => 'client', 'email' => 'status_client@example.com']);

        $client = Client::create([
            'user_id' => $this->clientUser->id,
            'full_name' => 'Status Client',
            'email' => $this->clientUser->email,
            'phone' => '09170000123',
            'address' => '1 Status Lane',
        ]);

        $this->booking = Booking::create([
            'client_id' => $client->id,
            'status' => 'pending',
            'event_type' => 'Wedding',
            'event_date' => now()->addDays(20)->toDateString(),
            'venue' => 'Manila Grand Hotel',
            'guest_count' => 100,
        ]);
    }

    public function test_every_status_change_notifies_client_in_portal_and_by_email(): void
    {
        Mail::fake();

        $statuses = ['quotation_sent', 'approved', 'admin_approved', 'payment_submitted', 'downpayment_received',
            'event_in_progress', 'event_completed', 'pending_return', 'completed'];

        foreach ($statuses as $status) {
            $this->booking->update(['status' => $status]);

            [$title] = ClientStatusNotifier::content($status);
            $this->assertSame(1, ClientNotification::where('user_id', $this->clientUser->id)
                ->where('booking_id', $this->booking->id)->where('title', $title)->count(), $status);
            Mail::assertSent(BookingStatusChanged::class, fn ($mail) => $mail->status === $status
                && $mail->hasTo('status_client@example.com'));
        }

        Mail::assertSent(BookingStatusChanged::class, count($statuses));
    }

    public function test_tailored_notification_replaces_automatic_one_for_same_change(): void
    {
        Mail::fake();

        $this->booking->update(['status' => 'downpayment_received']);
        ClientNotification::create([
            'user_id' => $this->clientUser->id,
            'booking_id' => $this->booking->id,
            'type' => 'payment_update',
            'title' => 'Payment Verified',
            'message' => 'Your payment has been successfully verified.',
            'is_read' => false,
        ]);

        $this->assertSame(['Payment Verified'], ClientNotification::where('booking_id', $this->booking->id)->pluck('title')->all());

        // A path that also emails the same status explicitly does not send it twice.
        ClientStatusNotifier::email($this->booking, 'downpayment_received');
        Mail::assertSent(BookingStatusChanged::class, 1);
    }

    public function test_status_email_renders_for_every_status(): void
    {
        foreach (['quotation_sent', 'admin_approved', 'pending_resolution', 'completed', 'cancelled', 'some_new_status'] as $status) {
            $html = (new BookingStatusChanged($this->booking, null, $status))->render();
            $this->assertStringContainsString('Status Client', $html);
            $this->assertStringContainsString(e(ClientStatusNotifier::content($status)[1]), $html);
        }
    }
}
