<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingItem extends Model
{
    protected $table = 'booking_items';

    protected $fillable = [
        'booking_id',
        'inventory_item_id',
        'item_name',
        'quantity',
        'quoted_unit_price',
        'ai_recommended_price',
        'is_ai_suggested',
        'confirmed_at',
        'procurement_status',
        'fresh_procurement_override',
        'suggested_order_date',
        'suggested_delivery_date',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'quoted_unit_price' => 'decimal:2',
        'ai_recommended_price' => 'decimal:2',
        'is_ai_suggested' => 'boolean',
        'fresh_procurement_override' => 'boolean',
        'confirmed_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id', 'id');
    }

    public function requiresFreshProcurement(): bool
    {
        return $this->fresh_procurement_override ?? (bool) $this->inventoryItem?->is_perishable;
    }
}
