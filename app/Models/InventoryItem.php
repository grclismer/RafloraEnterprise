<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use SoftDeletes;
    protected static function booted(): void
    {
        static::updated(function (InventoryItem $inventoryItem): void {
            if ($inventoryItem->wasChanged('current_stock')) {
                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'inventory_updated',
                    'module' => 'inventory',
                    'details' => 'Inventory stock updated to ' . $inventoryItem->current_stock,
                    'entity_type' => InventoryItem::class,
                    'entity_id' => $inventoryItem->id,
                ]);
            }
        });
    }
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'item_code',
        'image_path',
        'name',
        'category',
        'is_perishable',
        'current_stock',
        'unit_cost',
        'min_stock',
        'unit',
        'status',
        'description',
        'usable_life_value',
        'usable_life_unit',
        'supplier_name',
        'supplier_contact_person',
        'supplier_contact_number',
        'storage_location',
        'tags',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_perishable' => 'boolean',
            'current_stock' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'min_stock' => 'decimal:2',
            'usable_life_value' => 'integer',
        ];
    }

    /**
     * Get all inventory transactions for this item.
     */
    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'inventory_item_id', 'id');
    }

    /**
     * Get all return items for this inventory item.
     */
    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'inventory_item_id', 'id');
    }

    /**
     * Get all bookings that include this inventory item (through booking_items pivot).
     */
    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(
            Booking::class,
            'booking_items',
            'inventory_item_id',
            'booking_id'
        )->withPivot(['quantity', 'quoted_unit_price', 'is_ai_suggested', 'procurement_status', 
                      'suggested_order_date', 'suggested_delivery_date', 'notes']);
    }

    /**
     * Get all returns that include this inventory item (through return_items pivot).
     */
    public function returns(): BelongsToMany
    {
        return $this->belongsToMany(
            AssetReturn::class,
            'return_items',
            'inventory_item_id',
            'return_id'
        )->withPivot(['quantity_returned', 'quantity_good', 'quantity_damaged', 'quantity_lost', 'condition', 'final_amount', 'damage_charge', 'notes']);
    }

    /**
     * Check if inventory is low (below minimum stock) based on operational availability: stock
     * not held for confirmed bookings (net_available, the ledger) at or below the minimum.
     * Admin screens use scopeBelowMinimum() (on hand) or the Inventory page's booked-demand view.
     */
    public function isLowStock(): bool
    {
        return $this->net_available <= $this->min_stock;
    }

    public function getReservedStockAttribute($value = null): float
    {
        if ($value !== null) {
            return (float) $value;
        }
        if (array_key_exists('reserved_stock', $this->attributes)) {
            return (float) $this->attributes['reserved_stock'];
        }

        // P0-A/B: Calculate via ledger using Booking-Item level aggregation
        $transactions = $this->inventoryTransactions()
            ->whereNotNull('booking_id')
            ->get()
            ->groupBy('booking_id');

        $totalReserved = 0.0;

        foreach ($transactions as $bookingId => $txs) {
            $totalReserved += $this->netReservationFromLedger($txs, (int) $bookingId);
        }

        return $totalReserved;
    }

    /**
     * Stock still held for one booking: locks − releases − net dispatch (dispatched units have
     * already left current_stock, so they are not held twice). The same rule as reserved_stock.
     */
    public function reservedForBooking(int $bookingId): float
    {
        return $this->netReservationFromLedger(
            $this->inventoryTransactions()->where('booking_id', $bookingId)->get(),
            $bookingId
        );
    }

    private function netReservationFromLedger(\Illuminate\Support\Collection $txs, int $bookingId): float
    {
        $lock = $txs->where('transaction_type', 'booking_lock')->sum(fn ($tx) => abs((float) $tx->quantity_change));
        // Releases are recorded as positive quantities.
        $release = $txs->where('transaction_type', 'booking_release')->sum('quantity_change');
        // Dispatches are negative and corrections positive, so their sum is the net dispatch (negative).
        $netDispatch = abs($txs->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])->sum('quantity_change'));

        $netReservation = $lock - $release - $netDispatch;

        if (round($netReservation, 4) < 0) {
            \Illuminate\Support\Facades\Log::error(sprintf(
                'Inconsistent ledger state for Item %d, Booking %d. Net reservation cannot be negative (Lock: %f, Release: %f, Dispatch: %f).',
                $this->id, $bookingId, $lock, $release, $netDispatch
            ));
            // An excess release or over-dispatch means the booking's physical obligation is exhausted.
            // We floor it at 0 to prevent phantom reservations from double-deducting available stock.
            return 0.0;
        }

        return (float) $netReservation;
    }

    /**
     * Booked demand, shown as "Reserved" on the Inventory page and the basis of "To Procure":
     * quantities on upcoming, still-active bookings that Admin confirmed (or that are not AI
     * suggestions), including fresh flowers that are bought rather than held. This is a planning
     * figure; stock actually held for confirmed bookings is reserved_stock (the ledger).
     */
    public function scopeWithBookedDemand($query)
    {
        return $query->withSum(['bookings as reserved_stock' => function ($q) {
            $q->whereDate('bookings.event_date', '>=', \Carbon\Carbon::today())
                ->whereNotIn('bookings.status', ['cancelled', 'completed', 'declined'])
                ->where(fn ($inner) => $inner->whereNotNull('booking_items.confirmed_at')->orWhere('booking_items.is_ai_suggested', false));
        }], 'booking_items.quantity');
    }

    /**
     * Net quantity already dispatched to one booking (dispatches less corrections). Those units
     * have left current_stock and are no longer part of reservedForBooking().
     */
    public function dispatchedForBooking(int $bookingId): float
    {
        return abs((float) $this->inventoryTransactions()
            ->where('booking_id', $bookingId)
            ->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])
            ->sum('quantity_change'));
    }

    /**
     * On-hand low stock: physical stock at or below the configured minimum (reorder point),
     * regardless of bookings. Used by the Dashboard and Reports. The Inventory page's status
     * instead compares stock left after booked demand with the minimum (a planning view).
     */
    public function scopeBelowMinimum($query)
    {
        return $query->whereColumn('current_stock', '<=', 'min_stock');
    }

    /**
     * Active catalog items whose name equals $name ignoring case and surrounding spaces.
     */
    public function scopeNamed($query, string $name)
    {
        return $query->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))]);
    }

    /**
     * A catalog name in comparable form: case, spacing and punctuation are ignored, so
     * "Stage / Backdrop", "stage backdrop" and "STAGE-BACKDROP" are the same name.
     */
    public static function nameKey(?string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $name)) ?? '');
    }

    /**
     * nameKey() with simple plurals made singular word by word ("Pink Roses" → "pink rose",
     * "Lilies" → "lily", "Boxes" → "box"); words ending in ss/us/is ("Glass") are left alone.
     */
    public static function singularNameKey(?string $name): string
    {
        $words = explode(' ', self::nameKey($name));

        return implode(' ', array_map(function (string $word): string {
            if (strlen($word) <= 3 || preg_match('/(ss|us|is)$/', $word)) {
                return $word;
            }

            return preg_replace(['/ies$/', '/(sh|ch|x|z)es$/', '/s$/'], ['y', '$1', ''], $word, 1);
        }, $words));
    }

    /**
     * nameKey() with its words sorted, so word order is ignored: "Entrance Arch Floral Arrangement"
     * and "Entrance Floral Arch Arrangement" are the same name. Different words stay different.
     */
    public static function wordKey(?string $name): string
    {
        $words = array_filter(explode(' ', self::nameKey($name)), 'strlen');
        sort($words);

        return implode(' ', $words);
    }

    /**
     * singularNameKey() with its words sorted: ignores word order and singular/plural.
     */
    public static function singularWordKey(?string $name): string
    {
        $words = array_filter(explode(' ', self::singularNameKey($name)), 'strlen');
        sort($words);

        return implode(' ', $words);
    }

    /**
     * Known quantity and form variants that represent the same catalog material.
     * Keep this list narrow: different floral-foam shapes and finished arrangements
     * must remain distinct inventory items.
     */
    public static function materialIdentityKey(?string $name): string
    {
        $key = self::singularNameKey($name);

        if (preg_match('/^(?:\d+\s+)?floral foam(?:\s+(?:block|brick|oasis))?$/', $key)) {
            return 'floral foam';
        }

        if (preg_match('/^(?:\d+\s+)?(white|red)\s+rose(?:\s+centerpiece)?$/', $key, $matches)) {
            return $matches[1] . ' rose';
        }

        return '';
    }

    /**
     * Likely spelling and word-order variants that need a human review before creating a new item.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function catalogSimilarityCandidates(string $name, ?int $ignoreId = null): \Illuminate\Support\Collection
    {
        $candidateKey = self::singularNameKey($name);
        $candidateWords = array_values(array_filter(explode(' ', $candidateKey)));
        if ($candidateKey === '' || $candidateWords === []) {
            return collect();
        }

        return self::withTrashed()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get()
            ->map(function (self $item) use ($candidateKey, $candidateWords): array {
                $itemKey = self::singularNameKey($item->name);
                $itemWords = array_values(array_filter(explode(' ', $itemKey)));
                $sharedWords = count(array_intersect($candidateWords, $itemWords));
                $wordOverlap = $sharedWords / max(count($candidateWords), count($itemWords));
                $candidateSorted = $candidateWords;
                $itemSorted = $itemWords;
                sort($candidateSorted);
                sort($itemSorted);
                $sortedKey = implode(' ', $candidateSorted);
                $sortedItemKey = implode(' ', $itemSorted);
                $distance = levenshtein($sortedKey, $sortedItemKey);
                $similarity = 1 - ($distance / max(strlen($sortedKey), strlen($sortedItemKey), 1));

                return ['item' => $item, 'score' => max($wordOverlap, $similarity)];
            })
            ->filter(fn (array $match): bool => $match['score'] >= 0.68)
            ->sortByDesc('score')
            ->take(5)
            ->pluck('item')
            ->values();
    }

    /**
     * Existing catalog items (archived included) that a new item called $name would duplicate.
     * Known quantity/form variants are matched to their canonical product, but are returned as
     * 'similar' rather than 'exact' when a supplied unit conflicts with the canonical item's unit.
     *
     * @return array{exact: ?self, similar: ?self}
     */
    public static function catalogMatches(string $name, ?int $ignoreId = null, ?string $unit = null): array
    {
        $key = self::wordKey($name);
        $singular = self::singularWordKey($name);
        if ($key === '') {
            return ['exact' => null, 'similar' => null];
        }

        $candidates = self::withTrashed()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get(['id', 'name', 'item_code', 'unit', 'deleted_at'])
            ->sortBy(fn (self $item) => $item->deleted_at ? 1 : 0)
            ->values();

        $identityKey = self::materialIdentityKey($name);
        if ($identityKey !== '') {
            $identityCandidates = $candidates->filter(
                fn (self $item) => self::materialIdentityKey($item->name) === $identityKey
            );
            $canonical = $identityCandidates->first(
                fn (self $item) => self::singularNameKey($item->name) === $identityKey
            );
            $match = $canonical ?? $identityCandidates->first();
            $isVariantName = self::singularNameKey($name) !== $identityKey;

            if ($match) {
                if ($isVariantName && $unit !== null && !self::unitsMatch($match->unit, $unit)) {
                    return ['exact' => null, 'similar' => $match];
                }

                return ['exact' => $match, 'similar' => null];
            }
        }

        $exact = $candidates->first(fn (self $item) => self::wordKey($item->name) === $key);
        return [
            'exact' => $exact,
            'similar' => $candidates->first(fn (self $item) => self::singularWordKey($item->name) === $singular),
        ];
    }

    private static function unitsMatch(?string $first, ?string $second): bool
    {
        $normalize = static function (?string $unit): string {
            $key = self::singularNameKey($unit);

            return in_array($key, ['pc', 'pcs', 'piece', 'unit'], true) ? 'piece' : $key;
        };

        return $normalize($first) === $normalize($second);
    }

    public function getAvailableStockAttribute(): float
    {
        return $this->net_available;
    }

    public function getReorderLevelAttribute(): float
    {
        return (float) ($this->min_stock ?? 0);
    }

    public function getNetAvailableAttribute(): float
    {
        $netAvailable = (float) $this->current_stock - (float) $this->reserved_stock;
        
        // Removed max(0.0, ...) to ensure negative availability is visible if overbooked
        return $netAvailable;
    }

    /**
     * Dynamically derive required procurement quantity to cover current reserved event demand:
     * TO_PROCURE = max(0, RESERVED - ON_HAND)
     */
    public function getToProcureAttribute(): float
    {
        return max(0.0, (float) ($this->reserved_stock ?? 0) - (float) $this->current_stock);
    }

    /**
     * Get all packages that include this inventory item.
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'inventory_item_package')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    /**
     * Get all substitutes for this inventory item.
     */
    public function substitutes(): BelongsToMany
    {
        return $this->belongsToMany(
            InventoryItem::class,
            'inventory_item_substitutes',
            'item_id',
            'substitute_id'
        );
    }

    /**
     * Get all stock / procurement batch records for this item.
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'inventory_item_id');
    }

    /**
     * Get all gallery images for this inventory item.
     */
    public function images(): HasMany
    {
        return $this->hasMany(InventoryItemImage::class, 'inventory_item_id');
    }

    /**
     * Get the latest stock / procurement batch.
     */
    public function latestStock()
    {
        return $this->hasOne(InventoryStock::class, 'inventory_item_id')->latestOfMany('received_date');
    }

    /**
     * Scope query to only active inventory items.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Determine if item is active.
     */
    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    /**
     * Calculate usable stock for a given event date.
     */
    public function getUsableStockForDate($eventDate = null): float
    {
        if (!$eventDate) {
            return (float) $this->current_stock;
        }

        $targetDate = \Carbon\Carbon::parse($eventDate)->startOfDay();

        if ($this->stocks()->exists()) {
            return (float) $this->stocks()
                ->whereDate('usable_until', '>=', $targetDate)
                ->sum('quantity_remaining');
        }

        return (float) $this->current_stock;
    }
}
