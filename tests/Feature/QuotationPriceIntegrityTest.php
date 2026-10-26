<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\ClientNotification;
use App\Models\InventoryItem;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\QuotationHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The issued quotation is the one authoritative price (Admin audit F1–F3): an Admin override,
 * the booking's price, the client's page and the client's payment all carry the same amount;
 * the quotation's price and item snapshot use the same lines; and a refused or failed
 * send/reissue keeps none of its changes.
 */
class QuotationPriceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // A — An Admin override that differs from the calculated total is the price everywhere.
    public function test_admin_override_is_the_issued_quotation_booking_client_and_payment_price(): void
    {
        [$user, $client] = $this->clientUser();
        $booking = $this->booking(['client_id' => $client->id]);
        $roses = $this->item($booking, 'White Roses', 10, 600); // 10 × ₱600 × 3 = ₱18,000 calculated

        $this->sendQuotation($booking, [
            'items' => [$this->row($roses)],
            'final_quoted_price' => 20000,
            'final_price_overridden' => '1',
        ])->assertSessionMissing('error');

        $booking->refresh();
        $quotation = $booking->activeQuotation;
        $this->assertEquals(20000.0, (float) $quotation->final_quoted_price);
        $this->assertEquals(20000.0, (float) $booking->final_quoted_price);
        $this->assertEquals(20000.0, (float) $booking->total_quoted);
        $this->assertEquals(20000.0, $booking->shared_quote_total);
        // The calculated parts stay on the quotation, so the override difference is traceable.
        $this->assertEquals(6000.0, (float) $quotation->raw_materials_sum);
        $this->assertEquals(3.0, (float) $quotation->multiplier);

        $this->actingAs($user)->get(route('client.booking.updates', $booking))
            ->assertOk()->assertSee('₱20,000.00')->assertDontSee('18,000.00');

        // Client accepts, Admin approves, client pays in full: the payment and balance follow the quotation.
        $this->actingAs($user)->post(route('bookings.accept', $booking))->assertSessionMissing('error');
        $this->actingAs($this->admin())->post(route('admin.bookings.final-approve', $booking))->assertSessionMissing('error');
        $this->actingAs($user)->post(route('bookings.payment.reference', $booking), [
            'reference_number' => 'GCASH-INTEGRITY-1',
            'payment_type' => 'gcash',
            'payment_option' => 'full_payment',
        ])->assertSessionMissing('error');

        $payment = Payment::where('booking_id', $booking->id)->sole();
        $this->assertEquals(20000.0, (float) $payment->amount);
        $this->assertSame($quotation->id, $payment->quotation_id);
        $this->assertEquals(20000.0, $booking->fresh()->remaining_balance);

        $this->actingAs($this->admin())->post(route('admin.payments.verify', $payment), ['amount_received' => 20000])
            ->assertSessionHas('success');

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertEquals(0.0, $booking->remaining_balance);
        $this->assertEquals(0.0, $booking->shared_remaining_balance);
    }

    // B — Without an override the existing formula applies, labour included.
    public function test_calculated_price_uses_confirmed_lines_multiplier_and_labour(): void
    {
        $booking = $this->booking(['multiplier' => 2.5, 'labor_method' => 'fixed', 'labor_rate' => 1500]);
        $roses = $this->item($booking, 'White Roses', 10, 600);
        $vases = $this->item($booking, 'Glass Vases', 4, 250);

        // The editor's running total leaves labour out (₱7,000 × 2.5); unchanged, it is not an override.
        $this->sendQuotation($booking, [
            'multiplier' => 2.5,
            'items' => [$this->row($roses), $this->row($vases)],
            'final_quoted_price' => 17500,
            'final_price_overridden' => '0',
        ])->assertSessionMissing('error');

        $booking->refresh();
        $quotation = $booking->activeQuotation;
        // (10 × 600 + 4 × 250) = 7,000 × 2.5 = 17,500 + 1,500 labour = 19,000
        $this->assertEquals(7000.0, (float) $quotation->raw_materials_sum);
        $this->assertEquals(1500.0, (float) $quotation->labor_amount);
        $this->assertEquals(19000.0, (float) $quotation->final_quoted_price);
        $this->assertEquals(19000.0, (float) $booking->final_quoted_price);
        $this->assertEquals(7000.0, (float) $booking->raw_materials_sum);
        $this->assertCount(2, $quotation->items_snapshot);
    }

    public function test_linked_duplicate_lines_are_one_quotation_row_without_losing_source_lines(): void
    {
        $booking = $this->booking();
        $inventoryItem = InventoryItem::create([
            'name' => 'Floral Foam',
            'category' => 'Props',
            'is_perishable' => false,
            'current_stock' => 0,
            'unit_cost' => 10,
            'min_stock' => 0,
            'unit' => 'blocks',
        ]);
        $first = $this->item($booking, 'Floral Foam', 30, 10, [
            'inventory_item_id' => $inventoryItem->id,
            'is_ai_suggested' => true,
        ]);
        $second = $this->item($booking, 'Floral Foam', 30, 12, [
            'inventory_item_id' => $inventoryItem->id,
            'is_ai_suggested' => true,
        ]);

        $groupedRow = $this->row($first, [
            'inventory_item_id' => $inventoryItem->id,
            'quantity' => 60,
            'unit_price' => 11,
            'source_booking_item_ids' => [$first->id, $second->id],
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Combined 2 lines')
            ->assertDontSee('Listed twice');

        $this->sendQuotation($booking, ['items' => [$groupedRow]])
            ->assertSessionMissing('error');

        $this->assertSame([30.0, 30.0], BookingItem::where('booking_id', $booking->id)
            ->orderBy('id')->pluck('quantity')->map(fn ($quantity) => (float) $quantity)->all());

        $quotation = $booking->fresh()->activeQuotation;
        $this->assertCount(1, $quotation->items_snapshot);
        $this->assertSame('Floral Foam', $quotation->items_snapshot[0]['item_name']);
        $this->assertEquals(60.0, $quotation->items_snapshot[0]['quantity']);
        $this->assertEquals(11.0, $quotation->items_snapshot[0]['quoted_unit_price']);
        $this->assertEquals(660.0, $quotation->items_snapshot[0]['amount']);
        $this->assertEquals(1980.0, $quotation->items_snapshot[0]['selling_amount']);

        $this->assertSame(2, BookingItem::where('booking_id', $booking->id)->count());
        $this->assertEquals(60.0, (float) BookingItem::where('booking_id', $booking->id)->sum('quantity'));
        $this->assertEquals(10.0, (float) $first->fresh()->quoted_unit_price);
        $this->assertEquals(12.0, (float) $second->fresh()->quoted_unit_price);
        $this->assertEquals(60.0, (float) $booking->inventoryItems()
            ->whereKey($inventoryItem->id)->sum('booking_items.quantity'));
    }

    // The editor posts its running total even when the Admin typed nothing; it never becomes the price.
    public function test_editor_running_total_is_not_treated_as_an_override(): void
    {
        $booking = $this->booking(['final_quoted_price' => 99999, 'total_quoted' => 99999]); // stale working figure
        $roses = $this->item($booking, 'White Roses', 10, 600);

        $this->sendQuotation($booking, [
            'items' => [$this->row($roses)],
            'final_quoted_price' => 24000, // e.g. counted an unconfirmed AI row
            'final_price_overridden' => '0',
        ])->assertSessionMissing('error');

        $this->assertEquals(18000.0, (float) $booking->fresh()->activeQuotation->final_quoted_price);
        $this->assertEquals(18000.0, (float) $booking->fresh()->final_quoted_price);
    }

    // C — A removed line stays on record but is left off the snapshot and the price.
    public function test_removed_material_is_excluded_from_snapshot_and_total(): void
    {
        $booking = $this->booking();
        $roses = $this->item($booking, 'White Roses', 10, 600);
        $stand = $this->item($booking, 'Brass Candle Stand', 5, 100);

        $this->sendQuotation($booking, [
            'items' => [$this->row($roses), $this->row($stand, ['remove' => '1'])],
            'final_quoted_price' => 18000,
            'final_price_overridden' => '0',
        ])->assertSessionMissing('error');

        $quotation = $booking->fresh()->activeQuotation;
        $this->assertSame(['White Roses'], collect($quotation->items_snapshot)->pluck('item_name')->all());
        $this->assertEquals(6000.0, (float) $quotation->raw_materials_sum);
        $this->assertEquals(18000.0, (float) $quotation->final_quoted_price);
        $this->assertEquals(18000.0, (float) $booking->fresh()->final_quoted_price);

        $stand->refresh();
        $this->assertSame('unmatched', $stand->procurement_status);
        $this->assertEquals(5.0, (float) $stand->quantity);
        $this->assertNotNull($stand->confirmed_at);
        $this->assertSame(10.0, (float) $roses->fresh()->quantity);
    }

    // D — An unconfirmed line is not quoted; an unconfirmed AI suggestion blocks sending.
    public function test_unconfirmed_material_is_excluded_and_unconfirmed_ai_blocks_sending(): void
    {
        $booking = $this->booking();
        $roses = $this->item($booking, 'White Roses', 10, 600);
        $ribbon = $this->item($booking, 'Satin Ribbon', 20, 50, ['confirmed_at' => null]);

        $this->sendQuotation($booking, [
            'items' => [$this->row($roses), $this->row($ribbon)],
        ])->assertSessionMissing('error');

        $quotation = $booking->fresh()->activeQuotation;
        $this->assertSame(['White Roses'], collect($quotation->items_snapshot)->pluck('item_name')->all());
        $this->assertEquals(18000.0, (float) $quotation->final_quoted_price);

        $aiBooking = $this->booking();
        $aiRoses = $this->item($aiBooking, 'White Roses', 10, 600);
        $this->item($aiBooking, 'Pampas Grass', 6, 120, ['confirmed_at' => null, 'is_ai_suggested' => true]);

        $this->sendQuotation($aiBooking, [
            'items' => [$this->row($aiRoses)],
        ])->assertSessionHas('error', 'Confirm every material requirement before sending the quotation.');

        $this->assertSame(0, $aiBooking->quotations()->count());
        $this->assertSame('pending', $aiBooking->fresh()->status);
    }

    // E — A refused first send keeps none of its price, material or status changes.
    public function test_refused_initial_send_leaves_booking_items_and_quotations_unchanged(): void
    {
        [$user, $client] = $this->clientUser();
        $booking = $this->booking(['client_id' => $client->id, 'multiplier' => 3.0, 'final_quoted_price' => 15000, 'total_quoted' => 15000]);
        $roses = $this->item($booking, 'White Roses', 10, 600);
        $stand = $this->item($booking, 'Brass Candle Stand', 5, 100);
        $standStatus = $stand->fresh()->procurement_status;
        // An AI line Admin confirmed but never priced: issuance refuses it.
        $pampas = $this->item($booking, 'Pampas Grass', 6, 0, ['is_ai_suggested' => true]);

        $this->sendQuotation($booking, [
            'multiplier' => 4,
            'admin_notes' => 'Updated for the new venue.',
            'items' => [
                $this->row($roses, ['quantity' => 25, 'unit_price' => 650]),
                $this->row($stand, ['remove' => '1']),
                $this->row($pampas),
            ],
            'final_quoted_price' => 25000,
            'final_price_overridden' => '1',
        ])->assertSessionHas('error');

        $booking->refresh();
        $this->assertSame('pending', $booking->status);
        $this->assertEquals(15000.0, (float) $booking->final_quoted_price);
        $this->assertEquals(15000.0, (float) $booking->total_quoted);
        $this->assertEquals(3.0, (float) $booking->multiplier);
        $this->assertNull($booking->price_valid_until);
        $this->assertNull($booking->reviewed_at);
        $this->assertNull($booking->admin_notes);

        $this->assertEquals(10.0, (float) $roses->fresh()->quantity);
        $this->assertEquals(600.0, (float) $roses->fresh()->quoted_unit_price);
        $this->assertSame($standStatus, $stand->fresh()->procurement_status);
        $this->assertSame(3, $booking->bookingItems()->count());

        $this->assertSame(0, $booking->quotations()->count());
        $this->assertSame(0, ClientNotification::where('booking_id', $booking->id)->count());
        Mail::assertNothingSent();
    }

    // F — A refused reissue over an accepted quotation leaves that quotation and the booking as they were.
    public function test_refused_reissue_after_acceptance_keeps_accepted_quotation_and_booking_price(): void
    {
        [$user, $client] = $this->clientUser();
        $booking = $this->booking(['client_id' => $client->id]);
        $roses = $this->item($booking, 'White Roses', 10, 600);

        $this->sendQuotation($booking, ['items' => [$this->row($roses)]])->assertSessionMissing('error');
        $this->actingAs($user)->post(route('bookings.accept', $booking))->assertSessionMissing('error');

        $accepted = $booking->fresh()->acceptedQuotation;
        $this->assertEquals(18000.0, (float) $accepted->final_quoted_price);
        $acceptedSnapshot = $accepted->items_snapshot;

        foreach (['reissue_quotation', 'send_quotation'] as $action) {
            $this->actingAs($this->admin())->put(route('admin.bookings.update', $booking), $this->form($booking->fresh(), [
                'action' => $action,
                'multiplier' => 5,
                'items' => [$this->row($roses, ['quantity' => 40])],
                'final_quoted_price' => 25000,
                'final_price_overridden' => '1',
            ]))->assertSessionHas('error');
        }

        $booking->refresh();
        $this->assertSame(1, $booking->quotations()->count());
        $accepted->refresh();
        $this->assertSame(Quotation::STATUS_ACCEPTED, $accepted->status);
        $this->assertEquals(18000.0, (float) $accepted->final_quoted_price);
        $this->assertEquals($acceptedSnapshot, $accepted->items_snapshot);

        $this->assertSame('approved', $booking->status);
        $this->assertEquals(18000.0, (float) $booking->final_quoted_price);
        $this->assertEquals(3.0, (float) $booking->multiplier);
        $this->assertEquals(18000.0, $booking->remaining_balance);
        $this->assertEquals(10.0, (float) $roses->fresh()->quantity);
    }

    // G — A permitted reissue keeps the Admin's price, supersedes the old version and refreshes validity.
    public function test_successful_reissue_keeps_override_and_supersedes_previous_version(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $booking = $this->booking(['event_date' => '2026-09-25']);
        $roses = $this->item($booking, 'White Roses', 10, 600);
        $stand = $this->item($booking, 'Brass Candle Stand', 5, 100);

        $this->sendQuotation($booking, [
            'items' => [$this->row($roses), $this->row($stand, ['remove' => '1'])],
            'final_quoted_price' => 20000,
            'final_price_overridden' => '1',
        ])->assertSessionMissing('error');
        $v1 = $booking->fresh()->activeQuotation;

        // The quotation expires; the editor reloads with the Admin's ₱20,000 still in the override box.
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));
        $this->actingAs($this->admin())->put(route('admin.bookings.update', $booking), $this->form($booking->fresh(), [
            'action' => 'reissue_quotation',
            'items' => [$this->row($roses), $this->row($stand)],
            'final_quoted_price' => 20000,
            'final_price_overridden' => '1',
        ]))->assertSessionMissing('error');

        $booking->refresh();
        $v1->refresh();
        $v2 = $booking->activeQuotation;

        $this->assertSame(Quotation::STATUS_SUPERSEDED, $v1->status);
        $this->assertEquals(20000.0, (float) $v1->final_quoted_price);
        $this->assertSame(2, $v2->version);
        $this->assertSame(Quotation::STATUS_ISSUED, $v2->status);
        $this->assertEquals(20000.0, (float) $v2->final_quoted_price);
        $this->assertSame(['White Roses'], collect($v2->items_snapshot)->pluck('item_name')->all());
        $this->assertSame('2026-09-17', $v2->valid_until->toDateString());

        $this->assertSame('quotation_sent', $booking->status);
        $this->assertEquals(20000.0, (float) $booking->final_quoted_price);
        $this->assertSame('2026-09-17', $booking->price_valid_until->toDateString());

        // Without an override, a reissue prices the current lines and the booking follows it.
        $roses->update(['quoted_unit_price' => 700]);
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00'));
        $this->actingAs($this->admin())->put(route('admin.bookings.update', $booking), $this->form($booking->fresh(), [
            'action' => 'reissue_quotation',
        ]))->assertSessionMissing('error');

        $this->assertEquals(21000.0, (float) $booking->fresh()->activeQuotation->final_quoted_price); // 10 × 700 × 3
        $this->assertEquals(21000.0, (float) $booking->fresh()->final_quoted_price);
    }

    // H — A database failure part-way through issuance rolls back every write of that request.
    public function test_failure_while_writing_the_quotation_rolls_back_the_whole_send(): void
    {
        $booking = $this->booking(['final_quoted_price' => 15000, 'total_quoted' => 15000]);
        $roses = $this->item($booking, 'White Roses', 10, 600);
        $stand = $this->item($booking, 'Brass Candle Stand', 5, 100);
        $standStatus = $stand->fresh()->procurement_status;

        Quotation::creating(function (): void {
            throw new \LogicException('Simulated database failure while writing the quotation.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->sendQuotation($booking, [
                'multiplier' => 4,
                'admin_notes' => 'Final numbers.',
                'items' => [$this->row($roses, ['quantity' => 12]), $this->row($stand, ['remove' => '1'])],
                'final_quoted_price' => 22000,
                'final_price_overridden' => '1',
            ]);
            $this->fail('The simulated failure should have stopped the send.');
        } catch (\LogicException $e) {
            $this->assertSame('Simulated database failure while writing the quotation.', $e->getMessage());
        }

        $booking->refresh();
        $this->assertSame('pending', $booking->status);
        $this->assertEquals(15000.0, (float) $booking->final_quoted_price);
        $this->assertEquals(3.0, (float) $booking->multiplier);
        $this->assertNull($booking->price_valid_until);
        $this->assertNull($booking->admin_notes);
        $this->assertEquals(10.0, (float) $roses->fresh()->quantity);
        $this->assertSame($standStatus, $stand->fresh()->procurement_status);
        $this->assertSame(0, Quotation::count());
        $this->assertSame(0, QuotationHistory::count());
        Mail::assertNothingSent();
    }

    private function sendQuotation(Booking $booking, array $fields)
    {
        return $this->actingAs($this->admin())->put(
            route('admin.bookings.update', $booking),
            $this->form($booking, array_merge(['action' => 'send_quotation'], $fields))
        );
    }

    private function form(Booking $booking, array $fields): array
    {
        return array_merge([
            '_method' => 'PUT',
            'event_type' => $booking->event_type,
            'event_date' => $booking->event_date->toDateString(),
            'venue' => $booking->venue,
            'status' => $booking->status,
            'special_requests' => null,
        ], $fields);
    }

    private function row(BookingItem $item, array $overrides = []): array
    {
        return array_merge([
            'booking_item_id' => $item->id,
            'item_name' => $item->item_name,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->quoted_unit_price,
            'is_ai_suggested' => $item->is_ai_suggested ? '1' : '0',
        ], $overrides);
    }

    private function item(Booking $booking, string $name, float $quantity, float $unitPrice, array $attributes = []): BookingItem
    {
        return BookingItem::create(array_merge([
            'booking_id' => $booking->id,
            'item_name' => $name,
            'quantity' => $quantity,
            'quoted_unit_price' => $unitPrice,
            'is_ai_suggested' => false,
            'confirmed_at' => now(),
        ], $attributes));
    }

    private function booking(array $attributes = []): Booking
    {
        return Booking::create(array_merge([
            'event_type' => 'wedding',
            'event_date' => now()->addDays(20)->toDateString(),
            'event_time' => '15:00',
            'venue' => 'The Garden Hall',
            'status' => 'pending',
            'multiplier' => 3.0,
        ], $attributes));
    }

    private function clientUser(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email' => 'integrity-' . uniqid() . '@example.com']);
        $client = Client::create(['full_name' => 'Integrity Client', 'email' => $user->email, 'phone' => '09170000000', 'address' => 'Manila']);

        return [$user, $client];
    }

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin-integrity@example.com'],
            ['name' => 'Admin', 'password' => bcrypt('password123'), 'role' => 'admin']
        );
    }
}
