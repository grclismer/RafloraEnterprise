<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * No path adds a second catalog item for a name that already exists: names are compared
 * ignoring case, spacing and punctuation, and singular/plural, archived items included.
 */
class InventoryDuplicateNamesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Booking $booking;
    private InventoryItem $pinkRose;

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
        $this->pinkRose = $this->item('Pink Rose', 500);
    }

    public function test_name_matching_rules(): void
    {
        $this->assertSame('stage backdrop floral', InventoryItem::nameKey('  Stage / Backdrop  FLORAL '));
        $this->assertSame('pink rose', InventoryItem::singularNameKey('Pink Roses'));
        $this->assertSame('lily', InventoryItem::singularNameKey('Lilies'));
        $this->assertSame('gift box', InventoryItem::singularNameKey('Gift Boxes'));
        $this->assertSame('glass', InventoryItem::singularNameKey('Glass'));
        $this->assertSame('baby s breath', InventoryItem::singularNameKey("Baby's Breath"));

        $this->assertSame($this->pinkRose->id, InventoryItem::catalogMatches('PINK  ROSE')['exact']?->id);
        $this->assertNull(InventoryItem::catalogMatches('Pink Roses')['exact']);
        $this->assertSame($this->pinkRose->id, InventoryItem::catalogMatches('Pink Roses')['similar']?->id);
        $this->assertNull(InventoryItem::catalogMatches('Pink Rose', $this->pinkRose->id)['exact']);
    }

    public function test_known_material_variants_match_the_canonical_catalog_item(): void
    {
        $foam = $this->item('Floral Foam', 0, 'blocks');
        $this->item('Floral Foam - Brick', 0, 'pcs');
        $this->item('Floral Foam (Oasis)', 0, 'piece');
        $whiteRose = $this->item('White Rose', 0);
        $this->item('White Rose Centerpiece', 0, 'piece');

        $this->assertSame($foam->id, InventoryItem::catalogMatches('4 Floral Foam Blocks')['exact']?->id);
        $this->assertSame($foam->id, InventoryItem::catalogMatches('Floral Foam Bricks')['exact']?->id);
        $this->assertSame($foam->id, InventoryItem::catalogMatches('Floral Foam Oasis')['exact']?->id);
        $this->assertSame($whiteRose->id, InventoryItem::catalogMatches('20 White Roses')['exact']?->id);
        $this->assertSame($whiteRose->id, InventoryItem::catalogMatches('White Rose Centerpiece')['exact']?->id);
        $this->assertNull(InventoryItem::catalogMatches('Floral Foam Brick', unit: 'pcs')['exact']);
        $this->assertSame($foam->id, InventoryItem::catalogMatches('Floral Foam Brick', unit: 'pcs')['similar']?->id);
        $this->assertNull(InventoryItem::catalogMatches('White Rose Centerpiece', unit: 'piece')['exact']);

        $sphere = $this->item('Floral Foam Sphere', 0);
        $this->assertSame($sphere->id, InventoryItem::catalogMatches('Floral Foam Sphere')['exact']?->id);
        $this->assertNull(InventoryItem::catalogMatches('Foam Vase')['exact']);
    }

    public function test_catalog_similarity_candidates_flag_spelling_and_word_order_variants(): void
    {
        $orchid = $this->item('White Phalaenopsis Orchid', 0);
        $this->item('Red Rose', 0);

        $this->assertSame(
            [$orchid->id],
            InventoryItem::catalogSimilarityCandidates('White Phalaenopsis Orchd')->pluck('id')->all()
        );
        $this->assertSame(
            [$orchid->id],
            InventoryItem::catalogSimilarityCandidates('Orchid White Phalaenopsis')->pluck('id')->all()
        );
    }

    public function test_promote_requires_review_before_creating_a_possible_spelling_duplicate(): void
    {
        $existing = $this->item('White Phalaenopsis Orchid', 0);
        $line = $this->unmatchedLine('White Phalaenopsis Orchd');

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.promote', $this->booking), [
                'booking_item_id' => $line->id,
                'catalog_name' => 'White Phalaenopsis Orchd',
                'category' => 'Flowers',
            ])
            ->assertSessionHas('error', 'Possible catalog matches: White Phalaenopsis Orchid. Review these items, link the correct one, or confirm that the new item is genuinely different.');

        $this->assertNull($line->fresh()->inventory_item_id);
        $this->assertSame(2, InventoryItem::withTrashed()->count());

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.promote', $this->booking), [
                'booking_item_id' => $line->id,
                'catalog_name' => 'White Phalaenopsis Orchd',
                'category' => 'Flowers',
                'confirm_distinct_catalog_item' => '1',
            ])
            ->assertSessionHas('success');

        $this->assertNotNull($line->fresh()->inventory_item_id);
        $this->assertSame(3, InventoryItem::withTrashed()->count());
        $this->assertSame('White Phalaenopsis Orchd', $line->fresh()->inventoryItem->name);
        $this->assertDatabaseHas('inventory_items', ['id' => $existing->id, 'name' => 'White Phalaenopsis Orchid']);
    }

    public function test_promote_to_catalog_links_to_the_existing_item_instead_of_adding_a_duplicate(): void
    {
        $line = $this->unmatchedLine('pink rose');

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.promote', $this->booking), ['booking_item_id' => $line->id, 'catalog_name' => 'pink rose', 'category' => 'Flowers'])
            ->assertSessionHas('success', '"Pink Rose" is already in inventory, so this material was linked to it instead of adding a duplicate.');

        $this->assertSame($this->pinkRose->id, (int) $line->fresh()->inventory_item_id);
        $this->assertSame(1, InventoryItem::withTrashed()->count());
    }

    public function test_semantic_variant_with_a_different_unit_requires_manual_link_instead_of_promotion(): void
    {
        $foam = $this->item('Floral Foam', 94, 'blocks');
        $line = $this->unmatchedLine('Floral Foam - Brick');

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.promote', $this->booking), [
                'booking_item_id' => $line->id,
                'catalog_name' => 'Floral Foam Brick',
                'unit' => 'pcs',
            ])
            ->assertSessionHas('error', 'An inventory item named "Floral Foam" already exists. Link the material to it instead of adding a duplicate.');

        $this->assertNull($line->fresh()->inventory_item_id);
        $this->assertSame(2, InventoryItem::withTrashed()->count());
    }

    public function test_add_missing_ai_items_does_not_promote_a_unit_conflict_as_a_new_inventory_item(): void
    {
        $whiteRose = $this->item('White Rose', 1180, 'stems');
        $line = $this->unmatchedLine('20 White Roses');

        $this->actingAs($this->admin)
            ->post(route('admin.notifications.promote-booking-items', $this->booking), ['booking_item_ids' => [$line->id]])
            ->assertSessionHas('error', 'Not added: "20 White Roses" (similar to "White Rose": link it from the booking).');

        $this->assertNull($line->fresh()->inventory_item_id);
        $this->assertSame($whiteRose->id, InventoryItem::where('name', 'White Rose')->value('id'));
        $this->assertSame(2, InventoryItem::withTrashed()->count());
    }

    public function test_notification_bulk_add_skips_possible_spelling_duplicates_for_manual_review(): void
    {
        $existing = $this->item('White Phalaenopsis Orchid', 0);
        $line = $this->unmatchedLine('White Phalaenopsis Orchd');

        $this->actingAs($this->admin)
            ->post(route('admin.notifications.promote-booking-items', $this->booking), ['booking_item_ids' => [$line->id]])
            ->assertSessionHas('error', 'Not added: "White Phalaenopsis Orchd" (possible duplicate of: White Phalaenopsis Orchid; review and link it from the booking).');

        $this->assertNull($line->fresh()->inventory_item_id);
        $this->assertSame($existing->id, InventoryItem::where('name', 'White Phalaenopsis Orchid')->value('id'));
        $this->assertSame(2, InventoryItem::withTrashed()->count());
    }

    public function test_link_existing_allows_admin_to_select_the_canonical_item_explicitly(): void
    {
        $foam = $this->item('Floral Foam', 94, 'blocks');
        $line = $this->unmatchedLine('Floral Foam - Brick');

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.link', $this->booking), [
                'booking_item_id' => $line->id,
                'link_inventory_item_id' => $foam->id,
            ])
            ->assertSessionHas('success', 'AI suggestion linked to master inventory.');

        $this->assertSame($foam->id, (int) $line->fresh()->inventory_item_id);
        $this->assertSame('Floral Foam', $line->fresh()->item_name);
        $this->assertSame(2, InventoryItem::withTrashed()->count());
    }

    public function test_promote_refuses_a_plural_or_archived_twin(): void
    {
        $line = $this->unmatchedLine('Pink Roses');
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.promote', $this->booking), ['booking_item_id' => $line->id, 'catalog_name' => 'Pink Roses'])
            ->assertSessionHas('error', 'A similar inventory item "Pink Rose" already exists. Link the material to it, or use a clearly different name.');

        $archived = $this->item('Tulip', 10);
        $archived->delete();
        $tulipLine = $this->unmatchedLine('Tulip');
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.promote', $this->booking), ['booking_item_id' => $tulipLine->id, 'catalog_name' => 'TULIP'])
            ->assertSessionHas('error', 'An inventory item named "Tulip" is archived. Restore it from Archived Items, then link the material to it.');

        $this->assertNull($line->fresh()->inventory_item_id);
        $this->assertNull($tulipLine->fresh()->inventory_item_id);
        $this->assertSame(2, InventoryItem::withTrashed()->count());
    }

    public function test_add_missing_ai_items_links_existing_names_and_never_duplicates(): void
    {
        $archived = $this->item('Tulip', 10);
        $archived->delete();
        $existing = $this->unmatchedLine('PINK ROSE');
        $newFirst = $this->unmatchedLine('Glass Vase');
        $newAgain = $this->unmatchedLine('glass vase!');
        $plural = $this->unmatchedLine('Pink Roses');
        $archivedTwin = $this->unmatchedLine('tulip');

        $this->actingAs($this->admin)
            ->post(route('admin.notifications.promote-booking-items', $this->booking), ['booking_item_ids' => [$existing->id, $newFirst->id, $newAgain->id, $plural->id, $archivedTwin->id]])
            ->assertSessionHas('success', '1 AI item added to inventory with zero stock. 2 already in inventory and linked instead of added again. Not added: "Pink Roses" (similar to "Pink Rose": link it from the booking); "tulip" ("Tulip" is archived: restore it first).');

        $glass = InventoryItem::where('name', 'Glass Vase')->sole();
        $this->assertSame($this->pinkRose->id, (int) $existing->fresh()->inventory_item_id);
        $this->assertSame($glass->id, (int) $newFirst->fresh()->inventory_item_id);
        $this->assertSame($glass->id, (int) $newAgain->fresh()->inventory_item_id);
        $this->assertNull($plural->fresh()->inventory_item_id);
        $this->assertNull($archivedTwin->fresh()->inventory_item_id);
        $this->assertSame(3, InventoryItem::withTrashed()->count(), 'Only Glass Vase was added.');
        $this->assertSame(0.0, (float) $glass->current_stock);
    }

    public function test_link_to_an_archived_item_is_refused(): void
    {
        $archived = $this->item('Tulip', 10);
        $archived->delete();
        $line = $this->unmatchedLine('Tulip');

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.link', $this->booking), ['booking_item_id' => $line->id, 'link_inventory_item_id' => $archived->id])
            ->assertSessionHasErrors(['link_inventory_item_id' => 'That inventory item is archived or no longer exists. Restore it from Archived Items first.']);

        $this->assertNull($line->fresh()->inventory_item_id);
    }

    public function test_add_item_and_rename_refuse_duplicates(): void
    {
        foreach (['pink rose' => '"Pink Rose" is already in inventory. Open it and use Receive Stock', 'Pink Roses' => 'A similar item "Pink Rose" is already in inventory'] as $name => $message) {
            $this->actingAs($this->admin)
                ->post(route('admin.inventory.store'), $this->itemForm($name))
                ->assertSessionHasErrors('name');
            $this->assertStringContainsString($message, session('errors')->first('name'));
        }
        $this->assertSame(1, InventoryItem::withTrashed()->count());

        $vase = $this->item('Glass Vase', 5);
        $this->actingAs($this->admin)
            ->put(route('admin.inventory.update', $vase), $this->itemForm('PINK ROSE'))
            ->assertSessionHasErrors('name');
        $this->assertSame('Glass Vase', $vase->fresh()->name);

        // Editing an item without changing its name is always allowed.
        $this->actingAs($this->admin)
            ->put(route('admin.inventory.update', $vase), array_merge($this->itemForm('Glass Vase'), ['unit_cost' => 99]))
            ->assertSessionHasNoErrors();
        $this->assertSame(99.0, (float) $vase->fresh()->unit_cost);
    }

    public function test_manual_inventory_add_requires_explicit_review_for_a_spelling_variant(): void
    {
        $existing = $this->item('White Phalaenopsis Orchid', 0);

        $this->actingAs($this->admin)
            ->post(route('admin.inventory.store'), $this->itemForm('White Phalaenopsis Orchd'))
            ->assertSessionHasErrors([
                'name' => 'Possible catalog matches: White Phalaenopsis Orchid. Review these items, then confirm below only if this is a genuinely different item.',
            ]);

        $this->assertSame(2, InventoryItem::withTrashed()->count());

        $this->actingAs($this->admin)
            ->post(route('admin.inventory.store'), array_merge(
                $this->itemForm('White Phalaenopsis Orchd'),
                ['confirm_distinct_catalog_item' => '1']
            ))
            ->assertSessionHas('success');

        $this->assertSame(3, InventoryItem::withTrashed()->count());
        $this->assertDatabaseHas('inventory_items', ['id' => $existing->id, 'name' => 'White Phalaenopsis Orchid']);
    }

    public function test_csv_import_updates_the_same_name_and_stops_on_a_near_duplicate(): void
    {
        $this->import("name,category,is_perishable,current_stock,unit_cost,min_stock,unit\npink rose,Fresh Flowers,1,520,12.50,20,stems\n")
            ->assertSessionHas('success');
        $this->assertSame(1, InventoryItem::withTrashed()->count());
        $this->assertSame(520.0, (float) $this->pinkRose->fresh()->current_stock);

        $this->import("name,category,is_perishable,current_stock,unit_cost,min_stock,unit\nGlass Vase,Props,0,30,150,5,pcs\nPink Roses,Flowers,1,100,12,20,stems\n")
            ->assertSessionHas('error', 'Inventory CSV import failed. No changes were made.')
            ->assertSessionHasErrors(['csv_file' => '"Pink Roses" is too similar to the existing item "Pink Rose". Use the existing name to update it, or a clearly different name.']);
        $this->assertSame(1, InventoryItem::withTrashed()->count(), 'All or nothing: Glass Vase was not added either.');
    }

    private function import(string $csv)
    {
        return $this->actingAs($this->admin)->post(route('admin.inventory.import'), [
            'csv_file' => UploadedFile::fake()->createWithContent('inventory.csv', $csv),
        ]);
    }

    private function itemForm(string $name): array
    {
        return [
            'name' => $name,
            'category' => 'Flowers',
            'unit' => 'stems',
            'unit_cost' => 10,
            'min_stock' => 0,
            'item_type' => 'perishable',
            'received_date' => now()->toDateString(),
            'usable_life_value' => 7,
            'usable_life_unit' => 'days',
        ];
    }

    private function unmatchedLine(string $name): BookingItem
    {
        return BookingItem::create([
            'booking_id' => $this->booking->id,
            'item_name' => $name,
            'quantity' => 5,
            'quoted_unit_price' => 0,
            'is_ai_suggested' => true,
            'procurement_status' => 'pending',
        ]);
    }

    private function item(string $name, float $stock, string $unit = 'stems'): InventoryItem
    {
        return InventoryItem::create(['name' => $name, 'category' => 'Flowers', 'is_perishable' => true, 'current_stock' => $stock, 'unit_cost' => 10, 'min_stock' => 0, 'unit' => $unit]);
    }
}
