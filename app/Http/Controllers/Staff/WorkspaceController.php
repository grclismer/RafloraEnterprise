<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\AdminAlert;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\InventoryItem;
use App\Services\BookingWorkflowService;
use App\Services\StaffWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Staff Workspace pages. All data is scoped to bookings where bookings.staff_id is the signed-in
 * user (see StaffWorkspaceService); operational changes still go through EventController.
 */
class WorkspaceController extends Controller
{
    public const ISSUE_CATEGORIES = [
        'materials' => 'Materials',
        'equipment' => 'Equipment',
        'venue' => 'Venue access',
        'schedule' => 'Schedule',
        'safety' => 'Safety',
        'other' => 'Other',
    ];

    public function __construct(private StaffWorkspaceService $workspace)
    {
    }

    public function dashboard(Request $request): View
    {
        $staff = $request->user();
        $this->workspace->ensureChecklists($staff);

        $tasks = $this->workspace->tasks($staff);
        $current = $this->workspace->currentEvent($staff);
        $currentTasks = $current ? $tasks->filter(fn ($t) => $t['booking']->id === $current->id) : collect();

        $selectedSearch = (string) $request->query('search', $request->query('q', ''));
        $selectedView = $request->query('view', 'all');
        $selectedEvent = $request->query('event');
        $selectedType = $request->query('type');
        $selectedStatus = $request->query('status');
        $selectedSort = $request->query('sort', 'due');

        $filteredTasks = $this->workspace->filterTasks($tasks, [
            'search' => $selectedSearch,
            'view' => $selectedView,
            'event' => $selectedEvent,
            'type' => $selectedType,
            'status' => $selectedStatus,
            'sort' => $selectedSort,
        ]);

        return view('staff.dashboard', [
            'summary' => $this->workspace->summary($staff),
            'viewCounts' => collect(['all', 'today', 'week', 'pending', 'completed'])
                ->mapWithKeys(fn ($view) => [$view => $this->workspace->filterTasks($tasks, ['view' => $view])->count()]),
            'taskPreview' => $this->workspace->sortTasks($tasks->where('status', '!=', 'completed'))->take(8),
            'filteredTasks' => $filteredTasks,
            'selectedFilters' => [
                'search' => $selectedSearch,
                'view' => $selectedView,
                'event' => $selectedEvent,
                'type' => $selectedType,
                'status' => $selectedStatus,
                'sort' => $selectedSort,
            ],
            'schedule' => $this->workspace->scheduleFor($staff, now()),
            'currentEvent' => $current,
            'currentWorkflow' => $current ? app(BookingWorkflowService::class)->resolve($current) : null,
            'currentTaskTotal' => $currentTasks->count(),
            'currentTaskDone' => $currentTasks->where('status', 'completed')->count(),
            'currentMaterials' => $current ? $this->workspace->materialLines($current) : collect(),
            'currentTeam' => $current ? $this->workspace->team($current) : collect(),
            'recentUpdates' => $this->workspace->recentUpdates($staff),
            'activeEvents' => $this->workspace->activeBookings($staff),
            'allTasks' => $tasks,
        ]);
    }

    public function workOrders(Request $request): View
    {
        $staff = $request->user();
        $this->workspace->ensureChecklists($staff);

        $activeBookings = $this->workspace->activeBookings($staff);
        $tasks = $this->workspace->tasks($staff);
        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $s = mb_strtolower($search);
            $activeBookings = $activeBookings->filter(fn ($b) =>
                str_contains((string) $b->id, $s) ||
                str_contains(mb_strtolower($b->client?->full_name ?? $b->guest_name ?? ''), $s) ||
                str_contains(mb_strtolower($b->venue ?? ''), $s) ||
                str_contains(mb_strtolower((string) $b->event_type), $s)
            );
        }

        $workOrders = $activeBookings->map(function (Booking $booking) use ($tasks) {
            $bookingTasks = $tasks->filter(fn ($t) => (int) $t['booking']->id === (int) $booking->id);
            $totalTasks = $bookingTasks->count();
            $completedTasks = $bookingTasks->where('status', 'completed')->count();

            return [
                'booking' => $booking,
                'workflow' => app(BookingWorkflowService::class)->resolve($booking),
                'tasks' => $bookingTasks,
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'progress_percent' => $totalTasks > 0 ? (int) round(($completedTasks / $totalTasks) * 100) : 0,
                'materials' => $this->workspace->materialLines($booking),
                'dispatch_rows' => $this->workspace->dispatchRows($booking),
            ];
        });

