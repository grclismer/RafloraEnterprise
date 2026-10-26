<?php

namespace App\Services;

use App\Models\AdminAlert;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\BookingMessageRead;
use App\Models\StaffChecklistItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read model for the Staff Workspace.
 *
 * Every number and list on the staff pages comes from here so they share one set of rules:
 *  - An event is "assigned" when bookings.staff_id is the staff member; it is "active" unless
 *    cancelled, declined or completed.
 *  - Tasks are real work on assigned active events: the event checklist items, plus dispatch,
 *    material return and condition inspection work derived from inventory and return records.
 *    Derived tasks complete only when the underlying operation has been recorded; staff cannot
 *    tick them off directly.
 *  - Tasks have no stored deadline or priority. Checklist and dispatch work is due when the event
 *    starts; return and inspection work has no deadline in the data, so none is shown.
 */
class StaffWorkspaceService
{
    public const DEFAULT_CHECKLIST = [
        'review-event-details' => 'Review event details and venue access',
        'prepare-confirmed-materials' => 'Prepare confirmed event materials',
        'confirm-setup-readiness' => 'Confirm setup readiness before the event',
    ];

    public const INACTIVE_STATUSES = ['cancelled', 'declined', 'completed'];

    /** Mirrors the dispatch lock in InventoryDispatchService / Admin booking review. */
    public const DISPATCH_CLOSED_STATUSES = ['event_completed', 'completed', 'cancelled', 'declined', 'rejected', 'pending_return', 'pending_resolution'];

    public const RETURN_PHASE_STATUSES = ['event_completed', 'pending_return', 'pending_resolution'];

    public const POST_EVENT_STATUSES = ['event_in_progress', 'event_completed', 'pending_return', 'pending_resolution'];

    public const TASK_TYPES = [
        'checklist' => 'Checklist',
        'dispatch' => 'Dispatch',
        'return' => 'Material return',
        'inspection' => 'Condition inspection',
    ];

    /** @var array<int, Collection> */
    private array $assignedCache = [];

    /**
     * All bookings assigned to the staff member (active and historical), with the relations every
     * workspace panel needs, loaded once per request.
     */
    public function assignedBookings(User $staff): Collection
    {
        return $this->assignedCache[$staff->id] ??= Booking::query()
            ->where('staff_id', $staff->id)
            ->with([
                'client',
                'package',
                'handledBy',
                'staffChecklistItems.completedBy',
                'bookingItems.inventoryItem',
                'inventoryTransactions.inventoryItem',
                'returns.returnItems',
            ])
            ->orderByRaw('event_date IS NULL, event_date asc')
            ->orderBy('id')
            ->get();
    }

    public function activeBookings(User $staff): Collection
    {
        return $this->assignedBookings($staff)
            ->reject(fn (Booking $b) => in_array($b->status, self::INACTIVE_STATUSES, true))
            ->values();
    }

    public function canDispatch(Booking $booking): bool
    {
        return $booking->confirmed_at !== null
            && $booking->hasVerifiedPayment()
            && $booking->hasValidPreparationSchedule()
            && !in_array($booking->status, self::DISPATCH_CLOSED_STATUSES, true);
    }

    public function dispatchLockMessage(Booking $booking): ?string
    {
        if (!$booking->hasVerifiedPayment()) {
            return 'Dispatch is locked until a down payment or full payment is verified.';
        }

        if (!$booking->hasValidPreparationSchedule()) {
            return 'Dispatch is locked until Admin schedules a valid preparation start date.';
        }

        if ($booking->confirmed_at === null) {
            return 'Dispatch is locked until the booking has a confirmation date.';
        }

        if (in_array($booking->status, self::DISPATCH_CLOSED_STATUSES, true)) {
            return 'Dispatch is unavailable because this booking is closed or in its return phase.';
        }

        return null;
    }

    /** Creates the default checklist for an assigned event (idempotent: unique booking_id + key). */
    public function ensureChecklist(Booking $booking): void
    {
        foreach (self::DEFAULT_CHECKLIST as $key => $title) {
            StaffChecklistItem::firstOrCreate(
                ['booking_id' => $booking->id, 'key' => $key],
                ['title' => $title]
            );
        }
    }

