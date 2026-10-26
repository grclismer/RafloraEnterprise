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

/**
 * Dispatch errors name the material and its unit (never an internal item number) and say what
 * to do next, for Admin and Staff alike. Nothing is dispatched when a check fails.
 */
class DispatchErrorMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private Booking $booking;
    private InventoryItem $arch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->staff = User::factory()->create(['role' => 'staff']);
        $client = Client::create(['full_name' => 'Test Client', 'email' => 'client@example.com', 'phone' => '09170000000']);
        $this->booking = Booking::create([
            'client_id' => $client->id,
            'staff_id' => $this->staff->id,
            'status' => 'downpayment_received',
            'confirmed_at' => now(),
            'event_type' => 'wedding',
            'event_date' => now()->addDays(30)->toDateString(),
            'preparation_start_date' => now()->toDateString(),
            'venue' => 'Grand Hall',
        ]);
        $this->arch = InventoryItem::create(['name' => 'Entrance Floral Arch Arrangement', 'category' => 'Props', 'is_perishable' => false, 'current_stock' => 200, 'unit_cost' => 450, 'min_stock' => 0, 'unit' => 'pieces']);
        BookingItem::create([
            'booking_id' => $this->booking->id,
            'inventory_item_id' => $this->arch->id,
            'item_name' => $this->arch->name,
            'quantity' => 240,
            'confirmed_at' => now(),
        ]);
        Payment::create([
            'booking_id' => $this->booking->id,
            'amount' => 100,
            'payment_type' => 'bank_transfer',
            'payment_option' => 'downpayment',
            'reference_number' => 'VERIFIED-' . uniqid(),
            'status' => 'downpayment_received',
            'verified_at' => now(),
        ]);
        InventoryTransaction::create(['booking_id' => $this->booking->id, 'inventory_item_id' => $this->arch->id, 'transaction_type' => 'booking_lock', 'quantity_change' => -240]);
    }

    public function test_not_enough_stock_names_the_item_for_admin_and_staff(): void
    {
        $expected = 'Dispatch failed: Cannot dispatch 240 pieces of "Entrance Floral Arch Arrangement": only 200 pieces are in stock. Receive stock in Inventory Management, or dispatch fewer.';

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.dispatch', $this->booking), ['items' => [['inventory_item_id' => $this->arch->id, 'quantity' => 240]], 'reason' => 'Load truck'])
            ->assertSessionHas('error', $expected);

        $this->actingAs($this->staff)
            ->post(route('staff.events.dispatch', $this->booking), ['items' => [['inventory_item_id' => $this->arch->id, 'quantity' => 240]], 'reason' => 'Load truck'])
            ->assertSessionHas('error', $expected);

        $this->assertStringNotContainsString('item ' . $this->arch->id, $expected);
        $this->assertSame(200.0, (float) $this->arch->fresh()->current_stock, 'Nothing is dispatched.');
    }

    public function test_more_than_reserved_names_the_item(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.dispatch', $this->booking), ['items' => [['inventory_item_id' => $this->arch->id, 'quantity' => 250]], 'reason' => 'Load truck'])
            ->assertSessionHas('error', 'Dispatch failed: Cannot dispatch 250 pieces of "Entrance Floral Arch Arrangement": only 240 pieces are reserved for this booking. Reserve more under Preparation, or dispatch fewer.');
    }

    public function test_archived_items_and_closed_bookings_have_readable_messages(): void
    {
        $this->arch->delete();
        try {
            app(InventoryDispatchService::class)->dispatchItems($this->booking, [['inventory_item_id' => $this->arch->id, 'quantity' => 1]], $this->admin->id, 'x');
            $this->fail('Dispatching an archived item must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('"Entrance Floral Arch Arrangement" is archived, so it cannot be dispatched. Restore it in Inventory Management first.', $e->getMessage());
        }

        $this->booking->update(['status' => 'pending_return']);
        $this->expectExceptionMessage("Booking #{$this->booking->id} cannot dispatch items while it is Pending Return.");
        app(InventoryDispatchService::class)->dispatchItems($this->booking->fresh(), [['inventory_item_id' => $this->arch->id, 'quantity' => 1]], $this->admin->id, 'x');
    }
}