        return view('staff.work-orders', [
            'workOrders' => $workOrders,
            'activeEvents' => $activeBookings,
            'summary' => $this->workspace->summary($staff),
            'currentSearch' => $search,
        ]);
    }

    public function tasks(Request $request): View
    {
        $staff = $request->user();
        $this->workspace->ensureChecklists($staff);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'view' => ['nullable', Rule::in(['all', 'today', 'week', 'completed', 'pending'])],
            'event' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(array_keys(StaffWorkspaceService::TASK_TYPES))],
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'completed'])],
            'sort' => ['nullable', Rule::in(['due', 'urgency'])],
        ]);
        $filters['view'] = $filters['view'] ?? 'all';

        $all = $this->workspace->tasks($staff);
        $filtered = $this->workspace->filterTasks($all, $filters);

        $perPage = 15;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $viewCounts = collect(['all', 'today', 'week', 'completed', 'pending'])
            ->mapWithKeys(fn ($view) => [$view => $this->workspace->filterTasks($all, ['view' => $view])->count()]);

        return view('staff.tasks', [
            'tasks' => $paginator,
            'filters' => $filters,
            'viewCounts' => $viewCounts,
            'events' => $this->workspace->activeBookings($staff),
            'summary' => $this->workspace->summary($staff),
        ]);
    }

    public function assignments(Request $request): View
    {
        $staff = $request->user();
        $assigned = $this->workspace->assignedBookings($staff);
        $search = trim((string) $request->query('search', ''));
        $eventType = trim((string) $request->query('event_type', ''));

        $active = $this->workspace->activeBookings($staff);
        if ($search !== '') {
            $s = mb_strtolower($search);
            $active = $active->filter(fn ($b) =>
                str_contains((string) $b->id, $s) ||
                str_contains(mb_strtolower($b->client?->full_name ?? $b->guest_name ?? ''), $s) ||
                str_contains(mb_strtolower($b->venue ?? ''), $s) ||
                str_contains(mb_strtolower((string) $b->event_type), $s)
            );
        }
        if ($eventType !== '') {
            $active = $active->filter(fn ($b) => mb_strtolower((string) $b->event_type) === mb_strtolower($eventType));
        }

        return view('staff.assignments', [
            'activeEvents' => $active,
            'completedEvents' => $assigned->where('status', 'completed')->sortByDesc(fn ($b) => $b->event_date?->timestamp ?? 0)->values(),
            'tasks' => $this->workspace->tasks($staff),
            'currentSearch' => $search,
            'currentEventType' => $eventType,
            'availableEventTypes' => $assigned->pluck('event_type')->filter()->unique()->values(),
        ]);
    }

    public function dispatchBoard(Request $request): View
    {
        $staff = $request->user();
        $search = trim((string) $request->query('search', ''));
        $bookings = $this->workspace->activeBookings($staff);

        if ($search !== '') {
            $s = mb_strtolower($search);
            $bookings = $bookings->filter(fn ($b) =>
                str_contains((string) $b->id, $s) ||
                str_contains(mb_strtolower($b->client?->full_name ?? $b->guest_name ?? ''), $s) ||
                str_contains(mb_strtolower($b->venue ?? ''), $s)
            );
        }

        $rows = $bookings
            ->map(fn (Booking $booking) => [
                'booking' => $booking,
                'rows' => $this->workspace->dispatchRows($booking),
                'can_dispatch' => $this->workspace->canDispatch($booking),
            ])
            ->filter(fn ($entry) => $entry['rows']->isNotEmpty())
            ->values();

        return view('staff.dispatch', [
            'entries' => $rows,
            'currentSearch' => $search,
        ]);
    }

    public function returns(Request $request): View
    {
        $staff = $request->user();
        $tasks = $this->workspace->tasks($staff)->whereIn('type', ['return', 'inspection']);
        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $s = mb_strtolower($search);
            $tasks = $tasks->filter(fn ($t) =>
                str_contains((string) $t['booking']->id, $s) ||
                str_contains(mb_strtolower($t['title'] ?? ''), $s) ||
                str_contains(mb_strtolower($t['booking']->client?->full_name ?? $t['booking']->guest_name ?? ''), $s) ||
                str_contains(mb_strtolower($t['booking']->venue ?? ''), $s)
            );
        }

        return view('staff.returns', [
            'tasks' => $this->workspace->sortTasks($tasks),
            'currentSearch' => $search,
        ]);
    }

    public function checklist(Request $request): View
    {
        $staff = $request->user();
        $this->workspace->ensureChecklists($staff);
        $events = $this->workspace->activeBookings($staff);
        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $s = mb_strtolower($search);
            $events = $events->filter(fn ($b) =>
                str_contains((string) $b->id, $s) ||
                str_contains(mb_strtolower($b->client?->full_name ?? $b->guest_name ?? ''), $s) ||
                str_contains(mb_strtolower($b->venue ?? ''), $s)
            );
        }

        return view('staff.checklist', [
            'events' => $events,
            'currentSearch' => $search,
        ]);
    }

    public function messages(Request $request): View
    {
        $staff = $request->user();
        $bookings = $this->workspace->assignedBookings($staff)->keyBy('id');
        $reads = \App\Models\BookingMessageRead::where('user_id', $staff->id)->pluck('last_read_at', 'booking_id');
        $search = trim((string) $request->query('search', ''));

        $threads = $this->workspace->messages($staff)
            ->groupBy('booking_id')
            ->map(function ($messages, $bookingId) use ($bookings, $reads, $staff) {
                $lastRead = isset($reads[$bookingId]) ? Carbon::parse($reads[$bookingId]) : null;
                $unread = $messages->filter(fn ($m) => !($m->sender_type === 'staff' && (int) $m->sender_id === (int) $staff->id)
                    && (!$lastRead || $m->created_at->gt($lastRead)))->count();

                return ['booking' => $bookings[$bookingId] ?? null, 'latest' => $messages->first(), 'count' => $messages->count(), 'unread' => $unread];
            })
            ->filter(fn ($thread) => $thread['booking'] !== null)
            ->sortByDesc(fn ($thread) => $thread['latest']->created_at->timestamp)
            ->values();

        if ($search !== '') {
            $s = mb_strtolower($search);
            $threads = $threads->filter(fn ($t) =>
                str_contains((string) $t['booking']->id, $s) ||
                str_contains(mb_strtolower($t['booking']->client?->full_name ?? $t['booking']->guest_name ?? ''), $s) ||
                str_contains(mb_strtolower($t['latest']->message ?? ''), $s)
            );
        }

        return view('staff.messages', [
            'threads' => $threads,
            'eventsWithoutMessages' => $this->workspace->activeBookings($staff)->reject(fn ($b) => $threads->contains(fn ($t) => $t['booking']->id === $b->id)),
            'currentSearch' => $search,
        ]);
    }

    public function activity(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $query = AuditLog::where('user_id', $request->user()->id)->latest('created_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('module', 'like', "%{$search}%")
                    ->orWhere('details', 'like', "%{$search}%");
            });
        }

        return view('staff.activity', [
            'logs' => $query->paginate(20)->withQueryString(),
            'workspace' => $this->workspace,
            'currentSearch' => $search,
        ]);
    }

    public function calendar(Request $request): View
    {
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = isset($validated['month']) ? Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth() : now()->startOfMonth();

        $staff = $request->user();
        $entries = collect();
        foreach ($this->workspace->assignedBookings($staff) as $booking) {
            $client = $booking->client?->full_name ?? $booking->guest_name ?? 'Client';
            $dates = [
                ['date' => $booking->event_date, 'kind' => 'event', 'label' => ucfirst((string) $booking->event_type) . ' · ' . $client, 'time' => $booking->event_time],
                ['date' => $booking->preparation_start_date, 'kind' => 'preparation', 'label' => 'Prep starts · ' . $client, 'time' => null],
            ];
            foreach ($dates as $entry) {
                if ($entry['date'] && Carbon::parse($entry['date'])->isSameMonth($month)) {
                    $entries->push($entry + ['booking' => $booking, 'day' => Carbon::parse($entry['date'])->toDateString()]);
                }
            }
        }

        return view('staff.calendar', [
            'month' => $month,
            'entriesByDay' => $entries->groupBy('day'),
        ]);
    }

    public function guide(): View
    {
        return view('staff.guide', [
            'stages' => array_values(BookingWorkflowService::STAGES),
        ]);
    }

    public function search(Request $request): View
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) ($validated['q'] ?? ''));
        $staff = $request->user();

        $bookings = collect();
        $tasks = collect();
        if ($term !== '') {
            $needle = Str::lower(ltrim($term, '#'));
            $bookings = $this->workspace->assignedBookings($staff)->filter(function (Booking $b) use ($needle) {
                $haystack = Str::lower(implode(' ', [
                    $b->id, $b->event_type, $b->venue, $b->client?->full_name, $b->guest_name,
                ]));

                return Str::contains($haystack, $needle) || (string) $b->id === $needle;
            })->values();
            $tasks = $this->workspace->tasks($staff)->filter(fn ($t) => Str::contains(Str::lower($t['title']), $needle))->values();
        }

        return view('staff.search', ['term' => $term, 'bookings' => $bookings, 'tasks' => $tasks]);
    }

    public function requests(Request $request): View
    {
        $staff = $request->user();

        return view('staff.requests', [
            'requests' => $this->workspace->requests($staff),
            'events' => $this->workspace->activeBookings($staff),
            'inventoryItems' => InventoryItem::query()->orderBy('name')->get(['id', 'name', 'unit']),
            'issueCategories' => self::ISSUE_CATEGORIES,
            'selectedEvent' => (int) $request->query('event', 0),
        ]);
    }

    /**
     * Asks Admin for material. Creates (or updates) one open Admin alert per booking and item;
     * stock is never reserved or changed here.
     */
    public function storeInventoryRequest(Request $request): RedirectResponse
    {
        $staff = $request->user();
        $validated = $request->validate([
            'booking_id' => ['required', 'integer'],
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $booking = $this->assignedActiveBooking($staff, (int) $validated['booking_id']);
        $item = InventoryItem::findOrFail($validated['inventory_item_id']);
        $quantity = rtrim(rtrim(number_format((float) $validated['quantity'], 2, '.', ''), '0'), '.');
        $note = trim((string) ($validated['note'] ?? ''));

        $message = "{$staff->name} requested {$quantity} {$item->unit} of {$item->name} for Booking #{$booking->id} (" . ucfirst((string) $booking->event_type) . ').'
            . ($note !== '' ? " Note: {$note}" : '')
            . ' Stock is not reserved or changed until Admin acts on this request.';

        $updated = DB::transaction(function () use ($booking, $item, $quantity, $message, $staff): bool {
            $open = AdminAlert::where('type', 'inventory_request')
                ->where('booking_id', $booking->id)
                ->where('inventory_item_id', $item->id)
                ->where('is_read', false)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'title' => "Inventory request: {$item->name} × {$quantity} — Booking #{$booking->id}",
                'message' => $message,
            ];

            if ($open) {
                $open->update($attributes);
            } else {
                AdminAlert::create($attributes + [
                    'type' => 'inventory_request',
                    'booking_id' => $booking->id,
                    'inventory_item_id' => $item->id,
                    'is_read' => false,
                ]);
            }

            AuditLog::create([
                'user_id' => $staff->id,
                'action' => $open ? 'inventory_request_updated' : 'inventory_requested',
                'module' => 'inventory',
                'details' => "Requested {$quantity} {$item->unit} of {$item->name} for Booking #{$booking->id}.",
                'entity_type' => InventoryItem::class,
                'entity_id' => $item->id,
            ]);

            return (bool) $open;
        });

        return redirect()->route('staff.requests')->with('success', $updated
            ? "Your open request for {$item->name} on Booking #{$booking->id} was updated. Admin has been notified."
            : "Inventory request for {$item->name} sent to Admin. Stock is not reserved until Admin approves it.");
    }

    /** Reports an operational problem to Admin. Identical open reports are not duplicated. */
    public function storeIssue(Request $request): RedirectResponse
    {
        $staff = $request->user();
        $validated = $request->validate([
            'booking_id' => ['required', 'integer'],
            'category' => ['required', Rule::in(array_keys(self::ISSUE_CATEGORIES))],
            'description' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $booking = $this->assignedActiveBooking($staff, (int) $validated['booking_id']);
        $category = self::ISSUE_CATEGORIES[$validated['category']];
        $title = "Staff issue ({$category}): Booking #{$booking->id}";
        $message = "{$staff->name}: " . trim($validated['description']);

        $created = DB::transaction(function () use ($booking, $title, $message, $staff, $category): bool {
            $duplicate = AdminAlert::where('type', 'staff_issue')
                ->where('booking_id', $booking->id)
                ->where('title', $title)
                ->where('message', $message)
                ->where('is_read', false)
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                return false;
            }

            AdminAlert::create([
                'type' => 'staff_issue',
                'title' => $title,
                'message' => $message,
                'booking_id' => $booking->id,
                'is_read' => false,
            ]);

            AuditLog::create([
                'user_id' => $staff->id,
                'action' => 'staff_issue_reported',
                'module' => 'operations',
                'details' => "Reported a {$category} issue on Booking #{$booking->id}.",
                'entity_type' => Booking::class,
                'entity_id' => $booking->id,
            ]);

            return true;
        });

        return redirect()->route('staff.requests')->with(
            $created ? 'success' : 'info',
            $created ? 'Issue reported to Admin for Booking #' . $booking->id . '.' : 'This issue is already open with Admin for Booking #' . $booking->id . '.'
        );
    }

    /** 404s unless the booking is an active assignment of this staff member (no ID guessing). */
    private function assignedActiveBooking($staff, int $bookingId): Booking
    {
        $booking = $this->workspace->activeBookings($staff)->firstWhere('id', $bookingId);
        abort_if(!$booking, 404);

        return $booking;
    }
}
