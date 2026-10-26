<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Booking extends Model
{
    /**
     * Official quotation markup a custom (Smart AI) booking starts with, before Admin adjusts it:
     * the bookings.multiplier column default. Package bookings use 1× because their catalog price
     * is final. Separate from the AI estimate markup (Setting::KEY_AI_ESTIMATE_MARKUP).
     */
    public const DEFAULT_QUOTATION_MARKUP = 3.0;

    public const DELIVERY_EVENT_TYPE = 'delivery';

    protected static function booted(): void
    {
        static::creating(function (Booking $booking): void {
            if (is_null($booking->client_id) && empty($booking->guest_access_token)) {
                $booking->guest_access_token = (string) Str::uuid();
            }
        });

        static::created(function (Booking $booking): void {
            if (is_null($booking->client_id) && $booking->guest_email) {
                // Initial confirmation
                try {
                    \Illuminate\Support\Facades\Mail::to($booking->guest_email)
                        ->send(new \App\Mail\GuestBookingNotificationMail($booking, 'confirmation'));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to send guest booking confirmation email: ' . $e->getMessage());
                }
            }
        });

        static::updated(function (Booking $booking): void {
            $statusChanged = $booking->wasChanged('status');
            $quoteChanged = $booking->wasChanged('final_quoted_price') || $booking->wasChanged('total_quoted');

            // Single authoritative status-change audit entry for every path (Admin, Client, Staff, system).
            if ($statusChanged) {
                $previous = (new Booking())->forceFill(['status' => $booking->getOriginal('status')])->status_display_label;
                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'status_changed',
                    'module' => 'booking',
                    'event_type' => 'status_changed',
                    'details' => 'Booking status changed from ' . $previous . ' to ' . $booking->status_display_label,
                    'old_values' => ['status' => $booking->getOriginal('status')],
                    'new_values' => ['status' => $booking->status],
                    'entity_type' => Booking::class,
                    'entity_id' => $booking->id,
                ]);

                // Registered clients hear about every status change in the portal and by email.
                // Guests are covered by GuestBookingNotificationMail below.
                if (! is_null($booking->client_id)) {
                    $changedAt = now();
                    DB::afterCommit(function () use ($booking, $changedAt): void {
                        \App\Services\ClientStatusNotifier::statusChanged($booking->fresh() ?? $booking, $changedAt);
                    });
                }
            }

            if ($quoteChanged) {
                AuditLog::create([
                    'user_id' => Auth::id(),
                    'action' => 'quote_updated',
                    'module' => 'booking',
                    'details' => 'Booking quote updated to ' . ($booking->final_quoted_price ?? $booking->total_quoted),
                    'entity_type' => Booking::class,
                    'entity_id' => $booking->id,
                ]);
            }

            // Guests hear about a price only when Admin issues a quotation, the only update that
            // sets price_valid_until (QuotationIssuanceService); a working-price edit sends nothing.
            // Sent after commit so the email reads the quotation issued in the same transaction.
            $quotationIssued = $booking->wasChanged('price_valid_until');

            if (is_null($booking->client_id) && $booking->guest_email && ($statusChanged || $quotationIssued)) {
                $context = $statusChanged ? 'status_updated' : 'quote_updated';

                DB::afterCommit(function () use ($booking, $context): void {
                    try {
                        \Illuminate\Support\Facades\Mail::to($booking->guest_email)
                            ->send(new \App\Mail\GuestBookingNotificationMail($booking->fresh() ?? $booking, $context));
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('Failed to send guest booking update email: ' . $e->getMessage());
                    }
                });
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'handled_by',
        'staff_id',
        'event_type',
        'event_date',
        'event_time',
        'end_time',
        'event_size',
        'table_count',
        'setup_type',
        'venue',
        'venue_latitude',
        'venue_longitude',
        'venue_setting',
        'special_requests',
        'design_brief',
        'inspiration_image',
        'status',
        'pre_cancellation_status',
        'confirmed_at',
        'downpayment_amount',
        'downpayment_date',
        'total_quoted',
        'price_valid_until',
        'suggested_procurement_date',
        'preparation_start_date',
        'preparation_status',
        'cancellation_reason',
        'admin_notes',
        'raw_materials_sum',
        'multiplier',
        'final_quoted_price',
        // Guest booking fields
        'guest_name',
        'guest_email',
        'guest_phone',
        'guest_address',
        'guest_access_token',
        // AI analysis JSON cache
        'ai_analysis_data',
        'analysis_data',
        'package_id',
        'labor_method',
        'labor_rate',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'event_date' => 'date',
        'event_time' => 'string',
        'table_count' => 'integer',
        'downpayment_date' => 'date',
        'price_valid_until' => 'date',
        'suggested_procurement_date' => 'date',
        'preparation_start_date' => 'date',
        'confirmed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'delivery_received_at' => 'datetime',
        'downpayment_amount' => 'decimal:2',
        'total_quoted' => 'decimal:2',
        'ai_analysis_data' => 'json',
        'analysis_data' => 'json',
        'venue_latitude' => 'float',
        'venue_longitude' => 'float',
        'design_brief' => 'array',
    ];

    /**
     * Whether the client pinned the venue with the location finder.
     */
    public function hasVenueCoordinates(): bool
    {
        return $this->venue_latitude !== null && $this->venue_longitude !== null;
    }

    public function isInPreparationPeriod(): bool
    {
        if ($this->preparation_status === 'in_preparation') {
            return true;
        }

        if ($this->preparation_start_date) {
            return $this->preparation_start_date->isPast() || $this->preparation_start_date->isToday();
        }

        return false;
    }

    public function getPreparationStatusDisplayLabelAttribute(): string
    {
        return match ($this->preparation_status) {
            'in_preparation' => 'In Preparation',
            'ready' => 'Ready for Event',
            'cancelled' => 'Cancelled',
            default => 'Scheduled',
        };
    }

    public function areFreshFlowersReady(): bool
    {
        $perishableItems = $this->bookingItems()
            ->with('inventoryItem')
            ->whereNotNull('confirmed_at')
            ->where('quantity', '>', 0)
            ->get()
            ->filter(fn (BookingItem $item) => $item->requiresFreshProcurement());

        if ($perishableItems->isEmpty()) {
            return true;
        }

        return $perishableItems->every(fn ($item) => in_array($item->procurement_status, ['confirmed', 'procured', 'ready'], true));
    }

    /**
     * Delivery orders (event type "delivery") are bought, not rented: nothing comes back, so
     * there is no return audit and the booking closes once the client has the items.
     */
    public function isDelivery(): bool
    {
        return strtolower(trim((string) $this->event_type)) === self::DELIVERY_EVENT_TYPE;
    }

    public function isDeliveryReceived(): bool
    {
        return $this->isDelivery() && !is_null($this->delivery_received_at);
    }

    public function hasReusableMaterials(): bool
    {
        return $this->bookingItems()
            ->with('inventoryItem')
            ->whereNotNull('confirmed_at')
            ->where('quantity', '>', 0)
            ->get()
            ->contains(fn (BookingItem $item) => $item->inventory_item_id && !$item->requiresFreshProcurement());
    }

    public function freshProcurementItems()
    {
        return $this->bookingItems()
            ->with('inventoryItem')
            ->whereNotNull('confirmed_at')
            ->where('quantity', '>', 0)
            ->get()
            ->filter(fn (BookingItem $item) => $item->requiresFreshProcurement())
            ->values();
    }

    public function reusableBookingItems()
    {
        return $this->bookingItems()
            ->with('inventoryItem')
            ->whereNotNull('confirmed_at')
            ->where('quantity', '>', 0)
            ->get()
            ->filter(fn (BookingItem $item) => $item->inventory_item_id && !$item->requiresFreshProcurement())
            ->values();
    }

    public function hasDispatchedReusableMaterials(): bool
    {
        $reusableInventoryIds = $this->bookingItems()
            ->with('inventoryItem')
            ->get()
            ->filter(fn (BookingItem $item) => $item->inventory_item_id && !$item->requiresFreshProcurement())
            ->pluck('inventory_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        if ($reusableInventoryIds->isEmpty()) {
            return false;
        }

        $netDispatch = \App\Models\InventoryTransaction::where('booking_id', $this->id)
            ->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])
            ->whereIn('inventory_item_id', $reusableInventoryIds)
            ->sum('quantity_change');

        return abs((float) $netDispatch) > 0.0;
    }

    public function hasVerifiedPayment(): bool
    {
        return $this->payments()->whereNotNull('verified_at')->exists();
    }

    public function isPreparationDateAllowed(?\DateTimeInterface $date = null, ?\DateTimeInterface $eventDate = null): bool
    {
        $date = $date ? Carbon::parse($date)->startOfDay() : $this->preparation_start_date?->copy()->startOfDay();

        return $date
            && $date->greaterThanOrEqualTo(Carbon::today())
            && $this->isPreparationDateWithinEventWindow($date, $eventDate);
    }

    public function isPreparationDateWithinEventWindow(?\DateTimeInterface $date = null, ?\DateTimeInterface $eventDate = null): bool
    {
        $date = $date ? Carbon::parse($date)->startOfDay() : $this->preparation_start_date?->copy()->startOfDay();
        $eventDate = $eventDate ? Carbon::parse($eventDate)->startOfDay() : $this->event_date?->copy()->startOfDay();

        if (!$date || !$eventDate) {
            return false;
        }

        return $date->lessThanOrEqualTo($eventDate->subDays(14));
    }

    public function hasPreparationStarted(): bool
    {
        return $this->preparation_start_date !== null
            && $this->isPreparationDateWithinEventWindow()
            && $this->preparation_start_date->lessThanOrEqualTo(Carbon::today());
    }

    public function hasValidPreparationSchedule(): bool
    {
        return $this->preparation_start_date !== null
            && $this->isPreparationDateWithinEventWindow();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id', 'id');
    }


    /**
     * Get the client associated with this booking.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    /**
     * Get the staff/admin user handling this booking.
     */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by', 'id');
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id', 'id');
    }

    /**
     * Get the admin who completed the Raflora Review stage.
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'id');
    }

    /**
     * Whether Raflora has completed the review stage for this booking.
     */
    public function isReviewed(): bool
    {
        return !is_null($this->reviewed_at);
    }

    /**
     * Mark the Raflora Review stage as complete (attributes only; caller saves).
     * Returns false when the review was already recorded.
     */
    public function markReviewed(?int $userId): bool
    {
        if ($this->isReviewed()) {
            return false;
        }

        $this->reviewed_at = now();
        $this->reviewed_by = $userId;

        return true;
    }

    /**
     * Get all quotations for this booking.
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'booking_id', 'id');
    }

    /**
     * Get the currently active issued quotation for this booking.
     * Returns the highest-versioned quotation with status='issued'.
     */
    public function activeQuotation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Quotation::class, 'booking_id', 'id')
            ->where('status', Quotation::STATUS_ISSUED)
            ->latestOfMany('version');
    }

    /**
     * Get the quotation that was accepted by the client.
     * Returns the highest-versioned quotation with status='accepted'.
     */
    public function acceptedQuotation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Quotation::class, 'booking_id', 'id')
            ->where('status', Quotation::STATUS_ACCEPTED)
            ->latestOfMany('version');
    }

    /**
     * The quotation Raflora has shared with the client: the accepted one, else the issued one.
     * Null until Admin issues a quotation. Guests and clients see Raflora prices only from here;
     * the booking's own price fields are Admin's working figures.
     */
    public function sharedQuotation(): ?Quotation
    {
        return $this->acceptedQuotation ?? $this->activeQuotation;
    }

    /**
     * What the client owes as Raflora shared it: the shared quotation's price plus any damage
     * charges. Null before a quotation is issued.
     */
    public function getSharedQuoteTotalAttribute(): ?float
    {
        $quotation = $this->sharedQuotation();

        return $quotation ? (float) $quotation->final_quoted_price + $this->damage_charges : null;
    }

    /**
     * The client's balance against the shared quotation; null before a quotation is issued.
     */
    public function getSharedRemainingBalanceAttribute(): ?float
    {
        $total = $this->shared_quote_total;

        return $total === null ? null : max(0.0, $total - $this->total_paid);
    }

    /**
     * Get the current active or accepted quotation that holds tentative pricing.
     */
    public function tentativeQuotation(): ?Quotation
    {
        $candidate = $this->acceptedQuotation ?? $this->activeQuotation;
        if ($candidate && $candidate->is_tentative && is_null($candidate->reconfirmed_at)) {
            return $candidate;
        }

        return $this->quotations()
            ->where('is_tentative', true)
            ->whereNull('reconfirmed_at')
            ->whereIn('status', [Quotation::STATUS_ACCEPTED, Quotation::STATUS_ISSUED])
            ->latest('version')
            ->first();
    }

    /**
     * Check if the booking currently has tentative floral pricing.
     */
    public function hasTentativePricing(): bool
    {
        return !is_null($this->tentativeQuotation());
    }

    /**
     * Check if price reconfirmation is currently due based on the configured threshold.
     */
    public function isPriceReconfirmationDue(): bool
    {
        if (!$this->hasTentativePricing()) {
            return false;
        }

        if (is_null($this->event_date)) {
            return false;
        }

        if (in_array($this->status, ['declined', 'cancelled', 'completed'], true)) {
            return false;
        }

        $thresholdDays = (int) Setting::getSetting('price_reconfirmation_threshold_days', 30);
        $today = \Carbon\Carbon::today();

        return $this->event_date->greaterThanOrEqualTo($today)
            && $this->event_date->lessThanOrEqualTo($today->copy()->addDays($thresholdDays));
    }

    /**
     * Get the quotation history for this booking.
     */
    public function quotationHistory(): HasMany
    {
        return $this->hasMany(QuotationHistory::class, 'booking_id', 'id');
    }

    /**
     * Get all payments for this booking.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'booking_id', 'id');
    }

    /**
     * Get all returns for this booking.
     */
    public function returns(): HasMany
    {
        return $this->hasMany(AssetReturn::class, 'booking_id', 'id');
    }

    /**
     * Get all AI analysis results for this booking.
     */
    public function aiAnalyses(): HasMany
    {
        return $this->hasMany(AiAnalysisResult::class, 'booking_id', 'id');
    }

    /**
     * Get all meetings scheduled for this booking.
     */
    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'booking_id', 'id');
    }

    /**
     * Get all presentations for this booking.
     */
    public function presentations(): HasMany
    {
        return $this->hasMany(Presentation::class, 'booking_id', 'id');
    }

    /**
     * Get all calendar events related to this booking.
     */
    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class, 'related_booking_id', 'id');
    }

    /**
     * Get all inventory transactions for this booking.
     */
    /**
     * The client's "Schedule" change requests (App\Services\ScheduleChangeService).
     */
    public function scheduleRequests(): HasMany
    {
        return $this->hasMany(BookingScheduleRequest::class);
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'booking_id', 'id');
    }

    /**
     * Get all inventory items for this booking (through booking_items pivot).
     */
    public function inventoryItems(): BelongsToMany
    {
        return $this->belongsToMany(
            InventoryItem::class,
            'booking_items',
            'booking_id',
            'inventory_item_id'
        )->withPivot(['quantity', 'quoted_unit_price', 'is_ai_suggested', 'procurement_status', 
                      'suggested_order_date', 'suggested_delivery_date', 'notes']);
    }

    public function bookingItems(): HasMany
    {
        return $this->hasMany(BookingItem::class, 'booking_id', 'id');
    }

    public function staffChecklistItems(): HasMany
    {
        return $this->hasMany(StaffChecklistItem::class, 'booking_id', 'id');
    }

    /**
     * Get all messages for this booking.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(BookingMessage::class, 'booking_id', 'id');
    }

    /**
     * Backward-compatible alias for messages.
     */
    public function bookingMessages(): HasMany
    {
        return $this->messages();
    }

    public static function normalizeStatus(?string $status): ?string
    {
        if ($status === null || trim((string) $status) === '') {
            return null;
        }

        $normalized = Str::slug(trim((string) $status), '_');

        return $normalized === '' ? null : $normalized;
    }

    public function setNormalizedStatus(string $status): self
    {
        $normalized = self::normalizeStatus($status);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Invalid booking status: ' . $status);
        }

        if (in_array($normalized, ['confirmed', 'downpayment_received'], true) && is_null($this->confirmed_at)) {
            $this->confirmed_at = now();
        }

        $this->status = $normalized;

        return $this;
    }

    public function getStatusDisplayLabelAttribute(): string
    {
        return match ($this->status) {
            'downpayment_received' => 'Downpayment Received',
            'payment_submitted' => 'Payment Submitted',
            'payment_pending' => 'Payment Pending',
            'quotation_sent' => 'Quotation Sent',
            'completed' => 'Completed',
            'confirmed' => 'Confirmed',
            'event_in_progress' => 'Event In Progress',
            'event_completed' => 'Event Completed',
            'pending_return' => 'Pending Return',
            'pending_resolution' => 'Pending Resolution',
            'declined' => 'Declined',
            'cancelled' => 'Cancelled',
            'cancellation_requested' => 'Cancellation Requested',
            'change_requested' => 'Change Requested',
            default => Str::title(str_replace('_', ' ', $this->status ?? 'pending')),
        };
    }

    public function getClientStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'approved' => 'Quotation Accepted',
            'downpayment_received' => 'Downpayment Received',
            'payment_submitted' => 'Payment Submitted',
            'payment_pending' => 'Payment Pending',
            'quotation_sent' => 'Quotation Sent',
            'completed' => 'Completed',
            'declined' => 'Declined',
            default => $this->status_display_label,
        };
    }

    public static function parseUpdateMessage(?string $message): array
    {
        $result = [
            'custom_note' => '',
            'items' => [],
            'removed_items' => [],
            'price_changes' => [],
        ];

        if (empty($message)) {
            return $result;
        }

        $lines = preg_split('/\r\n|\n/', trim((string) $message));
        $section = null;
        $customParts = [];

        foreach ($lines as $rawLine) {
            $trimmed = trim((string) $rawLine);
            if ($trimmed === '') {
                if ($section === null && !empty($customParts)) {
                    $customParts[] = '';
                }
                continue;
            }

            if (stripos($trimmed, 'item adjustments:') === 0) {
                $section = 'items';
                continue;
            }

            if (stripos($trimmed, 'removed items:') === 0) {
                $section = 'removed';
                continue;
            }

            if (stripos($trimmed, 'price changes:') === 0) {
                $section = 'price';
                continue;
            }

            if ($section === 'items') {
                $result['items'][] = self::normalizeStructuredItemLine($trimmed);
                continue;
            }

            if ($section === 'removed') {
                $result['removed_items'][] = self::normalizeStructuredItemLine($trimmed, true);
                continue;
            }

            if ($section === 'price') {
                $result['price_changes'][] = self::normalizeStructuredPriceLine($trimmed);
                continue;
            }

            if ($section === null && preg_match('/^(?:•|\-|\*|\d+\.)\s*/', $trimmed) && stripos($trimmed, 'qty:') !== false) {
                $result['items'][] = self::normalizeStructuredItemLine($trimmed);
                continue;
            }

            if ($section === null && preg_match('/^(?:•|\-|\*|\d+\.)\s*/', $trimmed) && stripos($trimmed, 'removed') === 0) {
                $result['removed_items'][] = self::normalizeStructuredItemLine($trimmed, true);
                continue;
            }

            $customParts[] = $trimmed;
        }

        $result['custom_note'] = trim(implode("\n", $customParts));

        $result['items'] = array_values(array_filter($result['items'], fn ($item) => $item !== ''));
        $result['removed_items'] = array_values(array_filter($result['removed_items'], fn ($item) => $item !== ''));
        $result['price_changes'] = array_values(array_filter($result['price_changes'], fn ($item) => $item !== ''));

        return $result;
    }

    protected static function normalizeStructuredItemLine(string $line, bool $forceRemoved = false): string
    {
        $normalized = preg_replace('/^\s*(?:•|\-|\*|\d+\.)\s*/', '', trim($line));
        $normalized = preg_replace('/\s*@\s*(?:₱|P)\s*\$?\d[\d,]*(?:\.\d+)?/i', '', $normalized);
        $normalized = preg_replace('/\s*—\s*/', '  ', $normalized);
        $normalized = preg_replace('/\s{2,}/', '  ', trim((string) $normalized));

        if ($forceRemoved || stripos($normalized, 'removed ') === 0) {
            $normalized = preg_replace('/^removed\s+/i', '', $normalized);
            return '• ' . trim($normalized);
        }

        return '• ' . trim($normalized);
    }

    protected static function normalizeStructuredPriceLine(string $line): string
    {
        $normalized = trim($line);

        if ($normalized === '') {
            return '';
        }

        return '• ' . preg_replace('/^\s*(?:•|\-|\*|\d+\.)\s*/', '', $normalized);
    }

    public function getSetupTagLabelAttribute(): string
    {
        return match ($this->setup_type) {
            'on_site' => 'On-Site Setup',
            'delivery' => 'Delivery / Home Assembly',
            default => 'Delivery / Home Assembly',
        };
    }

    public const VERIFIED_PAYMENT_STATUSES = ['fully_paid', 'downpayment_received'];

    public function getTotalPaidAttribute(): float
    {
        if ($this->relationLoaded('payments')) {
            return (float) $this->payments
                ->whereIn('status', self::VERIFIED_PAYMENT_STATUSES)
                ->sum('amount_paid');
        }

        return (float) $this->payments()
            ->whereIn('status', self::VERIFIED_PAYMENT_STATUSES)
            ->sum('amount_paid');
    }

    public function getDamageChargesAttribute(): float
    {
        if ($this->relationLoaded('returns')) {
            $returns = $this->returns;
        } else {
            $returns = $this->returns()->with('returnItems')->get();
        }

        $hasItems = $returns->contains(fn ($return) => ($return->relationLoaded('returnItems') ? $return->returnItems : $return->returnItems()->get())->isNotEmpty());

        $chargedItemsSum = (float) $returns
            ->flatMap(fn ($return) => $return->relationLoaded('returnItems') ? $return->returnItems : $return->returnItems()->get())
            ->where('charge_decision', 'charge')
            ->sum('damage_charge');

        return $hasItems
            ? $chargedItemsSum
            : (float) $returns->sum('total_damage_charge');
    }

    public function getTotalObligationAttribute(): float
    {
        $quoteTotal = ((float) $this->final_quoted_price) > 0
            ? (float) $this->final_quoted_price
            : ((float) ($this->total_quoted ?? 0.0));

        return $quoteTotal + $this->damage_charges;
    }

    public function getRemainingBalanceAttribute(): float
    {
        return max(0.0, $this->total_obligation - $this->total_paid);
    }
}
