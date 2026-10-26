<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Client;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Carbon\Carbon;

class Step10DOperationalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::create([
            'name' => 'Admin User',
            'email' => 'admin-' . uniqid() . '@raflora.com',
            'password' => bcrypt('password123'),
            'role' => 'admin'
        ]);
    }

    private function createStaff(): User
    {
        return User::create([
            'name' => 'Staff User',
            'email' => 'staff-' . uniqid() . '@raflora.com',
            'password' => bcrypt('password123'),
            'role' => 'staff'
        ]);
    }

    private function createBooking(array $overrides = []): Booking
    {
        $user = User::create([
            'name' => 'Client User',
            'email' => 'client-' . uniqid() . '@raflora.com',
            'password' => bcrypt('password123'),
            'role' => 'client'
        ]);

        $client = Client::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'email' => $user->email,
            'phone' => '09171234567',
            'address' => 'Quezon City',
        ]);

        return Booking::create(array_merge([
            'client_id' => $client->id,
            'event_type' => 'wedding',
            'venue' => 'Manor',
            'event_date' => Carbon::today()->addMonths(2)->toDateString(),
            'status' => 'payment_submitted',
            'total_quoted' => 1000,
            'confirmed_at' => now(),
        ], $overrides));
    }

    private function createInventoryItem(string $name, string $category, bool $isPerishable, int $stock): InventoryItem
    {
        return InventoryItem::create([
            'item_code' => 'ITM-' . rand(1000, 9999),
            'name' => $name,
            'category' => $category,
            'is_perishable' => $isPerishable,
            'current_stock' => $stock,
            'min_stock' => 1,
            'unit' => 'pcs',
            'unit_cost' => 100,
        ]);
    }

    private function setupBookingWithPayment(Booking $booking, string $paymentType): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'payment_type' => 'bank_transfer',
            'payment_option' => $paymentType,
            'status' => 'pending',
            'amount' => 500,
            'reference_number' => 'REF-' . uniqid(),
            'proof_of_payment' => 'fake/path.jpg',
        ]);
    }

    private function createVerifiedPayment(Booking $booking): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'payment_type' => 'bank_transfer',
            'payment_option' => 'downpayment',
            'status' => 'downpayment_received',
            'amount' => 500,
            'reference_number' => 'VERIFIED-' . uniqid(),
            'verified_at' => now(),
        ]);
    }

    public function test_confirmed_booking_with_null_preparation_start_date_does_not_reserve(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['preparation_start_date' => null, 'status' => 'payment_submitted']);
        $item = $this->createInventoryItem('Vase', 'props', false, 10);
        $booking->bookingItems()->create([
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 2,
            'quoted_unit_price' => 100,
            'confirmed_at' => now(),
        ]);
        $payment = $this->setupBookingWithPayment($booking, 'downpayment');

        $response = $this->actingAs($admin)->post(route('admin.payments.verify', $payment), [
            'action' => 'verify',
            'amount_verified' => 500,
        ]);

        $response->assertRedirect();
        
        $booking->refresh();
        $this->assertEquals('downpayment_received', $booking->status);
        $this->assertEquals('scheduled', $booking->preparation_status);
        $this->assertNull($booking->preparation_start_date);
        
        $this->assertSame(0, InventoryTransaction::where('booking_id', $booking->id)->where('transaction_type', 'booking_lock')->count());
        $this->assertSame(0.0, (float) $item->fresh()->reserved_stock);
    }

    public function test_confirmed_booking_with_future_preparation_start_date_reserves_on_payment_verification(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['preparation_start_date' => now()->addDays(5)->toDateString(), 'status' => 'payment_submitted']);
        $item = $this->createInventoryItem('Vase', 'props', false, 10);
        $booking->bookingItems()->create([
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 2,
            'quoted_unit_price' => 100,
            'confirmed_at' => now(),
        ]);
        $payment = $this->setupBookingWithPayment($booking, 'full_payment');

        $response = $this->actingAs($admin)->post(route('admin.payments.verify', $payment), [
            'action' => 'verify',
            'amount_verified' => 500,
        ]);

        $response->assertRedirect();
        
        $booking->refresh();
        $this->assertEquals('confirmed', $booking->status);
        $this->assertEquals('scheduled', $booking->preparation_status);
        
        $this->assertSame(1, InventoryTransaction::where('booking_id', $booking->id)->where('transaction_type', 'booking_lock')->count());
        $this->assertSame(2.0, (float) $item->fresh()->reserved_stock);
    }

    public function test_confirmed_booking_with_today_preparation_start_date_reserves_inventory_on_verify(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['preparation_start_date' => now()->toDateString(), 'status' => 'payment_submitted']);
        $item = $this->createInventoryItem('Vase', 'props', false, 10);
        $booking->bookingItems()->create([
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 2,
            'quoted_unit_price' => 100,
            'confirmed_at' => now(),
        ]);
        $payment = $this->setupBookingWithPayment($booking, 'downpayment');

        $response = $this->actingAs($admin)->post(route('admin.payments.verify', $payment), [
            'action' => 'verify',
            'amount_verified' => 500,
        ]);

        $response->assertRedirect();
        
        $booking->refresh();
        $this->assertEquals('downpayment_received', $booking->status);
        // Reservation is handled, so booking_lock should exist.
        $this->assertSame(1, InventoryTransaction::where('booking_id', $booking->id)->where('transaction_type', 'booking_lock')->count());
        $this->assertSame(2.0, (float) $item->fresh()->reserved_stock);
    }

    public function test_admin_reserve_materials_fails_if_preparation_start_date_is_null(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['preparation_start_date' => null, 'status' => 'confirmed']);
        $this->createVerifiedPayment($booking);
        $item = $this->createInventoryItem('Vase', 'props', false, 10);
        $booking->bookingItems()->create([
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 2,
            'quoted_unit_price' => 100,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));
        $response->assertSessionHas('error');
        $this->assertSame(0, InventoryTransaction::where('booking_id', $booking->id)->count());
    }

    public function test_admin_can_reserve_materials_before_the_future_preparation_start_date(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['preparation_start_date' => now()->addDays(5)->toDateString(), 'status' => 'confirmed']);
        $this->createVerifiedPayment($booking);
        $item = $this->createInventoryItem('Vase', 'props', false, 10);
        $booking->bookingItems()->create([
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 2,
            'quoted_unit_price' => 100,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));
        $response->assertSessionHas('success');
        $this->assertSame(1, InventoryTransaction::where('booking_id', $booking->id)->where('transaction_type', 'booking_lock')->count());
        $this->assertSame(2.0, (float) $item->fresh()->reserved_stock);
    }

    public function test_admin_reserve_materials_succeeds_if_preparation_start_date_is_today(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['preparation_start_date' => now()->toDateString(), 'status' => 'confirmed']);
        $this->createVerifiedPayment($booking);
        $item = $this->createInventoryItem('Vase', 'props', false, 10);
        $booking->bookingItems()->create([
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 2,
            'quoted_unit_price' => 100,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));
        $response->assertSessionHas('success');
        $this->assertSame(1, InventoryTransaction::where('booking_id', $booking->id)->where('transaction_type', 'booking_lock')->count());
        $this->assertSame('in_preparation', $booking->fresh()->preparation_status);
    }

    public function test_admin_can_set_preparation_start_date_via_update(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['status' => 'confirmed', 'preparation_start_date' => null]);
        $this->createVerifiedPayment($booking);
        
        $response = $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
            'status' => 'confirmed',
            'event_type' => 'wedding',
            'event_date' => $booking->event_date->format('Y-m-d'),
            'venue' => 'Test Venue',
            'preparation_start_date' => now()->toDateString(),
        ]);
        
        $response->assertRedirect();
        $this->assertEquals(now()->toDateString(), $booking->fresh()->preparation_start_date->toDateString());
    }

    public function test_admin_can_confirm_fresh_flowers(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['status' => 'confirmed', 'preparation_start_date' => now()->addDays(5)->toDateString()]);
        $this->createVerifiedPayment($booking);
        
        $item = $this->createInventoryItem('Roses', 'flowers', true, 100);
        $booking->bookingItems()->create([
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 20,
            'quoted_unit_price' => 10,
            'confirmed_at' => now(),
            'procurement_status' => 'pending'
        ]);

        $this->assertFalse($booking->areFreshFlowersReady());

        $response = $this->actingAs($admin)->post(route('admin.bookings.confirm-fresh-flowers', $booking));
        
        $response->assertSessionHas('success');
        $this->assertTrue($booking->fresh()->areFreshFlowersReady());
    }

    public function test_admin_can_dispatch_reserved_materials(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['status' => 'confirmed', 'preparation_start_date' => now()->toDateString()]);
        $this->createVerifiedPayment($booking);
        
        $item = $this->createInventoryItem('Vase', 'props', false, 10);
        $booking->bookingItems()->create([
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 2,
            'quoted_unit_price' => 100,
            'confirmed_at' => now(),
        ]);
        
        $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));

        $response = $this->actingAs($admin)->post(route('admin.bookings.dispatch', $booking), [
            'reason' => 'Dispatch for event',
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity' => 2]
            ]
        ]);
        
        $response->assertSessionHas('success');
        $this->assertSame(1, InventoryTransaction::where('booking_id', $booking->id)->where('transaction_type', 'dispatch')->count());
        $this->assertEquals(8, $item->fresh()->current_stock);
    }

    public function test_mark_event_in_progress_fails_without_dispatch(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['status' => 'confirmed', 'preparation_start_date' => now()->toDateString()]);
        $this->createVerifiedPayment($booking);
        
        $item = $this->createInventoryItem('Vase', 'props', false, 10);
        $booking->bookingItems()->create([
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 2,
            'quoted_unit_price' => 100,
            'confirmed_at' => now(),
        ]);
        
        $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));

        $response = $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
            'action' => 'mark_event_in_progress',
            'status' => 'confirmed',
            'event_type' => 'wedding',
            'event_date' => $booking->event_date->format('Y-m-d'),
            'venue' => 'Manor'
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('materials have not been dispatched', session('error'));
        $this->assertEquals('confirmed', $booking->fresh()->status);
    }

    public function test_mark_event_in_progress_succeeds_with_dispatch(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['status' => 'confirmed', 'preparation_start_date' => now()->toDateString()]);
        $this->createVerifiedPayment($booking);
        
        $item = $this->createInventoryItem('Vase', 'props', false, 10);
        $booking->bookingItems()->create([
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 2,
            'quoted_unit_price' => 100,
            'confirmed_at' => now(),
        ]);
        
        $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));
        $this->actingAs($admin)->post(route('admin.bookings.dispatch', $booking), [
            'reason' => 'Dispatch for event',
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity' => 2]
            ]
        ]);

        $response = $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
            'action' => 'mark_event_in_progress',
            'status' => 'confirmed',
            'event_type' => 'wedding',
            'event_date' => $booking->event_date->format('Y-m-d'),
            'venue' => 'Manor'
        ]);

        $response->assertRedirect();
        $this->assertEquals('event_in_progress', $booking->fresh()->status);
    }
}
