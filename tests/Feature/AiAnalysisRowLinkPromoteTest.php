<?php

namespace Tests\Feature;

use App\Models\AiAnalysisResult;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The quote editor also lists AI suggestions straight from the booking's analysis that have no
 * booking line yet. Link and Promote work on those rows too: the line is created (only for a
 * name the analysis really suggested) and linked, never a duplicate catalog item.
 */
class AiAnalysisRowLinkPromoteTest extends TestCase
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
        $this->pinkRose = InventoryItem::create(['name' => 'Pink Rose', 'category' => 'Fresh Flowers', 'is_perishable' => true, 'current_stock' => 500, 'unit_cost' => 85, 'min_stock' => 0, 'unit' => 'stems']);
        AiAnalysisResult::create([
            'booking_id' => $this->booking->id,
            'raw_gemini_response' => 'mocked',
            'suggested_materials' => [
                ['item_name' => 'Pink Rose', 'quantity' => 98, 'estimated_unit_cost_php' => 90],
                ['item_name' => 'Butterfly Accents', 'quantity' => 12],
            ],
            'analyzed_at' => now(),
        ]);
    }

    public function test_the_editor_sends_the_ai_name_for_rows_without_a_line_and_prefills_promote(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.bookings.show', $this->booking))->assertOk()->getContent();

        $this->assertStringContainsString('name="ai_item_name" value="Butterfly Accents"', $html);
        $this->assertStringContainsString("const itemName = document.getElementById(`display_name_\${index}`)?.textContent.trim() || '';", $html);
        $this->assertStringContainsString("document.getElementById('aiMaterialCatalogName').value = itemName;", $html);
    }

    public function test_link_uses_a_name_search_that_starts_from_the_items_name(): void
    {
        $archived = InventoryItem::create(['name' => 'Old Vase', 'category' => 'Props', 'is_perishable' => false, 'current_stock' => 1, 'unit_cost' => 1, 'min_stock' => 0, 'unit' => 'pcs']);
        $archived->delete();

        $html = $this->actingAs($this->admin)->get(route('admin.bookings.show', $this->booking))->assertOk()->getContent();

        $this->assertStringContainsString('id="aiMaterialInventorySearch"', $html);
        $this->assertStringNotContainsString('<select id="aiMaterialInventorySelect"', $html);
        $this->assertStringContainsString("document.getElementById('aiMaterialInventorySearch').value = itemName;", $html);

        // The searchable list holds active inventory only.
        preg_match('/<script type="application\/json" id="aiMaterialInventoryData">(.*?)<\/script>/s', $html, $match);
        $names = array_column(json_decode($match[1], true), 'name');
        $this->assertContains('Pink Rose', $names);
        $this->assertNotContains('Old Vase', $names);
    }

    public function test_link_creates_the_line_for_an_analysis_row_and_links_it(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.link', $this->booking), ['ai_item_name' => 'Pink Rose', 'quantity' => 100, 'unit_price' => '85', 'link_inventory_item_id' => $this->pinkRose->id])
            ->assertSessionHas('success');

        $line = BookingItem::where('booking_id', $this->booking->id)->sole();
        $this->assertSame($this->pinkRose->id, (int) $line->inventory_item_id);
        $this->assertSame([100.0, 85.0], [(float) $line->quantity, (float) $line->quoted_unit_price]);
        $this->assertTrue((bool) $line->is_ai_suggested);
        $this->assertNull($line->confirmed_at);
    }

    public function test_linking_a_variant_hides_the_unmatched_analysis_row_and_combines_linked_quantities(): void
    {
        $analysis = $this->booking->aiAnalyses()->firstOrFail();
        $analysis->update(['suggested_materials' => [
            ['item_name' => 'Pink Roses', 'quantity' => 40, 'estimated_unit_cost_php' => 90],
        ]]);

        $existingLine = BookingItem::create([
            'booking_id' => $this->booking->id,
            'inventory_item_id' => $this->pinkRose->id,
            'item_name' => $this->pinkRose->name,
            'quantity' => 40,
            'quoted_unit_price' => 80,
            'is_ai_suggested' => true,
            'procurement_status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.link', $this->booking), [
                'ai_item_name' => 'Pink Roses',
                'quantity' => 40,
                'unit_price' => 80,
                'link_inventory_item_id' => $this->pinkRose->id,
            ])
            ->assertSessionHas('success');

        $html = $this->actingAs($this->admin)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Combined 2 lines', $html);
        $this->assertMatchesRegularExpression('/id="input_qty_0"[^>]*name="items\[0\]\[quantity\]"[^>]*value="80"/', $html);
        $this->assertStringNotContainsString('Unmatched / AI Suggestion', $html);
        $this->assertSame(2, BookingItem::where('booking_id', $this->booking->id)->count());
        $this->assertSame(80.0, (float) BookingItem::where('booking_id', $this->booking->id)->sum('quantity'));
        $this->assertSame($this->pinkRose->id, (int) $existingLine->fresh()->inventory_item_id);
    }

    public function test_promote_on_an_analysis_row_with_an_existing_name_links_instead_of_duplicating(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.promote', $this->booking), ['ai_item_name' => 'Pink Rose', 'catalog_name' => 'pink rose', 'quantity' => 98, 'unit_price' => '0'])
            ->assertSessionHas('success', '"Pink Rose" is already in inventory, so this material was linked to it instead of adding a duplicate.');

        $this->assertSame($this->pinkRose->id, (int) BookingItem::where('booking_id', $this->booking->id)->sole()->inventory_item_id);
        $this->assertSame(1, InventoryItem::withTrashed()->count());
    }

    public function test_promote_on_an_analysis_row_with_a_new_name_adds_it_once(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.promote', $this->booking), ['ai_item_name' => 'Butterfly Accents', 'catalog_name' => 'Butterfly Accents', 'category' => 'Props', 'quantity' => 12])
            ->assertSessionHas('success');

        $item = InventoryItem::where('name', 'Butterfly Accents')->sole();
        $line = BookingItem::where('booking_id', $this->booking->id)->sole();
        $this->assertSame($item->id, (int) $line->inventory_item_id);
        $this->assertSame(12.0, (float) $line->quantity);
        $this->assertSame(0.0, (float) $item->current_stock);
    }

    public function test_names_the_analysis_did_not_suggest_and_refused_promotes_create_nothing(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.link', $this->booking), ['ai_item_name' => 'Gold Chandelier', 'link_inventory_item_id' => $this->pinkRose->id])
            ->assertSessionHas('error', 'AI suggested item could not be located.');
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.promote', $this->booking), ['ai_item_name' => 'Gold Chandelier', 'catalog_name' => 'Gold Chandelier'])
            ->assertSessionHas('error', 'That material is not part of this booking.');

        // A refused promote (singular/plural twin of an existing item) leaves no stray line behind.
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.ai.promote', $this->booking), ['ai_item_name' => 'Pink Rose', 'catalog_name' => 'Pink Roses'])
            ->assertSessionHas('error');

        $this->assertSame(0, BookingItem::where('booking_id', $this->booking->id)->count());
        $this->assertSame(1, InventoryItem::withTrashed()->count());
    }
}
