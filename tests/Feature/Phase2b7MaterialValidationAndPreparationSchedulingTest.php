<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2b7MaterialValidationAndPreparationSchedulingTest extends TestCase
{
    use RefreshDatabase;

    // ==========================================
    // 1. MATERIAL VALIDATION & ROLE BOUNDARIES
    // ==========================================

    public function test_admin_can_validate_and_confirm_material_suggestions(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking();
        $inv = $this->createInventoryItem('Gold Arch Frame', 'props', false, 5);

        $item = $booking->bookingItems()->create([
            'inventory_item_id' => $inv->id,
            'item_name' => $inv->name,
            'quantity' => 2,
            'quoted_unit_price' => 250,
            'is_ai_suggested' => true,
            'procurement_status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->post(route('admin.bookings.items.confirm', [$booking, $item]));
        $response->assertRedirect();

        $this->assertNotNull($item->fresh()->confirmed_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'material_confirmed',
            'entity_id' => $booking->id,
        ]);
    }

    public function test_staff_receives_403_for_material_confirmation_and_linking(): void
    {
        $staff = $this->createStaff();
        $booking = $this->createBooking();
        $inv = $this->createInventoryItem('Gold Arch Frame', 'props', false, 5);

        $item = $booking->bookingItems()->create([
            'inventory_item_id' => $inv->id,
            'item_name' => $inv->name,
            'quantity' => 1,
            'is_ai_suggested' => true,
        ]);

        // Staff confirm attempt -> 403
        $confirmResponse = $this->actingAs($staff)->post(route('admin.bookings.items.confirm', [$booking, $item]));
        $confirmResponse->assertForbidden();

        // Staff link attempt -> 403
        $linkResponse = $this->actingAs($staff)->post(route('admin.bookings.ai.link', $booking), [
            'item_name' => $inv->name,
            'inventory_item_id' => $inv->id,
        ]);
        $linkResponse->assertForbidden();

        // Staff promote attempt -> 403
        $promoteResponse = $this->actingAs($staff)->post(route('admin.bookings.ai.promote', $booking), [
            'item_name' => 'Custom Pillar',
            'catalog_name' => 'Custom Pillar',
            'category' => 'props',
            'unit_price' => 100,
        ]);
        $promoteResponse->assertForbidden();
    }

    public function test_unvalidated_unlinked_ai_suggestions_block_quotation_submission(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking();

        // Unlinked AI suggestion without catalog mapping
        $booking->bookingItems()->create([
            'item_name' => 'Floating Crystal Orb',
            'quantity' => 2,
            'is_ai_suggested' => true,
            'inventory_item_id' => null,
            'confirmed_at' => null,
        ]);

        $response = $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $booking->event_date->toDateString(),
            'venue' => $booking->venue,
            'status' => 'pending',
            'action' => 'send_quotation',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('pending', $booking->fresh()->status);
        $this->assertSame(0, Quotation::where('booking_id', $booking->id)->count());
    }

    public function test_invalid_material_references_fail_safely(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking();

        // Attempting to link non-existent inventory item
        $response = $this->actingAs($admin)->post(route('admin.bookings.ai.link', $booking), [
            'item_name' => 'Ghost Item',
            'link_inventory_item_id' => 999999,
        ]);

        $response->assertSessionHasErrors(['link_inventory_item_id']);
    }

    // ==========================================
    // 2. QUOTATION ISSUANCE CONTINUITY ON SHORTAGE
    // ==========================================

    public function test_admin_can_issue_quotation_when_required_material_is_not_currently_stocked(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking();

        // Inventory item with 0 current stock
        $unstockedHardware = $this->createInventoryItem('Heavy Wooden Backdrop', 'props', false, 0);

        $bookingItem = $booking->bookingItems()->create([
            'inventory_item_id' => $unstockedHardware->id,
            'item_name' => $unstockedHardware->name,
            'quantity' => 3,
            'quoted_unit_price' => 500,
            'is_ai_suggested' => false,
            'confirmed_at' => now(),
        ]);

        // Admin sends quotation despite 0 physical stock
        $response = $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $booking->event_date->toDateString(),
            'venue' => $booking->venue,
            'status' => 'pending',
            'action' => 'send_quotation',
            'items' => [[
                'booking_item_id' => $bookingItem->id,
                'item_name' => $unstockedHardware->name,
                'quantity' => 3,
                'unit_price' => 500,
            ]],
        ]);

        $response->assertRedirect();
        $booking->refresh();

        $this->assertSame('quotation_sent', $booking->status);
        $this->assertDatabaseHas('quotations', [
            'booking_id' => $booking->id,
            'version' => 1,
        ]);

        // Stock was not decremented
        $this->assertSame(0.0, (float) $unstockedHardware->fresh()->current_stock);
        $this->assertSame(0.0, (float) $unstockedHardware->fresh()->reserved_stock);
    }

    public function test_quotation_issuance_does_not_reserve_or_decrement_stock(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking();
        $inv = $this->createInventoryItem('Vintage Chair', 'props', false, 10);

        $bookingItem = $booking->bookingItems()->create([
            'inventory_item_id' => $inv->id,
            'item_name' => $inv->name,
            'quantity' => 4,
            'quoted_unit_price' => 150,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $booking->event_date->toDateString(),
            'venue' => $booking->venue,
            'status' => 'pending',
            'action' => 'send_quotation',
            'items' => [[
                'booking_item_id' => $bookingItem->id,
                'item_name' => $inv->name,
                'quantity' => 4,
                'unit_price' => 150,
            ]],
        ]);

        $inv->refresh();
        $this->assertSame(10.0, (float) $inv->current_stock);
        $this->assertSame(0.0, (float) $inv->reserved_stock);
        $this->assertSame(0, InventoryTransaction::where('booking_id', $booking->id)->count());
    }

    // ==========================================
    // 3. PREPARATION SCHEDULING
    // ==========================================

    public function test_admin_can_define_and_adjust_preparation_start_date(): void
    {
        $admin = $this->createAdmin();
        $eventDate = Carbon::today()->addDays(30);
        $booking = $this->createBooking(['event_date' => $eventDate]);
        $this->createVerifiedPayment($booking);

        $prepDate = Carbon::today()->addDays(15)->toDateString();

        $response = $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $eventDate->toDateString(),
            'venue' => $booking->venue,
            'status' => $booking->status,
            'preparation_start_date' => $prepDate,
            'preparation_status' => 'scheduled',
        ]);

        $response->assertRedirect();
        $booking->refresh();

        $this->assertSame($prepDate, $booking->preparation_start_date->toDateString());
        $this->assertSame('scheduled', $booking->preparation_status);
        $this->assertFalse($booking->isInPreparationPeriod());

        // Adjust date to today (now in preparation period)
        $todayStr = Carbon::today()->toDateString();
        $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $eventDate->toDateString(),
            'venue' => $booking->venue,
            'status' => $booking->status,
            'preparation_start_date' => $todayStr,
            'preparation_status' => 'in_preparation',
        ]);

        $booking->refresh();
        $this->assertSame($todayStr, $booking->preparation_start_date->toDateString());
        $this->assertSame('in_preparation', $booking->preparation_status);
        $this->assertTrue($booking->isInPreparationPeriod());
    }

    public function test_admin_can_schedule_preparation_from_october_24_through_october_28_for_november_30_event(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 18:30:00'));

        try {
            $admin = $this->createAdmin();
            $eventDate = Carbon::parse('2026-11-30');
            $booking = $this->createBooking(['event_date' => $eventDate]);
            $this->createVerifiedPayment($booking);

            foreach (range(24, 28) as $day) {
                $prepDate = Carbon::create(2026, 10, $day)->toDateString();
                $response = $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
                    'event_type' => $booking->event_type,
                    'event_date' => $eventDate->toDateString(),
                    'venue' => $booking->venue,
                    'status' => $booking->status,
                    'preparation_start_date' => $prepDate,
                ]);

                $response->assertSessionHasNoErrors();
                $this->assertSame($prepDate, $booking->fresh()->preparation_start_date->toDateString());
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_preparation_start_date_must_be_today_or_later_and_at_least_fourteen_days_before_event(): void
    {
        $admin = $this->createAdmin();
        $eventDate = Carbon::today()->addDays(30);
        $booking = $this->createBooking(['event_date' => $eventDate]);
        $this->createVerifiedPayment($booking);

        $invalidPrepDates = [
            Carbon::yesterday()->toDateString(),
            $eventDate->copy()->subDays(13)->toDateString(),
            $eventDate->toDateString(),
        ];

        foreach ($invalidPrepDates as $invalidPrepDate) {
            $response = $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
                'event_type' => $booking->event_type,
                'event_date' => $eventDate->toDateString(),
                'venue' => $booking->venue,
                'status' => $booking->status,
                'preparation_start_date' => $invalidPrepDate,
            ]);

            $response->assertSessionHasErrors(['preparation_start_date']);
        }
        $this->assertNull($booking->fresh()->preparation_start_date);
    }

    public function test_preparation_date_cannot_be_set_before_payment_is_verified(): void
    {
        $admin = $this->createAdmin();
        $eventDate = Carbon::today()->addDays(30);
        $booking = $this->createBooking(['event_date' => $eventDate]);

        $response = $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $eventDate->toDateString(),
            'venue' => $booking->venue,
            'status' => $booking->status,
            'preparation_start_date' => Carbon::today()->toDateString(),
        ]);

        $response->assertSessionHasErrors(['preparation_start_date']);
        $this->assertNull($booking->fresh()->preparation_start_date);
    }

    // ==========================================
    // 4. INVENTORY RESERVATION & FRESH FLOWERS
    // ==========================================

    public function test_payment_verification_reserves_reusable_inventory_when_valid_prep_date_is_in_future(): void
    {
        $admin = $this->createAdmin();
        [$clientUser, $client] = $this->createClientWithUser();
        $eventDate = Carbon::today()->addDays(50);
        $prepDate = Carbon::today()->addDays(30);

        $booking = Booking::create([
            'client_id' => $client->id,
            'event_type' => 'wedding',
            'event_date' => $eventDate,
            'preparation_start_date' => $prepDate,
            'preparation_status' => 'scheduled',
            'venue' => 'Grand Plaza',
            'status' => 'payment_submitted',
            'total_quoted' => 10000,
        ]);

        $hardware = $this->createInventoryItem('Metal Arch Stand', 'props', false, 5);

        $booking->bookingItems()->create([
            'inventory_item_id' => $hardware->id,
            'item_name' => $hardware->name,
            'quantity' => 2,
            'quoted_unit_price' => 500,
            'confirmed_at' => now(),
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 5000,
            'payment_type' => 'bank_transfer',
            'payment_option' => 'downpayment',
            'reference_number' => 'REF-PREP-FUTURE',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->post(route('admin.payments.verify', $payment));
        $response->assertRedirect();

        $booking->refresh();
        $this->assertSame('downpayment_received', $booking->status);

        $this->assertSame(2.0, (float) $hardware->fresh()->reserved_stock);
        $this->assertSame(1, InventoryTransaction::where('booking_id', $booking->id)->where('transaction_type', 'booking_lock')->count());
    }

    public function test_fresh_flowers_do_not_create_booking_locks(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking([
            'event_date' => Carbon::today()->addDays(30),
            'preparation_start_date' => Carbon::today()->addDays(5),
            'preparation_status' => 'in_preparation',
            'status' => 'confirmed',
        ]);
        $this->createVerifiedPayment($booking);

        // Perishable fresh flowers
        $flowers = $this->createInventoryItem('Ecuadorian Red Roses', 'flowers', true, 100);

        $booking->bookingItems()->create([
            'inventory_item_id' => $flowers->id,
            'item_name' => $flowers->name,
            'quantity' => 50,
            'quoted_unit_price' => 60,
            'confirmed_at' => now(),
        ]);

        // Trigger material reservation
        $response = $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));
        $response->assertRedirect();

        // Fresh flowers do NOT create booking_lock transactions
        $this->assertSame(0.0, (float) $flowers->fresh()->reserved_stock);
        $this->assertSame(0, InventoryTransaction::where('booking_id', $booking->id)->where('transaction_type', 'booking_lock')->count());
    }

    public function test_admin_can_reclassify_a_fresh_item_as_reusable_for_one_booking_without_deleting_it(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking([
            'event_date' => Carbon::today()->addDays(30),
            'preparation_start_date' => Carbon::today()->addDays(5),
            'status' => 'confirmed',
        ]);
        $this->createVerifiedPayment($booking);
        $item = $this->createInventoryItem('Artificial Rose Stem', 'flowers', true, 10);
        $bookingItem = $booking->bookingItems()->create([
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 2,
            'confirmed_at' => now(),
            'procurement_status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->post(
            route('admin.bookings.items.fresh-procurement', [$booking, $bookingItem]),
            ['fresh_procurement' => '0']
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('booking_items', [
            'id' => $bookingItem->id,
            'booking_id' => $booking->id,
            'fresh_procurement_override' => 0,
        ]);
        $this->assertTrue((bool) $item->fresh()->is_perishable);
        $this->assertSame(1, $booking->bookingItems()->count());

        $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));

        $this->assertSame(2.0, (float) $item->fresh()->reserved_stock);
        $this->assertTrue($booking->fresh()->areFreshFlowersReady());
    }

    public function test_admin_can_reserve_reusable_materials_at_preparation_period(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking([
            'event_date' => Carbon::today()->addDays(30),
            'preparation_start_date' => Carbon::today(),
            'preparation_status' => 'scheduled',
            'status' => 'confirmed',
        ]);
        $this->createVerifiedPayment($booking);

        $reusable = $this->createInventoryItem('Centerpiece Stand', 'props', false, 10);

        $booking->bookingItems()->create([
            'inventory_item_id' => $reusable->id,
            'item_name' => $reusable->name,
            'quantity' => 4,
            'quoted_unit_price' => 200,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(4.0, (float) $reusable->fresh()->reserved_stock);
        $this->assertSame(1, InventoryTransaction::where('booking_id', $booking->id)->where('transaction_type', 'booking_lock')->count());
        $this->assertSame('in_preparation', $booking->fresh()->preparation_status);
    }

    public function test_staff_cannot_reserve_materials(): void
    {
        $staff = $this->createStaff();
        $booking = $this->createBooking(['status' => 'confirmed']);

        $response = $this->actingAs($staff)->post(route('admin.bookings.reserve-materials', $booking));
        $response->assertForbidden();
    }

    public function test_insufficient_stock_blocks_reservation_without_corrupting_ledger(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['status' => 'confirmed', 'preparation_start_date' => now()->toDateString()]);
        $this->createVerifiedPayment($booking);

        $scarceHardware = $this->createInventoryItem('Neon Arch 2026', 'props', false, 1);

        $booking->bookingItems()->create([
            'inventory_item_id' => $scarceHardware->id,
            'item_name' => $scarceHardware->name,
            'quantity' => 3, // Requires 3, only 1 available!
            'quoted_unit_price' => 500,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));
        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Ledger is uncorrupted
        $this->assertSame(0.0, (float) $scarceHardware->fresh()->reserved_stock);
        $this->assertSame(0, InventoryTransaction::where('booking_id', $booking->id)->count());
    }

    public function test_repeated_reservation_is_idempotent(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking(['status' => 'confirmed', 'preparation_start_date' => now()->toDateString()]);
        $this->createVerifiedPayment($booking);
        $hardware = $this->createInventoryItem('Floral Pedestal', 'props', false, 10);

        $booking->bookingItems()->create([
            'inventory_item_id' => $hardware->id,
            'item_name' => $hardware->name,
            'quantity' => 2,
            'quoted_unit_price' => 300,
            'confirmed_at' => now(),
        ]);

        // First reservation
        $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));
        $this->assertSame(2.0, (float) $hardware->fresh()->reserved_stock);
        $this->assertSame(1, InventoryTransaction::where('booking_id', $booking->id)->where('transaction_type', 'booking_lock')->count());

        // Second reservation call
        $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));
        // Still exactly 2.0 reserved, no duplicate transaction
        $this->assertSame(2.0, (float) $hardware->fresh()->reserved_stock);
        $this->assertSame(1, InventoryTransaction::where('booking_id', $booking->id)->where('transaction_type', 'booking_lock')->count());
    }

    // ==========================================
    // 5. SHORTAGE HANDLING BRIDGE
    // ==========================================

    public function test_shortage_resolution_from_booking_restocks_and_redirects_to_booking(): void
    {
        $admin = $this->createAdmin();
        $booking = $this->createBooking();
        $item = $this->createInventoryItem('Candle Holder Gold', 'props', false, 2);

        $response = $this->actingAs($admin)->post(route('admin.bookings.resolve-shortages', $booking), [
            'redirect_to' => 'booking',
            'action' => 'restock_and_resolve',
            'stock' => [
                $item->id => 5, // restock 5 items
            ],
        ]);

        $response->assertRedirect(route('admin.bookings.show', $booking));
        $response->assertSessionHas('success');

        $this->assertSame(7.0, (float) $item->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $item->id,
            'booking_id' => $booking->id,
            'transaction_type' => 'procurement',
            'quantity_change' => 5,
        ]);
    }

    // ==========================================
    // 6. END-TO-END WORKFLOW INTEGRATION
    // ==========================================

    public function test_complete_registered_client_material_planning_workflow(): void
    {
        $admin = $this->createAdmin();
        [$clientUser, $client] = $this->createClientWithUser();
        $eventDate = Carbon::today()->addDays(20);

        // Step 1: Registered client submits booking
        $booking = Booking::create([
            'client_id' => $client->id,
            'event_type' => 'wedding',
            'event_date' => $eventDate,
            'venue' => 'Seaside Pavilion',
            'status' => 'pending',
            'total_quoted' => 0,
        ]);

        // Step 2: AI suggests material and hardware items
        $backdropInv = $this->createInventoryItem('Geometric Arch', 'props', false, 0); // 0 stock!
        $freshRoses = $this->createInventoryItem('White Gardenia', 'flowers', true, 0);  // 0 stock!

        $backdropItem = $booking->bookingItems()->create([
            'inventory_item_id' => $backdropInv->id,
            'item_name' => $backdropInv->name,
            'quantity' => 1,
            'quoted_unit_price' => 2000,
            'is_ai_suggested' => true,
        ]);

        $rosesItem = $booking->bookingItems()->create([
            'inventory_item_id' => $freshRoses->id,
            'item_name' => $freshRoses->name,
            'quantity' => 20,
            'quoted_unit_price' => 100,
            'is_ai_suggested' => true,
        ]);

        // Step 3: Admin confirms/validates AI materials
        $this->actingAs($admin)->post(route('admin.bookings.items.confirm', [$booking, $backdropItem]));
        $this->actingAs($admin)->post(route('admin.bookings.items.confirm', [$booking, $rosesItem]));

        $this->assertNotNull($backdropItem->fresh()->confirmed_at);
        $this->assertNotNull($rosesItem->fresh()->confirmed_at);

        // Step 4: Admin issues quotation despite stock shortages
        $quoteResponse = $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $eventDate->toDateString(),
            'venue' => $booking->venue,
            'status' => 'pending',
            'action' => 'send_quotation',
            'final_quoted_price' => 4000,
            'items' => [
                ['booking_item_id' => $backdropItem->id, 'item_name' => $backdropInv->name, 'quantity' => 1, 'unit_price' => 2000],
                ['booking_item_id' => $rosesItem->id, 'item_name' => $freshRoses->name, 'quantity' => 20, 'unit_price' => 100],
            ],
        ]);

        $quoteResponse->assertRedirect();
        $booking->refresh();
        $this->assertSame('quotation_sent', $booking->status);
        $this->assertSame(4000.0, (float) $booking->final_quoted_price);

        // Step 5: Client accepts quotation
        $acceptResponse = $this->actingAs($clientUser)->post(route('bookings.accept', $booking));
        $acceptResponse->assertSessionMissing('error');
        $booking->refresh();
        $this->assertSame('approved', $booking->status);

        // Step 6: Admin final approval advances to admin_approved
        $this->actingAs($admin)->post(route('admin.bookings.final-approve', $booking));
        $booking->refresh();
        $this->assertSame('admin_approved', $booking->status);

        // Step 7: Client submits payment
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 2000,
            'payment_type' => 'gcash',
            'payment_option' => 'downpayment',
            'reference_number' => 'GCASH-E2E-1234',
            'status' => 'pending',
        ]);
        $booking->status = 'payment_submitted';
        $booking->save();

        // Step 8: Admin verifies payment
        $this->actingAs($admin)->post(route('admin.payments.verify', $payment));
        $booking->refresh();
        $this->assertSame('downpayment_received', $booking->status);

        // Step 9: Reusable stock is NOT reserved yet because prep date is in the future
        $this->assertSame(0.0, (float) $backdropInv->fresh()->reserved_stock);

        // Step 10: Admin restocks the backdrop shortage
        $this->actingAs($admin)->post(route('admin.bookings.resolve-shortages', $booking), [
            'redirect_to' => 'booking',
            'action' => 'restock_and_resolve',
            'stock' => [$backdropInv->id => 1],
        ]);
        $this->assertSame(1.0, (float) $backdropInv->fresh()->current_stock);

        // Step 11: Event reaches preparation period -> Admin reserves reusable inventory
        $scheduleResponse = $this->actingAs($admin)->put(route('admin.bookings.update', $booking), [
            'event_type' => $booking->event_type,
            'event_date' => $eventDate->toDateString(),
            'venue' => $booking->venue,
            'status' => $booking->status,
            'preparation_start_date' => Carbon::today()->toDateString(),
        ]);
        $scheduleResponse->assertRedirect();

        $this->actingAs($admin)->post(route('admin.bookings.reserve-materials', $booking));

        // Reusable stock is now locked
        $this->assertSame(1.0, (float) $backdropInv->fresh()->reserved_stock);
        // Perishable flowers did NOT consume warehouse locks
        $this->assertSame(0.0, (float) $freshRoses->fresh()->reserved_stock);
        $this->assertSame('in_preparation', $booking->fresh()->preparation_status);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function createAdmin(): User
    {
        return User::create([
            'name' => 'Admin Manager',
            'email' => 'admin-' . uniqid() . '@raflora.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);
    }

    private function createStaff(): User
    {
        return User::create([
            'name' => 'Staff Assistant',
            'email' => 'staff-' . uniqid() . '@raflora.com',
            'password' => bcrypt('password123'),
            'role' => 'staff',
        ]);
    }

    private function createClientWithUser(): array
    {
        $user = User::create([
            'name' => 'Valued Client',
            'email' => 'client-' . uniqid() . '@client.com',
            'password' => bcrypt('password123'),
            'role' => 'client',
            'email_verified_at' => now(),
        ]);

        $client = Client::create([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'email' => $user->email,
            'phone' => '09171234567',
            'address' => 'Quezon City',
        ]);

        return [$user, $client];
    }

    private function createBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'event_type' => 'wedding',
            'event_date' => Carbon::today()->addDays(14),
            'venue' => 'Rosewood Manor',
            'status' => 'pending',
            'total_quoted' => 0,
        ], $overrides));
    }

    private function createInventoryItem(string $name, string $category, bool $isPerishable, float $stock): InventoryItem
    {
        return InventoryItem::create([
            'name' => $name,
            'category' => $category,
            'is_perishable' => $isPerishable,
            'current_stock' => $stock,
            'unit_cost' => 100,
            'min_stock' => 1,
            'unit' => $isPerishable ? 'stem' : 'piece',
        ]);
    }

    private function createVerifiedPayment(Booking $booking): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'amount' => 100,
            'payment_type' => 'bank_transfer',
            'payment_option' => 'downpayment',
            'reference_number' => 'VERIFIED-' . uniqid(),
            'status' => 'downpayment_received',
            'verified_at' => now(),
        ]);
    }
}
