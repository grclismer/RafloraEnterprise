<?php

namespace Tests\Feature;

use App\Models\AdminAlert;
use App\Models\AssetReturn;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Payment;
use App\Models\ReturnItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2b8EventExecutionAndReturnAccountabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected User $clientUser;
    protected Client $clientRecord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin User']);
        $this->staff = User::factory()->create(['role' => 'staff', 'name' => 'Staff User']);
        $this->clientUser = User::factory()->create(['role' => 'client', 'name' => 'Client User']);

        $this->clientRecord = Client::create([
            'email' => $this->clientUser->email,
            'full_name' => $this->clientUser->name,
            'phone' => '09171234567',
            'address' => '123 Test Street, Manila',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. Dispatch & Completion Prerequisites
    |--------------------------------------------------------------------------
    */

    public function test_fresh_flower_only_booking_can_complete_without_reusable_dispatch(): void
    {
        $flower = InventoryItem::create([
            'name' => 'Red Roses',
            'category' => 'fresh_flower',
            'is_perishable' => true,
            'current_stock' => 100,
            'unit_cost' => 50,
            'min_stock' => 10,
            'unit' => 'stem',
        ]);

        $booking = Booking::create([
            'client_id' => $this->clientRecord->id,
            'event_type' => 'birthday',
            'event_date' => now()->addDays(3)->toDateString(),
            'venue' => 'Garden Pavilion',
            'status' => 'event_in_progress',
            'confirmed_at' => now(),
            'total_quoted' => 2000,
            'remaining_balance' => 0,
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'inventory_item_id' => $flower->id,
            'item_name' => $flower->name,
            'quantity' => 20,
            'quoted_unit_price' => 50,
            'confirmed_at' => now(),
            'procurement_status' => 'confirmed',
        ]);

        // Attempt completion with action=mark_event_completed
        $response = $this->actingAs($this->admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $booking->event_date->toDateString(),
            'venue' => $booking->venue,
            'status' => $booking->status,
            'action' => 'mark_event_completed',
        ]);

        $response->assertRedirect();
        $this->assertContains($booking->fresh()->status, ['event_completed', 'pending_return']);
    }

    public function test_service_only_booking_can_complete_without_physical_dispatch(): void
    {
        $booking = Booking::create([
            'client_id' => $this->clientRecord->id,
            'event_type' => 'consultation',
            'event_date' => now()->addDays(2)->toDateString(),
            'venue' => 'Virtual / Office',
            'status' => 'event_in_progress',
            'confirmed_at' => now(),
            'total_quoted' => 1500,
            'remaining_balance' => 0,
        ]);

        // No inventory items or booking items attached
        $response = $this->actingAs($this->admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $booking->event_date->toDateString(),
            'venue' => $booking->venue,
            'status' => $booking->status,
            'action' => 'mark_event_completed',
        ]);

        $response->assertRedirect();
        $this->assertContains($booking->fresh()->status, ['event_completed', 'pending_return']);
    }

    public function test_mixed_booking_requires_dispatch_of_reusable_materials(): void
    {
        $flower = InventoryItem::create([
            'name' => 'White Lilies',
            'category' => 'fresh_flower',
            'is_perishable' => true,
            'current_stock' => 50,
            'unit_cost' => 80,
            'min_stock' => 5,
            'unit' => 'stem',
        ]);

        $arch = InventoryItem::create([
            'name' => 'Gold Arch',
            'category' => 'hardware',
            'is_perishable' => false,
            'current_stock' => 5,
            'unit_cost' => 1500,
            'min_stock' => 1,
            'unit' => 'piece',
        ]);

        $booking = Booking::create([
            'client_id' => $this->clientRecord->id,
            'event_type' => 'wedding',
            'event_date' => now()->addDays(4)->toDateString(),
            'venue' => 'Grand Ballroom',
            'status' => 'event_in_progress',
            'confirmed_at' => now(),
            'total_quoted' => 5000,
            'remaining_balance' => 0,
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'inventory_item_id' => $flower->id,
            'item_name' => $flower->name,
            'quantity' => 10,
            'confirmed_at' => now(),
            'procurement_status' => 'confirmed',
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'inventory_item_id' => $arch->id,
            'item_name' => $arch->name,
            'quantity' => 1,
            'confirmed_at' => now(),
            'procurement_status' => 'confirmed',
        ]);

        // Attempt completion WITHOUT dispatch of the Gold Arch
        $response = $this->actingAs($this->admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $booking->event_date->toDateString(),
            'venue' => $booking->venue,
            'status' => $booking->status,
            'action' => 'mark_event_completed',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Cannot mark event completed: no materials have been dispatched.');
        $this->assertSame('event_in_progress', $booking->fresh()->status);

        // Now dispatch the Gold Arch
        InventoryTransaction::create([
            'inventory_item_id' => $arch->id,
            'booking_id' => $booking->id,
            'quantity_change' => -1,
            'transaction_type' => 'dispatch',
            'performed_by' => $this->admin->id,
            'reason' => 'Dispatched Gold Arch',
        ]);

        // Completion now succeeds
        $response2 = $this->actingAs($this->admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $booking->event_date->toDateString(),
            'venue' => $booking->venue,
            'status' => $booking->status,
            'action' => 'mark_event_completed',
        ]);

        $response2->assertRedirect();
        $this->assertContains($booking->fresh()->status, ['event_completed', 'pending_return']);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Zero-Hardware Return Audit
    |--------------------------------------------------------------------------
    */

    public function test_zero_hardware_return_audit_can_be_closed_successfully(): void
    {
        $booking = Booking::create([
            'client_id' => $this->clientRecord->id,
            'event_type' => 'fresh_flower_special',
            'event_date' => now()->subDay()->toDateString(),
            'venue' => 'Garden',
            'status' => 'event_completed',
            'confirmed_at' => now()->subDays(5),
            'total_quoted' => 1000,
            'remaining_balance' => 0,
        ]);

        Payment::create([
            'booking_id' => $booking->id,
            'amount' => 1000,
            'payment_option' => 'full_payment',
            'amount_paid' => 1000,
            'remaining_balance' => 0,
            'status' => 'fully_paid',
            'payment_type' => 'gcash',
            'reference_number' => 'REF-ZH-1',
            'verified_at' => now(),
            'verified_by' => $this->admin->id,
        ]);

        // Admin starts the zero-hardware audit (viewing no longer creates it)
        $this->actingAs($this->admin)->post(route('admin.return-tracking.start', $booking));
        $manageResponse = $this->actingAs($this->admin)->get(route('admin.return-tracking.manage', $booking));
        $manageResponse->assertOk();

        $return = AssetReturn::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('Pending', $return->status);
        $this->assertCount(0, $return->returnItems);

        // Submit zero-hardware closure
        $response = $this->actingAs($this->admin)->put(route('admin.return-tracking.update', $return), [
            'notes' => 'Zero-hardware closure: Fresh flowers only, no rental equipment to return.',
        ]);

        $response->assertRedirect(route('admin.return-tracking'));
        $response->assertSessionHas('success', 'Zero-hardware return audit completed successfully.');

        $this->assertSame('Completed', $return->fresh()->status);
        $this->assertSame('completed', $booking->fresh()->status);
    }

    public function test_zero_hardware_closure_is_blocked_if_hardware_was_dispatched(): void
    {
        $hardware = InventoryItem::create([
            'name' => 'Chandelier',
            'category' => 'hardware',
            'is_perishable' => false,
            'current_stock' => 10,
            'unit_cost' => 3000,
            'min_stock' => 1,
            'unit' => 'piece',
        ]);

        $booking = Booking::create([
            'client_id' => $this->clientRecord->id,
            'event_type' => 'gala',
            'event_date' => now()->subDay()->toDateString(),
            'venue' => 'Manila Hotel',
            'status' => 'pending_return',
            'confirmed_at' => now()->subDays(5),
            'total_quoted' => 3000,
            'remaining_balance' => 0,
        ]);

        // Dispatched 2 chandeliers
        InventoryTransaction::create([
            'inventory_item_id' => $hardware->id,
            'booking_id' => $booking->id,
            'quantity_change' => -2,
            'transaction_type' => 'dispatch',
            'performed_by' => $this->admin->id,
            'reason' => 'Dispatch chandeliers',
        ]);

        $return = AssetReturn::create([
            'booking_id' => $booking->id,
            'status' => 'Pending',
            'total_damage_charge' => 0,
        ]);

        ReturnItem::create([
            'return_id' => $return->id,
            'inventory_item_id' => $hardware->id,
            'quantity_returned' => 0,
            'condition' => 'pending',
            'damage_charge' => 0,
        ]);

        // Attempting to submit empty items without adjusting returns
        $response = $this->actingAs($this->admin)->put(route('admin.return-tracking.update', $return), [
            'notes' => 'Attempting to bypass hardware returns',
        ]);

        $response->assertSessionHas('error');
        $this->assertSame('Pending', $return->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Return Charge Contract
    |--------------------------------------------------------------------------
    */

    public function test_return_charge_persists_and_updates_booking_obligation(): void
    {
        $hardware = InventoryItem::create([
            'name' => 'Glass Vase',
            'category' => 'hardware',
            'is_perishable' => false,
            'current_stock' => 20,
            'unit_cost' => 250,
            'min_stock' => 2,
            'unit' => 'piece',
        ]);

        $booking = Booking::create([
            'client_id' => $this->clientRecord->id,
            'event_type' => 'wedding',
            'event_date' => now()->subDay()->toDateString(),
            'venue' => 'Bayview',
            'status' => 'event_completed',
            'confirmed_at' => now()->subDays(7),
            'total_quoted' => 1000,
            'final_quoted_price' => 1000,
            'remaining_balance' => 0,
        ]);

        Payment::create([
            'booking_id' => $booking->id,
            'amount' => 1000,
            'payment_option' => 'full_payment',
            'amount_paid' => 1000,
            'remaining_balance' => 0,
            'status' => 'fully_paid',
            'payment_type' => 'gcash',
            'reference_number' => 'REF-PAID-FULL',
            'verified_at' => now(),
            'verified_by' => $this->admin->id,
        ]);

        InventoryTransaction::create([
            'inventory_item_id' => $hardware->id,
            'booking_id' => $booking->id,
            'quantity_change' => -4,
            'transaction_type' => 'dispatch',
            'performed_by' => $this->admin->id,
            'reason' => 'Dispatched 4 vases',
        ]);

        $return = AssetReturn::create([
            'booking_id' => $booking->id,
            'status' => 'Pending',
            'total_damage_charge' => 0,
        ]);

        $returnItem = ReturnItem::create([
            'return_id' => $return->id,
            'inventory_item_id' => $hardware->id,
            'quantity_returned' => 3,
            'condition' => 'pending',
            'damage_charge' => 0,
        ]);

        // Submit return with damage charge sending charge_client (normalized to charge)
        $response = $this->actingAs($this->admin)->put(route('admin.return-tracking.update', $return), [
            'items' => [
                $returnItem->id => [
                    'quantity_returned' => 3,
                    'condition' => 'damaged',
                    'charge_decision' => 'charge_client',
                    'damage_charge' => 500,
                    'charge_reason' => '1 vase cracked during event transport',
                ],
            ],
            'notes' => 'Inspection completed',
        ]);

        $response->assertRedirect(route('admin.return-tracking'));

        $returnItem->refresh();
        $this->assertSame('charge', $returnItem->charge_decision);
        $this->assertEquals(500.0, (float) $returnItem->damage_charge);
        $this->assertSame('1 vase cracked during event transport', $returnItem->charge_reason);

        // Booking obligation and remaining balance now reflects the 500 damage charge
        $booking->refresh();
        $this->assertEquals(1500.0, (float) $booking->total_obligation);
        $this->assertEquals(500.0, (float) $booking->remaining_balance);
        $this->assertSame('pending_return', $booking->status);

        // Retrying submission does not duplicate the charge
        $this->actingAs($this->admin)->put(route('admin.return-tracking.update', $return), [
            'items' => [
                $returnItem->id => [
                    'quantity_returned' => 3,
                    'condition' => 'damaged',
                    'charge_decision' => 'charge',
                    'damage_charge' => 500,
                    'charge_reason' => '1 vase cracked during event transport',
                ],
            ],
            'notes' => 'Inspection repeated',
        ]);

        $booking->refresh();
        $this->assertEquals(1500.0, (float) $booking->total_obligation);
        $this->assertEquals(500.0, (float) $booking->remaining_balance);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Staff Physical Count & Admin Stock Adjustment (Decision A)
    |--------------------------------------------------------------------------
    */

    public function test_staff_cannot_directly_mutate_stock_and_admin_approves_adjustment(): void
    {
        $item = InventoryItem::create([
            'name' => 'Cocktail Table',
            'category' => 'hardware',
            'is_perishable' => false,
            'current_stock' => 15,
            'min_stock' => 3,
            'unit_cost' => 800,
            'unit' => 'piece',
        ]);

        $booking = Booking::create([
            'staff_id' => $this->staff->id,
            'client_id' => $this->clientRecord->id,
            'event_type' => 'party',
            'event_date' => now()->addDays(5)->toDateString(),
            'venue' => 'Roof Deck',
            'status' => 'confirmed',
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 5,
            'confirmed_at' => now(),
            'procurement_status' => 'confirmed',
        ]);

        // Staff submits observed count of 12 (discrepancy: -3)
        $response = $this->actingAs($this->staff)->put(route('staff.events.inventory.update', [$booking, $item]), [
            'observed_stock' => 12,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Global current stock remains UNTOUCHED at 15
        $this->assertSame(15.0, (float) $item->fresh()->current_stock);
        $this->assertDatabaseCount('inventory_transactions', 0);

        // An AdminAlert is generated
        $alert = AdminAlert::where('type', 'physical_count_variance')
            ->where('booking_id', $booking->id)
            ->where('inventory_item_id', $item->id)
            ->firstOrFail();

        $this->assertFalse($alert->is_read);
        $this->assertStringContainsString('observed physical count of 12', $alert->message);

        // Staff cannot approve the adjustment
        $this->actingAs($this->staff)
            ->post(route('admin.inventory.adjustments.approve', $alert))
            ->assertForbidden();

        // Admin approves the adjustment
        $adminResp = $this->actingAs($this->admin)
            ->post(route('admin.inventory.adjustments.approve', $alert), [
                'admin_reason' => 'Approved staff physical audit count',
            ]);

        $adminResp->assertRedirect();
        $adminResp->assertSessionHas('success');

        // Global stock is now updated to 12
        $this->assertSame(12.0, (float) $item->fresh()->current_stock);
        $this->assertTrue($alert->fresh()->is_read);

        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $item->id,
            'booking_id' => $booking->id,
            'quantity_change' => -3.0,
            'transaction_type' => 'adjustment',
            'performed_by' => $this->admin->id,
        ]);
    }

    public function test_admin_can_reject_staff_physical_adjustment(): void
    {
        $item = InventoryItem::create([
            'name' => 'Folding Chair',
            'category' => 'hardware',
            'is_perishable' => false,
            'current_stock' => 50,
            'min_stock' => 5,
            'unit_cost' => 100,
            'unit' => 'piece',
        ]);

        $booking = Booking::create([
            'staff_id' => $this->staff->id,
            'client_id' => $this->clientRecord->id,
            'event_type' => 'seminar',
            'event_date' => now()->addDays(2)->toDateString(),
            'venue' => 'Room 101',
            'status' => 'confirmed',
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 10,
            'confirmed_at' => now(),
            'procurement_status' => 'confirmed',
        ]);

        $this->actingAs($this->staff)->put(route('staff.events.inventory.update', [$booking, $item]), [
            'observed_stock' => 45,
        ]);

        $alert = AdminAlert::where('type', 'physical_count_variance')
            ->where('booking_id', $booking->id)
            ->firstOrFail();

        // Admin rejects the adjustment
        $this->actingAs($this->admin)
            ->post(route('admin.inventory.adjustments.reject', $alert))
            ->assertRedirect();

        $this->assertSame(50.0, (float) $item->fresh()->current_stock);
        $this->assertTrue($alert->fresh()->is_read);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Fresh Flower Readiness (Decision B)
    |--------------------------------------------------------------------------
    */

    public function test_booking_with_fresh_flowers_cannot_start_until_admin_confirms_readiness(): void
    {
        $flower = InventoryItem::create([
            'name' => 'Tulips',
            'category' => 'fresh_flower',
            'is_perishable' => true,
            'current_stock' => 30,
            'unit_cost' => 120,
            'min_stock' => 5,
            'unit' => 'stem',
        ]);

        $booking = Booking::create([
            'client_id' => $this->clientRecord->id,
            'event_type' => 'anniversary',
            'event_date' => now()->addDays(30)->toDateString(),
            'preparation_start_date' => now()->toDateString(),
            'venue' => 'Sunset Terrace',
            'status' => 'confirmed',
            'preparation_status' => 'ready', // Generic ready status does NOT bypass fresh flower readiness
            'confirmed_at' => now(),
            'total_quoted' => 3000,
            'remaining_balance' => 0,
        ]);

        $bookingItem = BookingItem::create([
            'booking_id' => $booking->id,
            'inventory_item_id' => $flower->id,
            'item_name' => $flower->name,
            'quantity' => 15,
            'confirmed_at' => now(),
            'procurement_status' => 'pending', // Not confirmed yet
        ]);
        Payment::create([
            'booking_id' => $booking->id,
            'amount' => 100,
            'payment_type' => 'bank_transfer',
            'payment_option' => 'downpayment',
            'reference_number' => 'VERIFIED-' . uniqid(),
            'status' => 'downpayment_received',
            'verified_at' => now(),
        ]);

        // Attempt to mark event in progress before Admin explicitly confirms fresh flowers
        $response = $this->actingAs($this->admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $booking->event_date->toDateString(),
            'venue' => $booking->venue,
            'status' => $booking->status,
            'action' => 'mark_event_in_progress',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Unable to start the event: fresh flower procurement readiness must be confirmed by Admin before execution.');
        $this->assertSame('confirmed', $booking->fresh()->status);

        // Staff cannot confirm fresh flowers
        $this->actingAs($this->staff)
            ->post(route('admin.bookings.confirm-fresh-flowers', $booking))
            ->assertForbidden();

        // Admin explicitly confirms fresh flowers
        $confirmResponse = $this->actingAs($this->admin)
            ->post(route('admin.bookings.confirm-fresh-flowers', $booking));

        $confirmResponse->assertRedirect();
        $confirmResponse->assertSessionHas('success', 'Fresh flower readiness has been explicitly confirmed for this event.');

        $this->assertSame('confirmed', $bookingItem->fresh()->procurement_status);
        $this->assertTrue($booking->fresh()->areFreshFlowersReady());

        // Now marking event in progress succeeds
        $response2 = $this->actingAs($this->admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $booking->event_date->toDateString(),
            'venue' => $booking->venue,
            'status' => $booking->status,
            'action' => 'mark_event_in_progress',
        ]);

        $response2->assertRedirect();
        $this->assertSame('event_in_progress', $booking->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Cancelled Booking Recovery After Dispatch (Decision C)
    |--------------------------------------------------------------------------
    */

    public function test_cancelled_booking_with_dispatched_materials_enters_recovery_without_auto_restock(): void
    {
        $speaker = InventoryItem::create([
            'name' => 'PA Speaker System',
            'category' => 'electronics',
            'is_perishable' => false,
            'current_stock' => 8,
            'unit_cost' => 5000,
            'min_stock' => 1,
            'unit' => 'set',
        ]);

        $booking = Booking::create([
            'client_id' => $this->clientRecord->id,
            'event_type' => 'concert',
            'event_date' => now()->addDays(1)->toDateString(),
            'venue' => 'Arena Park',
            'status' => 'event_in_progress',
            'confirmed_at' => now()->subDays(3),
            'total_quoted' => 10000,
            'remaining_balance' => 0,
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'inventory_item_id' => $speaker->id,
            'item_name' => $speaker->name,
            'quantity' => 2,
            'confirmed_at' => now(),
            'procurement_status' => 'confirmed',
        ]);

        // Initial booking lock at confirmation
        InventoryTransaction::create([
            'inventory_item_id' => $speaker->id,
            'booking_id' => $booking->id,
            'quantity_change' => -2,
            'transaction_type' => 'booking_lock',
            'performed_by' => $this->admin->id,
            'reason' => 'Booking confirmation lock',
        ]);

        // Dispatched 2 speaker sets (physical stock was reduced by 2 at dispatch)
        $speaker->decrement('current_stock', 2);
        InventoryTransaction::create([
            'inventory_item_id' => $speaker->id,
            'booking_id' => $booking->id,
            'quantity_change' => -2,
            'transaction_type' => 'dispatch',
            'performed_by' => $this->admin->id,
            'reason' => 'Dispatched speaker sets',
        ]);

        $this->assertSame(6.0, (float) $speaker->fresh()->current_stock);

        // Client cancels after dispatch, Admin approves cancellation
        $booking->update([
            'status' => 'cancellation_requested',
            'pre_cancellation_status' => 'event_in_progress',
        ]);
        $cancelResponse = $this->actingAs($this->admin)->post(route('admin.bookings.handle-cancellation', $booking), [
            'action' => 'approve',
            'admin_note' => 'Event cancelled by client post-dispatch',
        ]);

        $cancelResponse->assertRedirect();
        $this->assertSame('cancelled', $booking->fresh()->status);

        // Crucial Decision C verification: Stock is NOT automatically restored upon cancellation!
        $this->assertSame(6.0, (float) $speaker->fresh()->current_stock);

        // A return record was automatically ensured for recovery
        $return = AssetReturn::where('booking_id', $booking->id)->first();
        $this->assertNotNull($return);
        $this->assertSame('Pending', $return->status);

        // The cancelled booking appears in Return Tracking
        $indexResponse = $this->actingAs($this->admin)->get(route('admin.return-tracking'));
        $indexResponse->assertOk();
        $indexResponse->assertSee((string) $booking->id);

        // Return audit inspection: 2 speakers returned in good condition
        $returnItem = ReturnItem::where('return_id', $return->id)->where('inventory_item_id', $speaker->id)->firstOrFail();

        $updateResponse = $this->actingAs($this->admin)->put(route('admin.return-tracking.update', $return), [
            'items' => [
                $returnItem->id => [
                    'quantity_returned' => 2,
                    'condition' => 'good',
                    'charge_decision' => 'no_charge',
                ],
            ],
            'notes' => 'Recovered all dispatched items post-cancellation.',
        ]);

        $updateResponse->assertRedirect(route('admin.return-tracking'));

        // Authorized return assessment restores stock to 8
        $this->assertSame(8.0, (float) $speaker->fresh()->current_stock);
        $this->assertSame('Completed', $return->fresh()->status);

        // Booking status remains 'cancelled' (not overridden to 'completed')
        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. Admin Event Actions in Booking Detail (Scope 7)
    |--------------------------------------------------------------------------
    */

    public function test_admin_booking_detail_view_renders_event_execution_controls(): void
    {
        $booking = Booking::create([
            'client_id' => $this->clientRecord->id,
            'event_type' => 'reception',
            'event_date' => now()->addDays(3)->toDateString(),
            'venue' => 'Clubhouse',
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'total_quoted' => 2500,
            'remaining_balance' => 0,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.bookings.show', $booking));
        $response->assertOk();
        $response->assertSee('Operational Actions');
        $response->assertSee('Mark Event In Progress');

        // When in progress, shows Mark Event Completed
        $booking->update(['status' => 'event_in_progress']);
        $response2 = $this->actingAs($this->admin)->get(route('admin.bookings.show', $booking));
        $response2->assertOk();
        $response2->assertSee('Mark Event Completed');

        // When completed, shows Manage Return Audit
        $booking->update(['status' => 'event_completed']);
        $response3 = $this->actingAs($this->admin)->get(route('admin.bookings.show', $booking));
        $response3->assertOk();
        $response3->assertSee('Manage Return Audit');
    }

    /*
    |--------------------------------------------------------------------------
    | 8. Damage & Loss Traceability (Scope 8)
    |--------------------------------------------------------------------------
    */

    public function test_damage_and_loss_audit_trail_without_duplicate_stock_deduction(): void
    {
        $backdrop = InventoryItem::create([
            'name' => 'Silk Backdrop',
            'category' => 'decor',
            'is_perishable' => false,
            'current_stock' => 10,
            'unit_cost' => 1000,
            'min_stock' => 1,
            'unit' => 'piece',
        ]);

        $booking = Booking::create([
            'client_id' => $this->clientRecord->id,
            'event_type' => 'debut',
            'event_date' => now()->subDay()->toDateString(),
            'venue' => 'Grand Hall',
            'status' => 'event_completed',
            'confirmed_at' => now()->subDays(5),
            'total_quoted' => 4000,
            'remaining_balance' => 0,
        ]);

        // Dispatch 2 backdrops (stock decreases 10 -> 8)
        $backdrop->decrement('current_stock', 2);
        InventoryTransaction::create([
            'inventory_item_id' => $backdrop->id,
            'booking_id' => $booking->id,
            'quantity_change' => -2,
            'transaction_type' => 'dispatch',
            'performed_by' => $this->admin->id,
            'reason' => 'Dispatched backdrops',
        ]);

        $this->assertSame(8.0, (float) $backdrop->fresh()->current_stock);

        $return = AssetReturn::create([
            'booking_id' => $booking->id,
            'status' => 'Pending',
            'total_damage_charge' => 0,
        ]);

        $returnItem = ReturnItem::create([
            'return_id' => $return->id,
            'inventory_item_id' => $backdrop->id,
            'quantity_returned' => 2,
            'condition' => 'pending',
            'damage_charge' => 0,
        ]);

        // Assess as damaged: stock was ALREADY deducted at dispatch, so current_stock MUST REMAIN 8!
        $this->actingAs($this->admin)->put(route('admin.return-tracking.update', $return), [
            'items' => [
                $returnItem->id => [
                    'quantity_returned' => 2,
                    'condition' => 'damaged',
                    'charge_decision' => 'charge',
                    'damage_charge' => 800,
                    'charge_reason' => 'Torn fabric',
                ],
            ],
            'notes' => 'Damaged condition assessment',
        ]);

        // Verify stock is NOT deducted again (remains 8.0)
        $this->assertSame(8.0, (float) $backdrop->fresh()->current_stock);

        // Verify AuditLog entry exists for damage assessment
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'return_damage_assessed',
            'module' => 'return_tracking',
            'entity_type' => ReturnItem::class,
            'entity_id' => $returnItem->id,
        ]);
    }
}
