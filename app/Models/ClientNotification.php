<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'booking_id',
        'type',
        'title',
        'message',
        'is_read',
    ];

    protected static function booted(): void
    {
        static::created(function (ClientNotification $notification): void {
            \App\Services\ClientStatusNotifier::supersede($notification);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function markAsRead(): void
    {
        $this->forceFill(['is_read' => true])->save();
    }
}
