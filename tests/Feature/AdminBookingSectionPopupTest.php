<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBookingSectionPopupTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_stays_on_the_page_and_other_sections_open_in_the_popup(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.bookings.show', $this->booking()))
            ->assertOk()
            ->getContent();

        $overview = strpos($html, 'id="tab-overview"');
        $shell = strpos($html, 'id="booking-section-modal"');
        $chat = strpos($html, 'id="admin-chat-panel"');
        $this->assertNotFalse($overview);
        $this->assertNotFalse($shell);
        $this->assertLessThan($shell, $overview, 'Overview must sit outside the section pop-up.');

        foreach (['tab-details', 'tab-quotation', 'tab-materials', 'tab-preparation', 'tab-staff'] as $section) {
            $position = strpos($html, 'id="' . $section . '"');
            $this->assertNotFalse($position, $section . ' is missing.');
            $this->assertTrue($position > $shell && $position < $chat, $section . ' must render inside the section pop-up.');
        }

        // Every section tab is still listed; only Overview is not a pop-up.
        $this->assertSame(5, substr_count($html, 'aria-haspopup="dialog"'));
        $this->assertStringContainsString('class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6"', $html);
        $this->assertStringContainsString('class="tab-button group inline-flex min-w-0 items-center gap-2 rounded-lg border', $html);
        $this->assertStringContainsString('Sections open as a pop-up over this overview.', $html);
    }

    public function test_unassigned_staff_tab_has_no_highlight_before_the_booking_needs_staff(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.bookings.show', $this->booking()))
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/data-tab-state="action"[^>]*id="tab-staff-button"/', $html);
    }

    public function test_quotation_table_has_mobile_card_labels_for_all_columns(): void
    {
        $booking = $this->booking();
        $booking->bookingItems()->create([
            'item_name' => 'Test Material',
            'quantity' => 2,
            'quoted_unit_price' => 10,
        ]);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="rf-table--stack w-full min-w-[760px]', $html);
        foreach (['Item Name', 'Qty', 'Unit Cost (₱)', 'Total Cost (₱)', 'Actions'] as $label) {
            $this->assertStringContainsString('data-label="' . $label . '"', $html);
        }
    }

    public function test_event_details_load_the_private_inspiration_image_through_the_secure_route(): void
    {
        $booking = $this->booking();
        $booking->update(['inspiration_image' => 'bookings/inspiration-images/photo.jpg']);

        $this->actingAs($this->admin())
            ->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->assertSee('src="' . route('secure.inspiration.show', $booking->id) . '"', false)
            ->assertDontSee('storage/bookings/inspiration-images/photo.jpg', false);
    }

    public function test_fully_dispatched_booking_points_the_next_step_to_mark_event_in_progress(): void
    {
        [$booking, $item] = $this->dispatchBooking();
        $this->transaction($booking, $item, 'dispatch', -4);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->assertSee('data-next-step-target="operational-actions-heading"', false)
            ->assertSee('Start Event · Mark In Progress')
            ->getContent();

        $this->assertMatchesRegularExpression('/data-tab-state="done"[^>]*id="tab-preparation-button"|id="tab-preparation-button"[^>]*data-tab-state="done"/', $html);
    }

    public function test_partly_dispatched_booking_still_points_to_preparation_and_dispatch(): void
    {
        [$booking, $item] = $this->dispatchBooking();
        $this->transaction($booking, $item, 'dispatch', -1);

        $this->actingAs($this->admin())
            ->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->assertSee('data-next-step-target="tab-preparation"', false)
            ->assertDontSee('Start Event · Mark In Progress');
    }

    /** @return array{0: Booking, 1: \App\Models\InventoryItem} */
    private function dispatchBooking(): array
    {
        $staff = User::create(['name' => 'Staff User', 'email' => 'staff-' . uniqid() . '@example.com', 'password' => bcrypt('password123'), 'role' => 'staff', 'email_verified_at' => now()]);
        $booking = $this->booking();
        $client = \App\Models\Client::create(['full_name' => 'Client User', 'email' => 'client-' . uniqid() . '@example.com', 'phone' => '09171234567']);
        $booking->forceFill([
            'status' => 'downpayment_received',
            'confirmed_at' => now(),
            'staff_id' => $staff->id,
            'client_id' => $client->id,
            'event_date' => now()->addDays(30)->toDateString(),
            'preparation_start_date' => now()->toDateString(),
        ])->save();
        $item = \App\Models\InventoryItem::create(['name' => 'Tree Trunk', 'category' => 'props', 'is_perishable' => false, 'current_stock' => 50, 'unit_cost' => 50, 'min_stock' => 1, 'unit' => 'piece']);
        $booking->bookingItems()->create([
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 4,
            'confirmed_at' => now(),
        ]);
        \App\Models\Payment::create([
            'booking_id' => $booking->id,
            'amount' => 100,
            'payment_type' => 'bank_transfer',
            'payment_option' => 'downpayment',
            'reference_number' => 'VERIFIED-' . uniqid(),
            'status' => 'downpayment_received',
            'verified_at' => now(),
        ]);
        $this->transaction($booking, $item, 'booking_lock', -4);

        return [$booking, $item];
    }

    private function transaction(Booking $booking, \App\Models\InventoryItem $item, string $type, float $quantity): void
    {
        \App\Models\InventoryTransaction::create(['booking_id' => $booking->id, 'inventory_item_id' => $item->id, 'transaction_type' => $type, 'quantity_change' => $quantity]);
    }

    private function booking(): Booking
    {
        return Booking::create([
            'event_type' => 'wedding',
            'event_date' => now()->addDays(10)->toDateString(),
            'venue' => 'The Garden Hall',
            'status' => 'pending',
            'total_quoted' => 0,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin User',
            'email' => 'admin-' . uniqid() . '@example.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);
    }
}
