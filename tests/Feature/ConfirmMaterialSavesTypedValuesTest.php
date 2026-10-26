<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quote table values (Qty, Unit Cost) are saved when Admin clicks Confirm Material, Confirm all or
 * Approve & Send — never while typing — and saving them never moves inventory stock or changes
 * the catalog price.
 */
class ConfirmMaterialSavesTypedValuesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Booking $booking;
    private InventoryItem $roses;
    private InventoryItem $vase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['full_name' => 'Test Client', 'email' => 'client@example.com', 'phone' => '09170000000']);
        $this->booking = Booking::create([
            'client_id' => $client->id,
            'status' => 'pending',
            'event_type' => 'wedding',
            'event_date' => now()->addDays(30)->toDateString(),
            'venue' => 'Grand Hall',
        ]);
        $this->roses = $this->inventoryItem('White Rose', 80, 500, perishable: true);
        $this->vase = $this->inventoryItem('Glass Vase', 50, 40);
    }

    public function test_confirm_material_saves_the_typed_quantity_and_unit_cost_without_touching_inventory(): void
    {
        $line = $this->line($this->roses, 250, 80);

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.items.confirm', [$this->booking, $line]), ['quantity' => 300, 'unit_price' => '75.50'])
            ->assertSessionHas('success');

        $line->refresh();
        $this->assertNotNull($line->confirmed_at);
        $this->assertSame(300.0, (float) $line->quantity);
        $this->assertSame(75.5, (float) $line->quoted_unit_price);
        $this->assertInventoryUntouched();
        $this->assertTrue(\App\Models\AuditLog::where('action', 'quote_line_updated')->exists());
    }

    public function test_confirming_a_combined_row_confirms_all_source_lines_and_preserves_their_prices(): void
    {
        $first = $this->line($this->roses, 10, 70);
        $second = $this->line($this->roses, 30, 90);
        $this->assertSame([10.0, 30.0], [(float) $first->quantity, (float) $second->quantity]);

        $this->actingAs($this->admin)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertOk()
            ->assertSee('Combined 2 lines');

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.items.confirm', [$this->booking, $first]), [
                'booking_item_ids' => [$first->id, $second->id],
                'quantity' => 100,
                'unit_price' => '85',
            ])
            ->assertSessionHas('success', '2 materials confirmed for this booking.');

        $this->assertSame([25.0, 75.0], [
            (float) $first->fresh()->quantity,
            (float) $second->fresh()->quantity,
        ]);
        $this->assertSame([70.0, 90.0], [
            (float) $first->fresh()->quoted_unit_price,
            (float) $second->fresh()->quoted_unit_price,
        ]);
        $this->assertNotNull($first->fresh()->confirmed_at);
        $this->assertNotNull($second->fresh()->confirmed_at);
        $this->assertSame(100.0, (float) $this->roses->fresh()->bookings()
            ->whereKey($this->booking->id)->sum('booking_items.quantity'));
    }

    public function test_confirm_material_without_typed_values_still_confirms_the_saved_ones(): void
    {
        $line = $this->line($this->roses, 250, 80);

        $this->actingAs($this->admin)->post(route('admin.bookings.items.confirm', [$this->booking, $line]))->assertSessionHas('success');

        $line->refresh();
        $this->assertNotNull($line->confirmed_at);
        $this->assertSame(250.0, (float) $line->quantity);
        $this->assertSame(80.0, (float) $line->quoted_unit_price);
    }

    public function test_confirm_all_saves_each_rows_typed_values(): void
    {
        $roses = $this->line($this->roses, 250, 0);
        $vase = $this->line($this->vase, 10, 0);

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.items.confirm-all', $this->booking), ['lines' => [
                $roses->id => ['quantity' => 260, 'unit_price' => '80'],
                $vase->id => ['quantity' => 12, 'unit_price' => '45.25'],
            ]])
            ->assertSessionHas('success');

        $this->assertSame([260.0, 80.0], [(float) $roses->fresh()->quantity, (float) $roses->fresh()->quoted_unit_price]);
        $this->assertSame([12.0, 45.25], [(float) $vase->fresh()->quantity, (float) $vase->fresh()->quoted_unit_price]);
        $this->assertNotNull($vase->fresh()->confirmed_at);
        $this->assertInventoryUntouched();
    }

    public function test_one_bad_value_saves_and_confirms_nothing(): void
    {
        $roses = $this->line($this->roses, 250, 0);
        $vase = $this->line($this->vase, 10, 0);

        foreach ([['quantity' => -1, 'unit_price' => '45'], ['quantity' => 12, 'unit_price' => ''], ['quantity' => '1.5', 'unit_price' => '45']] as $bad) {
            $this->actingAs($this->admin)
                ->post(route('admin.bookings.items.confirm-all', $this->booking), ['lines' => [
                    $roses->id => ['quantity' => 260, 'unit_price' => '80'],
                    $vase->id => $bad,
                ]])
                ->assertSessionHas('error');
        }

        $this->assertNull($roses->fresh()->confirmed_at);
        $this->assertSame([250.0, 0.0], [(float) $roses->fresh()->quantity, (float) $roses->fresh()->quoted_unit_price]);
        $this->assertNull($vase->fresh()->confirmed_at);
    }

    public function test_quantity_cannot_change_once_stock_is_reserved_and_nothing_is_saved(): void
    {
        $this->booking->update(['status' => 'confirmed']);
        $reserved = $this->line($this->vase, 10, 50, confirmed: true);
        InventoryTransaction::create(['booking_id' => $this->booking->id, 'inventory_item_id' => $this->vase->id, 'transaction_type' => 'booking_lock', 'quantity_change' => -10]);
        $roses = $this->line($this->roses, 250, 0);
        $extraVase = $this->line($this->vase, 2, 50);

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.items.confirm-all', $this->booking), ['lines' => [
                $roses->id => ['quantity' => 260, 'unit_price' => '80'],
                $extraVase->id => ['quantity' => 5, 'unit_price' => '50'],
            ]])
            ->assertSessionHas('error', 'Stock for "Glass Vase" is already reserved or dispatched for this booking, so its quantity cannot be changed here. Nothing was saved.');

        // All or nothing: the rose row before it was not saved either, and the stock hold is unchanged.
        $this->assertNull($roses->fresh()->confirmed_at);
        $this->assertSame(250.0, (float) $roses->fresh()->quantity);
        $this->assertSame(10.0, $this->vase->fresh()->reservedForBooking($this->booking->id));
        $this->assertSame(10.0, (float) $reserved->fresh()->quantity);

        // A price-only change on that reserved item is fine: prices never move stock.
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.items.confirm', [$this->booking, $extraVase]), ['quantity' => 2, 'unit_price' => '55'])
            ->assertSessionHas('success');
        $this->assertSame(55.0, (float) $extraVase->fresh()->quoted_unit_price);
        $this->assertSame(10.0, $this->vase->fresh()->reservedForBooking($this->booking->id));
    }

    public function test_read_only_stages_refuse_changed_values(): void
    {
        $line = $this->line($this->roses, 250, 80);
        $this->booking->update(['status' => 'downpayment_received']);

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.items.confirm', [$this->booking, $line]), ['quantity' => 999, 'unit_price' => '80'])
            ->assertSessionHas('error');

        $this->assertSame(250.0, (float) $line->fresh()->quantity);
        $this->assertNull($line->fresh()->confirmed_at);
    }

    public function test_approve_and_send_saves_the_typed_values_too(): void
    {
        $line = $this->line($this->vase, 10, 50, confirmed: true);

        $this->actingAs($this->admin)
            ->put(route('admin.bookings.update', $this->booking), [
                'event_type' => 'wedding',
                'event_date' => $this->booking->event_date->toDateString(),
                'venue' => 'Grand Hall',
                'status' => 'pending',
                'action' => 'send_quotation',
                'multiplier' => 3,
                'items' => [[
                    'booking_item_id' => $line->id,
                    'inventory_item_id' => $this->vase->id,
                    'item_name' => 'Glass Vase',
                    'quantity' => 14,
                    'unit_price' => '52.50',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('quotation_sent', $this->booking->fresh()->status);
        $this->assertSame([14.0, 52.5], [(float) $line->fresh()->quantity, (float) $line->fresh()->quoted_unit_price]);
        $this->assertInventoryUntouched();
    }

    public function test_a_declined_booking_keeps_its_prices_and_loses_no_stock(): void
    {
        $line = $this->line($this->vase, 10, 50);
        $this->actingAs($this->admin)->post(route('admin.bookings.items.confirm', [$this->booking, $line]), ['quantity' => 12, 'unit_price' => '60']);

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.decline', $this->booking), ['reason' => 'Fully booked that day.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('declined', $this->booking->fresh()->status);
        $this->assertSame([12.0, 60.0], [(float) $line->fresh()->quantity, (float) $line->fresh()->quoted_unit_price]);
        $this->assertInventoryUntouched();
    }

    public function test_the_confirm_forms_carry_the_rows_values(): void
    {
        $line = $this->line($this->vase, 10, 50);

        $html = $this->actingAs($this->admin)->get(route('admin.bookings.show', $this->booking))->assertOk()->getContent();

        $this->assertStringContainsString('name="quantity" value="10" data-sync-group-row="0"', $html);
        $this->assertStringContainsString('name="lines[' . $line->id . '][unit_price]" value="50.00"', $html);
        $this->assertStringContainsString('function syncConfirmLineValues()', $html);
    }

    private function assertInventoryUntouched(): void
    {
        $this->assertSame(0, InventoryTransaction::count(), 'Saving quote values must not move stock.');
        $this->assertSame([500.0, 80.0], [(float) $this->roses->fresh()->current_stock, (float) $this->roses->fresh()->unit_cost]);
        $this->assertSame([40.0, 50.0], [(float) $this->vase->fresh()->current_stock, (float) $this->vase->fresh()->unit_cost]);
    }

    private function line(InventoryItem $item, float $quantity, float $price, bool $confirmed = false): BookingItem
    {
        return BookingItem::create([
            'booking_id' => $this->booking->id,
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => $quantity,
            'quoted_unit_price' => $price,
            'is_ai_suggested' => true,
            'procurement_status' => 'pending',
            'confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    private function inventoryItem(string $name, float $unitCost, float $stock, bool $perishable = false): InventoryItem
    {
        return InventoryItem::create(['name' => $name, 'category' => 'props', 'is_perishable' => $perishable, 'current_stock' => $stock, 'unit_cost' => $unitCost, 'min_stock' => 0, 'unit' => 'piece']);
    }
}
