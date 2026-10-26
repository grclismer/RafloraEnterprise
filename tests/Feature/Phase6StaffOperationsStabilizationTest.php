<?php

namespace Tests\Feature;

use App\Models\AssetReturn;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stabilization Plan — Phase 6: Preparation → Material Movements → Event Execution → Returns.
 */
class Phase6StaffOperationsStabilizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $otherStaff;
    private Booking $booking;
    private InventoryItem $arch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'p6-admin@example.com');
        $this->staff = $this->user('staff', 'p6-staff@example.com');
        $this->otherStaff = $this->user('staff', 'p6-other-staff@example.com');
        $client = Client::create(['full_name' => 'Phase Six Client', 'email' => 'p6-client@example.com']);

        $this->arch = InventoryItem::create([
            'name' => 'Gold Arch Frame',
            'category' => 'props',
            'is_perishable' => false,
            'current_stock' => 8,   // 10 owned, 2 dispatched to this event
            'unit_cost' => 500,
            'unit' => 'piece',
            'min_stock' => 0,
        ]);

        $this->booking = Booking::create([
            'client_id' => $client->id,
            'staff_id' => $this->staff->id,
            'event_type' => 'wedding',
            'event_date' => now()->subDay()->toDateString(),
            'venue' => 'Phase Six Hall',
            'status' => 'pending_return',
            'confirmed_at' => now()->subDays(10),
        ]);
        BookingItem::create([
            'booking_id' => $this->booking->id,
            'inventory_item_id' => $this->arch->id,
            'item_name' => $this->arch->name,
            'quantity' => 2,
            'quoted_unit_price' => 500,
            'confirmed_at' => now()->subDays(12),
        ]);
        InventoryTransaction::create([
            'inventory_item_id' => $this->arch->id,
            'booking_id' => $this->booking->id,
            'quantity_change' => -2,
            'transaction_type' => 'dispatch',
            'reason' => 'Dispatch for event',
            'performed_by' => $this->admin->id,
        ]);
    }

    public function test_staff_recorded_return_is_credited_to_stock_once_when_admin_reconciles(): void
    {
        // The return record opens when the event ends; Staff then opens the event and records the return.
        app(\App\Services\ReturnRecordService::class)->openFor($this->booking);
        $this->actingAs($this->staff)->get(route('staff.events.show', $this->booking))->assertOk();
        $return = $this->booking->returns()->firstOrFail();
        $returnItem = $return->returnItems()->firstOrFail();

        $this->actingAs($this->staff)->put(route('staff.events.return.update', $this->booking), [
            'items' => [$returnItem->id => ['quantity_returned' => 2, 'notes' => 'Both frames back']],
        ])->assertSessionHas('success', 'Material return recorded for Admin review.');

        // Staff records are for review: stock is not credited yet.
        $this->assertSame(8.0, (float) $this->arch->fresh()->current_stock);
        $this->assertSame(2.0, (float) $returnItem->fresh()->quantity_good);

        // Admin confirms the same counts — the good items must now return to stock.
        $this->actingAs($this->admin)->put(route('admin.return-tracking.update', $return), [
            'items' => [$returnItem->id => ['quantity_good' => 2, 'quantity_damaged' => 0, 'quantity_lost' => 0, 'charge_decision' => 'no_charge']],
        ])->assertRedirect(route('admin.return-tracking'));

        $this->assertSame(10.0, (float) $this->arch->fresh()->current_stock);
        $this->assertSame(1, InventoryTransaction::where('booking_id', $this->booking->id)->where('transaction_type', 'return')->count());
        $this->assertSame('Completed', $return->fresh()->status);
        $this->assertSame('completed', $this->booking->fresh()->status);
    }

    public function test_repeated_admin_reconciliation_does_not_double_credit_stock(): void
    {
        app(\App\Services\ReturnRecordService::class)->openFor($this->booking);
        $this->actingAs($this->staff)->get(route('staff.events.show', $this->booking));
        $return = $this->booking->returns()->firstOrFail();
        $returnItem = $return->returnItems()->firstOrFail();
        $payload = ['items' => [$returnItem->id => ['quantity_good' => 1, 'quantity_damaged' => 1, 'quantity_lost' => 0, 'charge_decision' => 'pending']]];

        $this->actingAs($this->admin)->put(route('admin.return-tracking.update', $return), $payload);
        $this->actingAs($this->admin)->put(route('admin.return-tracking.update', $return), $payload);

        // Only the good unit is usable stock; the damaged unit is not counted.
        $this->assertSame(9.0, (float) $this->arch->fresh()->current_stock);
        $this->assertSame('pending_resolution', $this->booking->fresh()->status);
    }

    public function test_staff_cannot_reopen_a_completed_return_audit(): void
    {
        app(\App\Services\ReturnRecordService::class)->openFor($this->booking);
        $this->actingAs($this->staff)->get(route('staff.events.show', $this->booking));
        $return = $this->booking->returns()->firstOrFail();
        $returnItem = $return->returnItems()->firstOrFail();
        $this->actingAs($this->admin)->put(route('admin.return-tracking.update', $return), [
            'items' => [$returnItem->id => ['quantity_good' => 2, 'quantity_damaged' => 0, 'quantity_lost' => 0, 'charge_decision' => 'no_charge']],
        ]);
        $this->assertSame('Completed', $return->fresh()->status);

        $this->actingAs($this->staff)->put(route('staff.events.return.update', $this->booking), [
            'items' => [$returnItem->id => ['quantity_returned' => 0]],
        ])->assertSessionHas('error', 'This return audit has been completed by Raflora and can no longer be changed.');
        $this->actingAs($this->staff)->put(route('staff.events.return.condition', $this->booking), [
            'items' => [$returnItem->id => ['condition' => 'lost']],
        ])->assertSessionHas('error', 'This return audit has been completed by Raflora and can no longer be changed.');

        $this->assertSame(2.0, (float) $returnItem->fresh()->quantity_good);
        $this->assertSame('Completed', $return->fresh()->status);
        $this->assertSame(10.0, (float) $this->arch->fresh()->current_stock);
    }

    public function test_staff_actions_are_limited_to_assigned_events_and_staff_functions(): void
    {
        $this->actingAs($this->otherStaff)->get(route('staff.events.show', $this->booking))->assertNotFound();
        $this->actingAs($this->otherStaff)->put(route('staff.events.return.update', $this->booking), ['items' => [1 => ['quantity_returned' => 1]]])->assertNotFound();
        $this->actingAs($this->otherStaff)->post(route('staff.events.dispatch', $this->booking), [
            'items' => [['inventory_item_id' => $this->arch->id, 'quantity' => 1]],
            'reason' => 'Not my event',
        ])->assertNotFound();

        $payment = Payment::create([
            'booking_id' => $this->booking->id,
            'amount' => 100,
            'amount_paid' => 0,
            'payment_type' => 'gcash',
            'reference_number' => 'P6-PENDING',
            'status' => 'pending',
        ]);
        $return = AssetReturn::create(['booking_id' => $this->booking->id, 'status' => 'Pending', 'total_damage_charge' => 0]);

        $this->actingAs($this->staff)->post(route('admin.payments.verify', $payment))->assertForbidden();
        $this->actingAs($this->staff)->put(route('admin.return-tracking.update', $return), [])->assertForbidden();
        $this->actingAs($this->staff)->post(route('admin.bookings.dispatch', $this->booking), [])->assertForbidden();
        $this->actingAs($this->staff)->post(route('admin.bookings.assign-staff', $this->booking), ['staff_id' => $this->staff->id])->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(8.0, (float) $this->arch->fresh()->current_stock);
    }

    public function test_staff_physical_count_is_submitted_for_review_without_changing_stock(): void
    {
        $this->actingAs($this->staff)->put(route('staff.events.inventory.update', [$this->booking, $this->arch]), ['observed_stock' => 7])
            ->assertSessionHas('success');

        $this->assertSame(8.0, (float) $this->arch->fresh()->current_stock);
        $this->assertDatabaseHas('admin_alerts', ['type' => 'physical_count_variance', 'booking_id' => $this->booking->id, 'inventory_item_id' => $this->arch->id]);
    }

    public function test_staff_dispatch_ui_uses_booking_classification_and_allows_dispatch_before_planned_prep_date(): void
    {
        $this->arch->update(['is_perishable' => true]);
        $booking = Booking::create([
            'client_id' => $this->booking->client_id,
            'staff_id' => $this->staff->id,
            'event_type' => 'wedding',
            'event_date' => now()->addDays(30)->toDateString(),
            'preparation_start_date' => now()->subDay()->toDateString(),
            'venue' => 'Future Event Hall',
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        BookingItem::create([
            'booking_id' => $booking->id,
            'inventory_item_id' => $this->arch->id,
            'item_name' => $this->arch->name,
            'quantity' => 2,
            'confirmed_at' => now(),
            'fresh_procurement_override' => false,
        ]);
        Payment::create([
            'booking_id' => $booking->id,
            'amount' => 100,
            'amount_paid' => 100,
            'payment_type' => 'gcash',
            'reference_number' => 'P6-VERIFIED',
            'status' => 'downpayment_received',
            'verified_at' => now(),
        ]);
        InventoryTransaction::create([
            'inventory_item_id' => $this->arch->id,
            'booking_id' => $booking->id,
            'quantity_change' => -2,
            'transaction_type' => 'booking_lock',
            'reason' => 'Preparation reservation',
            'performed_by' => $this->admin->id,
        ]);

        $workspace = app(\App\Services\StaffWorkspaceService::class);
        $this->assertTrue($workspace->canDispatch($booking));
        $this->assertFalse($workspace->materialLines($booking)->first()['perishable']);
        $this->actingAs($this->staff)->get(route('staff.events.show', $booking))
            ->assertOk()
            ->assertSee('name="items[0][quantity]"', false);

        $booking->update(['preparation_start_date' => now()->addDay()->toDateString()]);
        $this->assertTrue($workspace->canDispatch($booking->fresh()));
        $this->actingAs($this->staff)->get(route('staff.events.show', $booking))
            ->assertOk()
            ->assertSee('name="items[0][quantity]"', false);

        $unpaidBooking = Booking::create([
            'client_id' => $this->booking->client_id,
            'staff_id' => $this->staff->id,
            'event_type' => 'wedding',
            'event_date' => now()->addDays(30)->toDateString(),
            'preparation_start_date' => now()->addDay()->toDateString(),
            'venue' => 'Unpaid Event Hall',
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        BookingItem::create([
            'booking_id' => $unpaidBooking->id,
            'inventory_item_id' => $this->arch->id,
            'item_name' => $this->arch->name,
            'quantity' => 2,
            'confirmed_at' => now(),
            'fresh_procurement_override' => false,
        ]);
        InventoryTransaction::create([
            'inventory_item_id' => $this->arch->id,
            'booking_id' => $unpaidBooking->id,
            'quantity_change' => -2,
            'transaction_type' => 'booking_lock',
            'reason' => 'Preparation reservation',
            'performed_by' => $this->admin->id,
        ]);
        $this->assertFalse($workspace->canDispatch($unpaidBooking));
        $this->actingAs($this->staff)->get(route('staff.events.show', $unpaidBooking))
            ->assertOk()
            ->assertSee('Dispatch is locked until a down payment or full payment is verified.')
            ->assertDontSee('name="items[0][quantity]"', false);
    }

    private function user(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role) . ' Phase Six',
            'email' => $email,
            'password' => bcrypt('password123'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