    /** Initializes checklists for active assignments so task counts include every event. */
    public function ensureChecklists(User $staff): void
    {
        $missing = $this->activeBookings($staff)->filter(fn (Booking $b) => $b->staffChecklistItems->count() < count(self::DEFAULT_CHECKLIST));
        if ($missing->isEmpty()) {
            return;
        }

        $missing->each(fn (Booking $b) => $this->ensureChecklist($b));
        unset($this->assignedCache[$staff->id]);
    }

    /** The moment work for an event is due: its start time, or the start of the event day when no time is set. */
    public function eventStart(Booking $booking): ?Carbon
    {
        if (!$booking->event_date) {
            return null;
        }

        $date = Carbon::parse($booking->event_date)->startOfDay();
        if ($booking->event_time) {
            try {
                $time = Carbon::parse($booking->event_time);
                return $date->setTime($time->hour, $time->minute);
            } catch (\Throwable $e) {
                return $date;
            }
        }

        return $date;
    }

    /**
     * Reserved / dispatched / outstanding quantities per inventory item for a booking.
     * outstanding = reserved (lock - release) - net dispatched, the same formula the dispatch service enforces.
     */
    public function dispatchRows(Booking $booking): Collection
    {
        $reusableInventoryIds = $booking->bookingItems
            ->filter(fn ($bookingItem) => $bookingItem->confirmed_at !== null
                && (float) $bookingItem->quantity > 0
                && $bookingItem->inventory_item_id
                && !$bookingItem->requiresFreshProcurement())
            ->pluck('inventory_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $booking->inventoryTransactions
            ->filter(fn ($tx) => $tx->inventoryItem !== null)
            ->groupBy('inventory_item_id')
            ->map(function (Collection $txs) {
                $locked = (float) $txs->where('transaction_type', 'booking_lock')->sum(fn ($t) => abs((float) $t->quantity_change));
                $released = (float) $txs->where('transaction_type', 'booking_release')->sum('quantity_change');
                $dispatched = abs((float) $txs->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])->sum('quantity_change'));
                $reserved = max(0.0, $locked - $released);

                return [
                    'item' => $txs->first()->inventoryItem,
                    'reserved' => $reserved,
                    'dispatched' => $dispatched,
                    'outstanding' => max(0.0, round($reserved - $dispatched, 4)),
                ];
            })
            ->filter(fn ($row) => $reusableInventoryIds->contains((int) $row['item']->id)
                && ($row['reserved'] > 0 || $row['dispatched'] > 0))
            ->values();
    }

    /**
     * Confirmed materials for a booking with an honest readiness state. Nothing here reserves or
     * moves stock; it only reports what the inventory and procurement records say.
     */
    public function materialLines(Booking $booking): Collection
    {
        $dispatch = $this->dispatchRows($booking)->keyBy(fn ($row) => $row['item']->id);

        return $booking->bookingItems
            ->filter(fn ($bi) => $bi->confirmed_at !== null && (float) $bi->quantity > 0 && $bi->inventoryItem !== null)
            ->map(function ($bi) use ($dispatch) {
                $item = $bi->inventoryItem;
                $required = (float) $bi->quantity;

                if ($bi->requiresFreshProcurement()) {
                    [$state, $label] = match ($bi->procurement_status) {
                        'ready' => ['ready', 'Ready'],
                        'confirmed' => ['preparing', 'Ordered'],
                        'out_of_stock' => ['blocked', 'Unavailable'],
                        default => ['pending', 'Pending procurement'],
                    };
                } else {
                    $row = $dispatch->get($item->id);
                    $reserved = (float) ($row['reserved'] ?? 0);
                    $dispatched = (float) ($row['dispatched'] ?? 0);

                    [$state, $label] = match (true) {
                        $dispatched >= $required => ['ready', 'Dispatched'],
                        $reserved >= $required => ['preparing', 'Reserved'],
                        (float) $item->net_available + $reserved < $required => ['blocked', 'Insufficient stock'],
                        default => ['pending', 'Awaiting reservation'],
                    };
                }

                return [
                    'name' => $bi->item_name ?? $item->name,
                    'quantity' => $required,
                    'unit' => $item->unit,
                    'perishable' => $bi->requiresFreshProcurement(),
                    'state' => $state,
                    'label' => $label,
                ];
            })
            ->values();
    }

    /**
     * Every task for the staff member's active assignments.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function tasks(User $staff): Collection
    {
        $now = now();
        $tasks = collect();

        foreach ($this->activeBookings($staff) as $booking) {
            $due = $this->eventStart($booking);
            $eventUrl = route('staff.events.show', $booking);

            foreach ($booking->staffChecklistItems->sortBy('id') as $item) {
                $tasks->push($this->task([
                    'key' => 'checklist-' . $item->id,
                    'type' => 'checklist',
                    'title' => $item->title,
                    'booking' => $booking,
                    'status' => $item->is_completed ? 'completed' : 'pending',
                    'due_at' => $due,
                    'completed_at' => $item->completed_at,
                    'checklist_item' => $item,
                    'detail' => $item->is_completed && $item->completedBy ? 'Completed by ' . $item->completedBy->name : null,
                    'url' => $eventUrl . '#checklist',
                ], $now));
            }

            $rows = $this->dispatchRows($booking);
            $outstanding = $rows->filter(fn ($row) => $row['outstanding'] > 0);
            $dispatchClosed = in_array($booking->status, self::DISPATCH_CLOSED_STATUSES, true);
            if ($rows->isNotEmpty() && !($dispatchClosed && $outstanding->isNotEmpty())) {
                $fullyDispatched = $outstanding->isEmpty();
                $partly = !$fullyDispatched && $rows->contains(fn ($row) => $row['dispatched'] > 0);
                $dispatchLockMessage = $this->dispatchLockMessage($booking);
                $tasks->push($this->task([
                    'key' => 'dispatch-' . $booking->id,
                    'type' => 'dispatch',
                    'title' => 'Dispatch reserved materials',
                    'booking' => $booking,
                    'status' => $fullyDispatched ? 'completed' : ($partly ? 'in_progress' : 'pending'),
                    'due_at' => $due,
                    'completed_at' => null,
                    'detail' => $fullyDispatched
                        ? 'All reserved materials dispatched'
                        : ($dispatchLockMessage
                            ? $dispatchLockMessage
                            : ($rows->count() - $outstanding->count()) . ' of ' . $rows->count() . ' materials dispatched'),
                    'url' => $eventUrl . '#dispatch',
                ], $now));
            }

            $returnRecord = $booking->returns->sortByDesc('id')->first();
            if (in_array($booking->status, self::RETURN_PHASE_STATUSES, true) && $returnRecord && $returnRecord->returnItems->isNotEmpty()) {
                $returnRecorded = in_array($returnRecord->status, ['Partially Returned', 'Completed'], true);
                $tasks->push($this->task([
                    'key' => 'return-' . $booking->id,
                    'type' => 'return',
                    'title' => 'Record returned material quantities',
                    'booking' => $booking,
                    'status' => $returnRecorded ? 'completed' : 'pending',
                    'due_at' => null,
                    'completed_at' => $returnRecorded ? $returnRecord->return_date : null,
                    'detail' => $returnRecord->returnItems->count() . ' dispatched ' . \Illuminate\Support\Str::plural('material', $returnRecord->returnItems->count()) . ' to account for',
                    'url' => $eventUrl . '#returns',
                ], $now));

                if ($returnRecorded) {
                    $pendingConditions = $returnRecord->returnItems->where('condition', 'pending')->count();
                    $tasks->push($this->task([
                        'key' => 'inspection-' . $booking->id,
                        'type' => 'inspection',
                        'title' => 'Record material condition',
                        'booking' => $booking,
                        'status' => $pendingConditions === 0 ? 'completed' : 'pending',
                        'due_at' => null,
                        'completed_at' => null,
                        'detail' => $pendingConditions === 0 ? 'Conditions recorded for Admin review' : $pendingConditions . ' ' . \Illuminate\Support\Str::plural('item', $pendingConditions) . ' without a condition',
                        'url' => $eventUrl . '#condition',
                    ], $now));
                }
            }
        }

        return $tasks;
    }

    private function task(array $task, CarbonInterface $now): array
    {
        $task['type_label'] = self::TASK_TYPES[$task['type']];
        $task['urgency'] = match (true) {
            $task['status'] === 'completed' => 'done',
            $task['due_at'] === null => 'none',
            $task['due_at']->lt($now) => 'overdue',
            $task['due_at']->isSameDay($now) => 'today',
            $task['due_at']->lte($now->copy()->addDays(7)->endOfDay()) => 'soon',
            default => 'later',
        };

        return $task;
    }

    /** Applies the My Tasks filters and sort to a task collection. */
    public function filterTasks(Collection $tasks, array $filters): Collection
    {
        $now = now();
        $view = $filters['view'] ?? 'all';

        $tasks = $tasks->filter(function (array $task) use ($view, $now) {
            return match ($view) {
                'today' => $task['status'] !== 'completed' && ($task['urgency'] === 'overdue' || ($task['due_at'] && $task['due_at']->isSameDay($now))),
                'week' => $task['status'] !== 'completed' && $task['due_at'] && $task['due_at']->lte($now->copy()->endOfWeek()),
                'completed' => $task['status'] === 'completed',
                'pending' => $task['status'] !== 'completed',
                default => true,
            };
        });

        if (!empty($filters['event'])) {
            $tasks = $tasks->filter(fn ($task) => (int) $task['booking']->id === (int) $filters['event']);
        }
        if (!empty($filters['type']) && isset(self::TASK_TYPES[$filters['type']])) {
            $tasks = $tasks->filter(fn ($task) => $task['type'] === $filters['type']);
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'in_progress', 'completed'], true)) {
            $tasks = $tasks->filter(fn ($task) => $task['status'] === $filters['status']);
        }
        if (!empty($filters['search'])) {
            $q = mb_strtolower(trim($filters['search']));
            $tasks = $tasks->filter(function (array $task) use ($q) {
                $clientName = $task['booking']->client?->full_name ?? $task['booking']->guest_name ?? '';
                return str_contains(mb_strtolower($task['title']), $q)
                    || str_contains(mb_strtolower($clientName), $q)
                    || str_contains((string) $task['booking']->id, $q)
                    || str_contains(mb_strtolower($task['booking']->venue ?? ''), $q);
            });
        }

        return $this->sortTasks($tasks, $filters['sort'] ?? 'due');
    }

    public function sortTasks(Collection $tasks, string $sort = 'due'): Collection
    {
        $urgencyRank = ['overdue' => 0, 'today' => 1, 'soon' => 2, 'later' => 3, 'none' => 4, 'done' => 5];

        return $tasks->sort(function (array $a, array $b) use ($sort, $urgencyRank) {
            if ($sort === 'urgency') {
                $cmp = $urgencyRank[$a['urgency']] <=> $urgencyRank[$b['urgency']];
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            // Completed work sinks; then earliest deadline first; tasks without a deadline after dated ones.
            $doneCmp = ($a['status'] === 'completed') <=> ($b['status'] === 'completed');
            if ($doneCmp !== 0) {
                return $doneCmp;
            }
            $aTime = $a['due_at']?->timestamp ?? PHP_INT_MAX;
            $bTime = $b['due_at']?->timestamp ?? PHP_INT_MAX;

            return [$aTime, $a['booking']->id, $a['key']] <=> [$bTime, $b['booking']->id, $b['key']];
        })->values();
    }

    /** Dashboard figures. Each card states the rule it uses in the view. */
    public function summary(User $staff): array
    {
        $tasks = $this->tasks($staff);
        $active = $this->activeBookings($staff);
        $completed = $tasks->where('status', 'completed')->count();

        $itemsToPrepare = $active
            ->reject(fn (Booking $b) => in_array($b->status, self::POST_EVENT_STATUSES, true))
            ->sum(fn (Booking $b) => $this->materialLines($b)->where('state', '!=', 'ready')->count());

        return [
            'assigned_events' => $active->count(),
            'events_today' => $active->filter(fn (Booking $b) => $b->event_date && Carbon::parse($b->event_date)->isToday())->count(),
            'tasks_total' => $tasks->count(),
            'tasks_completed' => $completed,
            'tasks_open' => $tasks->count() - $completed,
            'tasks_overdue' => $tasks->where('urgency', 'overdue')->count(),
            'tasks_due_today' => $tasks->where('urgency', 'today')->count(),
            'completion_percent' => $tasks->count() > 0 ? (int) round(($completed / $tasks->count()) * 100) : 0,
            'items_to_prepare' => $itemsToPrepare,
            'dispatch_open' => $tasks->where('type', 'dispatch')->where('status', '!=', 'completed')->count(),
        ];
    }

    /**
     * Dated entries on the staff member's active assignments for one day: event start times,
     * preparation start dates and floral order targets. Entries without a time are "all day".
     */
    public function scheduleFor(User $staff, CarbonInterface $day): Collection
    {
        $entries = collect();

        foreach ($this->activeBookings($staff) as $booking) {
            $client = $booking->client?->full_name ?? $booking->guest_name ?? 'Client';
            $url = route('staff.events.show', $booking);

            if ($booking->event_date && Carbon::parse($booking->event_date)->isSameDay($day)) {
                $start = $this->eventStart($booking);
                $entries->push([
                    'time' => $booking->event_time ? $start : null,
                    'title' => ucfirst((string) $booking->event_type) . ' event',
                    'subtitle' => $client . ($booking->venue ? ' · ' . $booking->venue : ''),
                    'kind' => 'event',
                    'status' => $booking->status_display_label,
                    'url' => $url,
                ]);
            }
            if ($booking->preparation_start_date && Carbon::parse($booking->preparation_start_date)->isSameDay($day)) {
                $entries->push([
                    'time' => null,
                    'title' => 'Preparation begins',
                    'subtitle' => $client . ' · ' . ucfirst((string) $booking->event_type),
                    'kind' => 'preparation',
                    'status' => $booking->preparation_status_display_label,
                    'url' => $url . '#checklist',
                ]);
            }
            if ($booking->suggested_procurement_date && Carbon::parse($booking->suggested_procurement_date)->isSameDay($day)) {
                $entries->push([
                    'time' => null,
                    'title' => 'Floral order target',
                    'subtitle' => $client . ' · ' . ucfirst((string) $booking->event_type),
                    'kind' => 'procurement',
                    'status' => null,
                    'url' => $url . '#dispatch',
                ]);
            }
        }

        return $entries->sortBy(fn ($e) => $e['time']?->timestamp ?? 0)->values();
    }

    /**
     * The most relevant active event: one in progress, otherwise the next upcoming event.
     * Past events that are not in progress are not "current".
     */
    public function currentEvent(User $staff): ?Booking
    {
        $active = $this->activeBookings($staff);

        return $active->firstWhere('status', 'event_in_progress')
            ?? $active->first(fn (Booking $b) => $b->event_date && Carbon::parse($b->event_date)->startOfDay()->gte(now()->startOfDay()));
    }

    /** People on record for an event: the assigned staff member and the Admin handling the booking. */
    public function team(Booking $booking): Collection
    {
        $team = collect();
        if ($booking->staff_id && ($staff = User::find($booking->staff_id))) {
            $team->push(['name' => $staff->name, 'role' => 'Assigned staff', 'id' => $staff->id]);
        }
        if ($booking->handledBy) {
            $team->push(['name' => $booking->handledBy->name, 'role' => 'Admin handler', 'id' => $booking->handledBy->id]);
        }

        return $team;
    }

    /** Staff-visible messages on assigned bookings, newest first. */
    public function messages(User $staff, ?int $limit = null): Collection
    {
        $ids = $this->assignedBookings($staff)->pluck('id');
        $query = BookingMessage::whereIn('booking_id', $ids)
            ->whereIn('visibility', ['shared', 'admin_staff'])
            ->latest('created_at');

        return ($limit ? $query->limit($limit) : $query)->get();
    }

    /** Messages from other people on active assignments that arrived after the staff member last opened the thread. */
    public function unreadMessageCount(User $staff): int
    {
        $ids = $this->activeBookings($staff)->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        $reads = BookingMessageRead::where('user_id', $staff->id)->whereIn('booking_id', $ids)->pluck('last_read_at', 'booking_id');

        return BookingMessage::whereIn('booking_id', $ids)
            ->whereIn('visibility', ['shared', 'admin_staff'])
            ->where(fn ($q) => $q->where('sender_type', '!=', 'staff')->orWhere('sender_id', '!=', $staff->id))
            ->get(['booking_id', 'created_at'])
            ->filter(fn ($m) => !isset($reads[$m->booking_id]) || $m->created_at->gt(Carbon::parse($reads[$m->booking_id])))
            ->count();
    }

    public function markThreadRead(User $staff, Booking $booking): void
    {
        BookingMessageRead::updateOrCreate(
            ['user_id' => $staff->id, 'booking_id' => $booking->id],
            ['last_read_at' => now()]
        );
    }

    /**
     * Operational updates on the staff member's assignments: checklist completions, dispatches,
     * staff-visible messages from others, and the staff member's own audit entries. Admin audit
     * entries are excluded because they can include financial details.
     */
    public function recentUpdates(User $staff, int $limit = 8): Collection
    {
        $bookings = $this->assignedBookings($staff);
        $updates = collect();

        foreach ($bookings as $booking) {
            foreach ($booking->staffChecklistItems->where('is_completed', true) as $item) {
                if ($item->completed_at) {
                    $updates->push([
                        'at' => $item->completed_at,
                        'icon' => 'fa-solid fa-check',
                        'tone' => 'emerald',
                        'text' => ($item->completedBy?->name ?? 'Staff') . ' completed "' . $item->title . '"',
                        'context' => 'Booking #' . $booking->id,
                        'url' => route('staff.events.show', $booking) . '#checklist',
                    ]);
                }
            }
            foreach ($booking->inventoryTransactions->whereIn('transaction_type', ['dispatch']) as $tx) {
                $updates->push([
                    'at' => $tx->created_at,
                    'icon' => 'fa-solid fa-truck-fast',
                    'tone' => 'navy',
                    'text' => rtrim(rtrim(number_format(abs((float) $tx->quantity_change), 2), '0'), '.') . ' × ' . ($tx->inventoryItem?->name ?? 'material') . ' dispatched',
                    'context' => 'Booking #' . $booking->id,
                    'url' => route('staff.events.show', $booking) . '#dispatch',
                ]);
            }
        }

        foreach ($this->messages($staff, 20) as $message) {
            if ($message->sender_type === 'staff' && (int) $message->sender_id === (int) $staff->id) {
                continue;
            }
            $updates->push([
                'at' => $message->created_at,
                'icon' => 'fa-regular fa-comment',
                'tone' => 'brand',
                'text' => 'New message from ' . ucfirst((string) $message->sender_type),
                'context' => 'Booking #' . $message->booking_id,
                'url' => route('staff.events.show', $message->booking_id) . '#discussion',
            ]);
        }

        AuditLog::where('user_id', $staff->id)->latest('created_at')->limit(10)->get()->each(function ($log) use ($updates) {
            $updates->push([
                'at' => $log->created_at,
                'icon' => 'fa-solid fa-clock-rotate-left',
                'tone' => 'slate',
                'text' => $this->auditText($log),
                'context' => \Illuminate\Support\Str::headline((string) $log->module),
                'url' => null,
            ]);
        });

        return $updates->filter(fn ($u) => $u['at'])->sortByDesc(fn ($u) => $u['at']->timestamp)->take($limit)->values();
    }

    public function auditText(AuditLog $log): string
    {
        $details = $log->details;
        if (is_array($details) && isset($details['message'])) {
            return (string) $details['message'];
        }
        if (is_string($details) && $details !== '') {
            return $details;
        }

        return \Illuminate\Support\Str::headline((string) ($log->action ?? 'Activity recorded'));
    }

    /** Staff → Admin requests and reports raised from the workspace (stored as Admin alerts). */
    public function requests(User $staff): Collection
    {
        $ids = $this->assignedBookings($staff)->pluck('id');

        return AdminAlert::whereIn('booking_id', $ids)
            ->whereIn('type', ['inventory_request', 'staff_issue'])
            ->latest('created_at')
            ->get();
    }
}
