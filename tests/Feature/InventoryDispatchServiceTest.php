<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\InventoryDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_and_physical_stock_reconciliation_during_dispatch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = new InventoryDispatchService();

        // 1. Setup inventory item
        $item = InventoryItem::create([
            'name' => 'Chair',
            'category' => 'furniture',
            'is_perishable' => false,
            'current_stock' => 50,
            'unit_cost' => 10,
            'unit' => 'piece',
        ]);

        // 2. Setup Booking 1 (The one we will dispatch)
        $booking1 = Booking::create([
            'event_type' => 'wedding',
            'event_date' => now()->addDays(30)->toDateString(),
            'preparation_start_date' => now()->subDay()->toDateString(),
            'status' => 'confirmed',
            'guest_email' => 'guest1@example.com',
            'confirmed_at' => now(), // explicitly confirmed
        ]);
        BookingItem::create([
            'booking_id' => $booking1->id,
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 10,
            'confirmed_at' => now(),
        ]);
        Payment::create([
            'booking_id' => $booking1->id,
            'amount' => 100,
            'payment_type' => 'bank_transfer',
            'payment_option' => 'downpayment',
            'reference_number' => 'VERIFIED-' . uniqid(),
            'status' => 'downpayment_received',
            'verified_at' => now(),
        ]);
        $this->assertTrue($booking1->hasPreparationStarted());

        // Confirmed requirement: 10 units
        InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'booking_id' => $booking1->id,
            'quantity_change' => 10,
            'transaction_type' => 'booking_lock',
            'performed_by' => $admin->id,
            'reason' => 'Reservation Lock',
        ]);

        // 3. Setup Booking 2 (To ensure active reservations remain protected)
        $booking2 = Booking::create([
            'event_type' => 'wedding',
            'event_date' => now()->addDays(12)->toDateString(),
            'status' => 'confirmed',
            'guest_email' => 'guest2@example.com',
            'confirmed_at' => now(),
        ]);

        InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'booking_id' => $booking2->id,
            'quantity_change' => 5,
            'transaction_type' => 'booking_lock',
            'performed_by' => $admin->id,
            'reason' => 'Reservation Lock',
        ]);

        // Assert Pre-Dispatch State
        $item->refresh();
        $this->assertEquals(50, $item->current_stock);
        // Total reserved = 10 (Booking 1) + 5 (Booking 2) = 15
        $this->assertEquals(15, $item->reserved_stock);
        $this->assertEquals(35, $item->net_available);

        // 4. Actual Dispatch: 4 units for Booking 1
        $service->dispatchItems($booking1, [
            ['inventory_item_id' => $item->id, 'quantity' => 4]
        ], $admin->id, 'Admin Dispatch');

        $item->refresh();

        // Physical stock decreases by exactly 4 units (50 -> 46)
        $this->assertEquals(46, $item->current_stock);
        
        // Outstanding quantity logic: Booking 1 original 10, dispatched 4, outstanding 6
        // Total reserved = 6 (Booking 1) + 5 (Booking 2) = 11
        $this->assertEquals(11, $item->reserved_stock);

        // The dispatched 4 units are not deducted a second time from availability
        // Net available = Current Stock (46) - Reserved Stock (11) = 35
        // (Remains 35, proving it doesn't double-deduct)
        $this->assertEquals(35, $item->net_available);

        // Other bookings' active reservations remain protected (Booking 2 still has 5 reserved)
    }

    public function test_legacy_booking_rejection(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = new InventoryDispatchService();

        // Null confirmed_at
        $booking = Booking::create([
            'event_type' => 'wedding',
            'event_date' => now()->subDays(10)->toDateString(),
            'status' => 'completed',
            'guest_email' => 'legacy@example.com',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('UNCLEAR');

        $service->dispatchItems($booking, [], $admin->id, 'Test');
    }

    public function test_dispatch_correction(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = new InventoryDispatchService();

        $item = InventoryItem::create([
            'name' => 'Chair',
            'category' => 'furniture',
            'is_perishable' => false,
            'current_stock' => 50,
            'unit_cost' => 10,
            'unit' => 'piece',
        ]);

        $booking = Booking::create([
            'event_type' => 'wedding',
            'event_date' => now()->addDays(30)->toDateString(),
            'preparation_start_date' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed',
            'guest_email' => 'guest@example.com',
            'confirmed_at' => now(),
        ]);
        BookingItem::create([
            'booking_id' => $booking->id,
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 10,
            'confirmed_at' => now(),
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

        InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'booking_id' => $booking->id,
            'quantity_change' => 10,
            'transaction_type' => 'booking_lock',
            'performed_by' => $admin->id,
            'reason' => 'Reservation',
        ]);

        $service->dispatchItems($booking, [['inventory_item_id' => $item->id, 'quantity' => 5]], $admin->id, 'Dispatch');

        $item->refresh();
        $this->assertEquals(45, $item->current_stock); // 50 - 5
        $this->assertEquals(5, $item->reserved_stock); // 10 - 5

        $dispatchTx = InventoryTransaction::where('transaction_type', 'dispatch')->first();

        // Correct 2 units (meaning we dispatched 2 too many, so we return 2 to stock)
        $service->correctDispatch($dispatchTx, 2, $admin->id, 'Correction');

        $item->refresh();
        $this->assertEquals(47, $item->current_stock); // 45 + 2
        // Reserved stock should increase because we dispatched less, so outstanding reservation increases.
        // Wait: lock 10, dispatch 5, correction 2 -> net dispatch 3. lock(10) - net dispatch(3) = 7.
        $this->assertEquals(7, $item->reserved_stock);
        // Net available: 47 - 7 = 40 (original 50 - lock 10 = 40). It remains consistent!
        $this->assertEquals(40, $item->net_available);

        // Try correcting more than the remaining dispatched amount (5 - 2 = 3)
        $this->expectException(\LogicException::class);
        $service->correctDispatch($dispatchTx, 4, $admin->id, 'Over-correction');
    }
}
