@php
    $bookingHeading = 'Booking #' . $booking->id . ' · ' . ucfirst($booking->event_type);
    $bookingClientName = $booking->client?->full_name ?? 'Guest';
    try {
        $bookingEventTime = $booking->event_time ? \Illuminate\Support\Carbon::parse($booking->event_time)->format('g:i A') : null;
    } catch (\Throwable $e) {
        $bookingEventTime = $booking->event_time;
    }
    try {
        $bookingEndTime = $booking->end_time ? \Illuminate\Support\Carbon::parse($booking->end_time)->format('g:i A') : null;
    } catch (\Throwable $e) {
        $bookingEndTime = $booking->end_time;
    }
    if ($bookingEventTime && $bookingEndTime) {
        $bookingEventTime .= ' – ' . $bookingEndTime;
    }
@endphp
<x-admin-layout :title="$bookingHeading" :description="'Client: ' . $bookingClientName">
    <x-slot:actions>
        @php
            // Only a list URL stored by the bookings page itself is used.
            $bookingsListUrl = session('admin.bookings.list_url');
            $bookingsListUrl = is_string($bookingsListUrl) && str_starts_with($bookingsListUrl, route('admin.bookings')) ? $bookingsListUrl : route('admin.bookings');
        @endphp
        <a href="{{ $bookingsListUrl }}" class="inline-flex items-center gap-2 h-9 px-3.5 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-50 hover:text-slate-900 transition">
            <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
            Back to Bookings
        </a>
    </x-slot:actions>

    @php
        $quoteReadOnly = $quoteReadOnly ?? false;
        $bookingLocked = $quoteReadOnly;

        $bookingWorkflow = app(\App\Services\BookingWorkflowService::class)->resolve($booking);
        $canCompleteReview = $booking->status === 'pending'
            && !is_null($booking->client_id)
            && !$booking->isReviewed()
            && $bookingWorkflow['current'] === 'raflora_review';

        $totalObligation = (float) $booking->total_obligation;
        $paidAmount = (float) $booking->total_paid;
        $remaining = max(0, $totalObligation - $paidAmount);
        $paidPercent = $totalObligation > 0 ? min(100, round(($paidAmount / $totalObligation) * 100, 1)) : 0;

        $canLogFinalPayment = in_array($booking->status, ['event_in_progress', 'event_completed', 'pending_return', 'pending_resolution'], true) && ($remaining > 0);
        // A balance reference the client sent: Admin checks it under "Log Final Payment" before logging it.
        $clientBalancePayment = $canLogFinalPayment ? $booking->payments->where('status', 'pending')->sortByDesc('created_at')->first() : null;
        $showMarkInProgress = in_array($booking->status, ['downpayment_received', 'confirmed'], true);
        // Delivery orders are sold, not rented: no event completion or return audit; Admin (or the
        // client) marks the item received instead (App\Services\DeliveryOrderService).
        $isDeliveryOrder = $booking->isDelivery();
        $showMarkDeliveryReceived = app(\App\Services\DeliveryOrderService::class)->canMarkReceived($booking);
        $showMarkEventCompleted = $booking->status === 'event_in_progress' && !$isDeliveryOrder;
        $hasCompletedReturn = $booking->returns->where('status', 'Completed')->isNotEmpty();
        $hasCancellationRecovery = ($booking->status === 'cancelled') && ($booking->returns->isNotEmpty() || $booking->hasDispatchedReusableMaterials());
        $showReturnAuditLink = !($isDeliveryOrder && $booking->status !== 'cancelled') && ($booking->status === 'event_completed' || in_array($booking->status, ['pending_return', 'pending_resolution'], true) || $hasCancellationRecovery || $booking->status === 'completed');

        // The client's pending schedule change request, decided from the Quotation section (ScheduleChangeService).
        $pendingSchedule = in_array($booking->status, \App\Services\ScheduleChangeService::OPEN_STATUSES, true)
            ? app(\App\Services\ScheduleChangeService::class)->pendingFor($booking)
            : null;
        $scheduleAvailability = $pendingSchedule ? app(\App\Services\ScheduleChangeService::class)->availability($booking, $pendingSchedule->requested_date) : null;

        $showOperationalActions = $canLogFinalPayment || $showMarkInProgress || $showMarkEventCompleted || $showMarkDeliveryReceived || $showReturnAuditLink;

        $damageLoss = $booking->returns ? $booking->returns->sum('total_damage_charge') : 0;
        $baseQuote = $booking->final_quoted_price ?: $booking->total_quoted;

        $materialItems = ($bookingItems ?? $booking->bookingItems)->filter(fn ($bi) => (float) $bi->quantity > 0)->values();
        $validatedMaterialCount = $materialItems->filter(fn ($bi) => !is_null($bi->confirmed_at))->count();
        $allMaterialsValidated = $materialItems->isNotEmpty() && $validatedMaterialCount === $materialItems->count();
        $formatMaterialQty = fn ($qty) => rtrim(rtrim(number_format((float) $qty, 2, '.', ','), '0'), '.');

        $prepChecklist = $booking->staffChecklistItems()->with('completedBy')->orderBy('id')->get();
        $prepCompletedCount = $prepChecklist->where('is_completed', true)->count();
        $prepAllComplete = $prepChecklist->isNotEmpty() && $prepCompletedCount === $prepChecklist->count();

        $assignedStaff = $booking->assignedStaff;
        $pendingPaymentCount = $booking->payments->where('status', 'pending')->count();

        // Only values persisted by the AI analysis are shown; nothing is estimated in the view.
        $aiSuggestions = collect($analysis?->suggested_materials ?? [])->filter(fn ($m) => is_array($m))->values();
        $aiNeedsReviewCount = $aiSuggestions->filter(fn ($m) => !empty($m['needs_review']))->count();

        $statusBadgeVariant = match (true) {
            $booking->status === 'pending' => 'warning',
            $booking->status === 'downpayment_received' => 'success',
            default => 'primary',
        };
        $statusBadgeLabel = $booking->status === 'pending' ? 'Pending Admin Review' : $booking->status_display_label;

        $tabs = [
            ['id' => 'tab-overview', 'label' => 'Overview', 'icon' => 'fa-solid fa-table-columns'],
            ['id' => 'tab-details', 'label' => 'Event Details & AI', 'icon' => 'fa-solid fa-wand-magic-sparkles',
                'badge' => $aiNeedsReviewCount > 0 ? $aiNeedsReviewCount . ' to review' : null, 'tone' => 'bg-amber-100 text-amber-800'],
            ['id' => 'tab-quotation', 'label' => 'Quotation', 'icon' => 'fa-solid fa-file-invoice-dollar',
                'badge' => isset($activeQuotation) ? 'v' . $activeQuotation->version : null, 'tone' => 'bg-slate-100 text-slate-600'],
            ['id' => 'tab-materials', 'label' => 'Materials & Inventory', 'icon' => 'fa-solid fa-boxes-stacked',
                'badge' => $materialItems->isNotEmpty() ? $validatedMaterialCount . '/' . $materialItems->count() : null,
                'tone' => $allMaterialsValidated ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'],
            ['id' => 'tab-preparation', 'label' => 'Preparation', 'icon' => 'fa-solid fa-clipboard-list',
                'badge' => $prepChecklist->isNotEmpty() ? $prepCompletedCount . '/' . $prepChecklist->count() : null,
                'tone' => $prepAllComplete ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'],
            ['id' => 'tab-staff', 'label' => 'Staff Assignment', 'icon' => 'fa-solid fa-user-gear',
                'badge' => $assignedStaff ? null : 'Unassigned', 'tone' => 'bg-slate-100 text-slate-600'],
        ];

        // Tab highlight, read from the workflow stages only: done (green), action (orange), waiting (client's turn).
        $currentStage = empty($bookingWorkflow['terminal']) ? $bookingWorkflow['current'] : null;
        $stageDone = fn (string $key) => ($bookingWorkflow['stages'][$key]['state'] ?? null) === \App\Services\BookingWorkflowService::STATE_COMPLETE;
        $staffNeededStages = ['confirmed', 'preparation_reservation', 'dispatch', 'event_execution'];
        // Dispatch stage with nothing left to dispatch: the event now waits for Admin's "Mark Event In Progress".
        $readyToStartEvent = $currentStage === 'dispatch' && $showMarkInProgress && (function () use ($booking) {
            $txs = $booking->inventoryTransactions()->whereHas('inventoryItem', fn ($q) => $q->where('is_perishable', false))->get();
            $reserved = abs((float) $txs->where('transaction_type', 'booking_lock')->filter(fn ($t) => (float) $t->quantity_change < 0)->sum('quantity_change'))
                - (float) $txs->where('transaction_type', 'booking_release')->filter(fn ($t) => (float) $t->quantity_change > 0)->sum('quantity_change');
            $dispatched = abs((float) $txs->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])->sum('quantity_change'));

            return $dispatched >= max(0.0, $reserved);
        })();
        $tabStates = empty($bookingWorkflow['terminal']) ? [
            'tab-details' => $currentStage === 'raflora_review' ? 'action' : ($stageDone('raflora_review') ? 'done' : null),
            'tab-quotation' => ($pendingSchedule || in_array($currentStage, ['material_validation', 'quotation'], true)) ? 'action'
                : ($currentStage === 'approval' ? 'waiting' : ($stageDone('approval') ? 'done' : null)),
            'tab-materials' => $currentStage === 'material_validation' ? 'action' : (($allMaterialsValidated || $stageDone('material_validation')) ? 'done' : null),
            'tab-preparation' => ($stageDone('dispatch') || $readyToStartEvent) ? 'done' : (in_array($currentStage, ['preparation_reservation', 'dispatch'], true) ? 'action' : null),
            'tab-staff' => $assignedStaff ? 'done' : (in_array($currentStage, $staffNeededStages, true) ? 'action' : null),
        ] : [];
        $tabStateStyles = [
            'done' => ['dot' => 'bg-emerald-500', 'tab' => 'bg-emerald-50/70', 'label' => 'Completed'],
            'action' => ['dot' => 'bg-orange-500 animate-pulse', 'tab' => 'bg-orange-50', 'label' => 'Needs action'],
            'waiting' => ['dot' => 'bg-sky-500', 'tab' => 'bg-sky-50/70', 'label' => 'Waiting for client'],
        ];

        // "Next step" shortcut: opens the section where the current workflow stage is handled.
        $nextStep = match (true) {
            // A client schedule request waits on Admin whatever the stage (ScheduleChangeService).
            (bool) $pendingSchedule => ['target' => 'schedule-change-request', 'button' => 'Review Schedule Request'],
            $currentStage === 'raflora_review' => ['target' => 'tab-details', 'button' => 'Review Event Details & AI'],
            $currentStage === 'material_validation' => ['target' => 'tab-quotation', 'button' => 'Validate Materials in Quotation'],
            $currentStage === 'quotation' => ['target' => 'tab-quotation', 'button' => 'Prepare & Send Quotation'],
            $currentStage === 'approval' => ['target' => 'tab-quotation', 'button' => 'View Sent Quotation'],
            $currentStage === 'payment' => ['target' => $booking->payments->isNotEmpty() ? 'payments-heading' : 'financial-summary-heading', 'button' => 'Review Payments'],
            in_array($currentStage, $staffNeededStages, true) && !$assignedStaff => ['target' => 'tab-staff', 'button' => 'Assign Staff'],
            $readyToStartEvent => ['target' => 'operational-actions-heading', 'button' => $isDeliveryOrder ? 'Mark Out for Delivery' : 'Start Event · Mark In Progress'],
            $isDeliveryOrder && $showMarkDeliveryReceived => ['target' => 'operational-actions-heading', 'button' => 'Mark Item Received'],
            $isDeliveryOrder && $currentStage === 'completion' && $canLogFinalPayment => ['target' => 'operational-actions-heading', 'button' => 'Log Final Payment'],
            in_array($currentStage, ['confirmed', 'preparation_reservation', 'dispatch'], true) => ['target' => 'tab-preparation', 'button' => 'Open Preparation & Dispatch'],
            in_array($currentStage, ['event_execution', 'material_return', 'inventory_reconciliation'], true) => ['target' => $showOperationalActions ? 'operational-actions-heading' : 'financial-summary-heading', 'button' => 'Open Operational Actions'],
            default => null,
        };

        $cardHeader = 'flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between px-5 py-4 border-b border-slate-100';
        $cardTitle = 'text-[15px] font-semibold text-slate-900';
        $cardText = 'mt-0.5 text-[13px] text-slate-500';
        $pill = 'inline-flex w-fit items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold';
        $fieldLabel = 'block text-xs font-semibold text-slate-600 mb-1';
        $fieldInput = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-500/20 focus:outline-none';
        $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-800 transition disabled:cursor-not-allowed disabled:opacity-50';
        $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 hover:text-slate-900 transition disabled:cursor-not-allowed disabled:opacity-50';
        $btnDark = 'inline-flex items-center justify-center gap-2 rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-900 transition disabled:cursor-not-allowed disabled:opacity-50';
    @endphp

    @if($quoteReadOnly)
        <div class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            <i class="fa-solid fa-lock mt-0.5 text-amber-600" aria-hidden="true"></i>
            <p>This quotation is locked because the booking is currently <strong>{{ $booking->status_display_label }}</strong>. Pricing and materials are read-only.</p>
        </div>
    @endif

    {{-- Booking summary: identity, current step and money at a glance on every tab --}}
    <section class="rf-admin-card mb-5 overflow-hidden" aria-label="Booking summary">
        <div class="lg:hidden px-5 pt-4">
            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-700">Operational booking review</p>
            <h2 class="mt-1 text-xl font-semibold text-slate-900">{{ $bookingHeading }}</h2>
            <p class="text-sm text-slate-500">Client: {{ $bookingClientName }}</p>
        </div>
        <div class="px-5 py-4 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rf-badge rf-badge--{{ $statusBadgeVariant }}">{{ $statusBadgeLabel }}</span>
                    @if(!empty($bookingWorkflow['terminal']))
                        <span class="text-xs font-semibold text-rose-700">{{ $bookingWorkflow['terminal_label'] }}</span>
                    @elseif($bookingWorkflow['current'])
                        <span class="text-xs text-slate-500">Now: <span class="font-semibold text-slate-800">{{ $bookingWorkflow['current_label'] }}</span></span>
                    @endif
                </div>
                @if(empty($bookingWorkflow['terminal']) && !empty($bookingWorkflow['current_detail']))
                    <p class="mt-1.5 text-sm text-slate-600 max-w-2xl">{{ $bookingWorkflow['current_detail'] }}</p>
                @endif
            </div>
        </div>
        <dl class="grid grid-cols-2 lg:grid-cols-4 border-t border-slate-100 bg-slate-50/60 text-sm">
            <div class="px-5 py-3 min-w-0">
                <dt class="text-xs font-medium text-slate-500">Event date</dt>
                <dd class="mt-0.5 font-medium text-slate-900">
                    {{ optional($booking->event_date)->format('D, M j, Y') ?? 'Not set' }}@if($bookingEventTime)<span class="text-slate-500 font-normal"> · {{ $bookingEventTime }}</span>@endif
                </dd>
            </div>
            <div class="px-5 py-3 min-w-0 lg:border-l border-slate-100">
                <dt class="text-xs font-medium text-slate-500">Venue</dt>
                <dd class="mt-0.5 font-medium text-slate-900 truncate" title="{{ $booking->venue }}">{{ $booking->venue ?? 'Not set' }}</dd>
            </div>
            <div class="px-5 py-3 min-w-0 border-t lg:border-t-0 lg:border-l border-slate-100">
                <dt class="text-xs font-medium text-slate-500">Client</dt>
                <dd class="mt-0.5 font-medium text-slate-900 truncate">{{ $bookingClientName }}</dd>
            </div>
            <div class="px-5 py-3 min-w-0 border-t lg:border-t-0 lg:border-l border-slate-100">
                <dt class="text-xs font-medium text-slate-500">Assigned staff</dt>
                <dd class="mt-0.5 font-medium truncate {{ $assignedStaff ? 'text-slate-900' : 'text-slate-400' }}">{{ $assignedStaff?->name ?? 'Unassigned' }}</dd>
            </div>
        </dl>
    </section>

    <!-- Booking Navigation Tabs: Overview is the page; every other section opens as a pop-up over it. -->
    <div class="rf-admin-card mb-6 overflow-hidden">
      <div class="px-2 py-2">
        <nav class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6" role="tablist" aria-label="Booking sections">
            @foreach($tabs as $tab)
                @php
                    $isFirstTab = $loop->first;
                    $tabState = $tabStates[$tab['id']] ?? null;
                    $tabStyle = $tabState ? $tabStateStyles[$tabState] : null;
                @endphp
                <button
                    @unless($isFirstTab) aria-haspopup="dialog" @endunless
                    @if($tabState) data-tab-state="{{ $tabState }}" title="{{ $tabStyle['label'] }}" @endif
                    type="button"
                    role="tab"
                    id="{{ $tab['id'] }}-button"
                    aria-controls="{{ $tab['id'] }}"
                    aria-selected="{{ $isFirstTab ? 'true' : 'false' }}"
                    tabindex="{{ $isFirstTab ? '0' : '-1' }}"
                    onclick="switchTab('{{ $tab['id'] }}')"
                    class="tab-button group inline-flex min-w-0 items-center gap-2 rounded-lg border px-2.5 py-2.5 text-left text-sm font-medium leading-snug transition focus:outline-none focus-visible:bg-slate-50 {{ $tabStyle['tab'] ?? '' }} {{ $isFirstTab ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:text-slate-800' }}"
                    data-target="{{ $tab['id'] }}"
                >
                    @if($tabStyle)
                        <span class="h-2 w-2 shrink-0 rounded-full {{ $tabStyle['dot'] }}" aria-hidden="true"></span>
                    @endif
                    <i class="{{ $tab['icon'] }} text-[13px] opacity-80" aria-hidden="true"></i>
                    <span class="min-w-0">{{ $tab['label'] }}</span>
                    @if(!empty($tab['badge']))
                        <span class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold leading-none tabular-nums {{ $tab['tone'] }}">{{ $tab['badge'] }}</span>
                    @endif
                    @if($tabStyle)
                        <span class="sr-only">({{ $tabStyle['label'] }})</span>
                    @endif
                </button>
            @endforeach
        </nav>
      </div>
      @if(!empty($tabStates))
        <p class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-100 bg-slate-50/60 px-4 py-2 text-[11px] text-slate-500">
            @foreach($tabStateStyles as $legend)
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full {{ str_replace(' animate-pulse', '', $legend['dot']) }}" aria-hidden="true"></span>{{ $legend['label'] }}</span>
            @endforeach
            <span class="text-slate-400">Sections open as a pop-up over this overview.</span>
        </p>
      @endif
    </div>

    {{-- ============================== OVERVIEW ============================== --}}
    <div id="tab-overview" class="tab-content block space-y-6" role="tabpanel" aria-labelledby="tab-overview-button" tabindex="0">
        @if($isDeliveryOrder)
            <x-delivery-order-notice :booking="$booking" audience="admin" />
        @endif

        @if($canCompleteReview)
            <section class="rf-admin-card border-brand-200 bg-brand-50/40" aria-labelledby="raflora-review-heading">
                <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-700 text-white" aria-hidden="true"><i class="fa-solid fa-clipboard-check text-sm"></i></span>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-700">Your next step</p>
                            <h2 id="raflora-review-heading" class="mt-0.5 {{ $cardTitle }}">Raflora Review</h2>
                            <p class="{{ $cardText }} max-w-3xl">Review the event details, inspiration, and client messages. Completing the review moves this booking to Material Preparation / Validation. Confirming, linking, or promoting a material also completes the review.</p>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('admin.bookings.complete-review', $booking) }}" class="flex shrink-0 flex-col gap-2 sm:flex-row">
                        @csrf
                        <button type="button" onclick="switchTab('tab-details')" class="{{ $btnSecondary }} w-full sm:w-auto">
                            <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Open Event Details &amp; AI
                        </button>
                        <button type="submit" class="{{ $btnPrimary }} w-full sm:w-auto">
                            <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> Complete Raflora Review
                        </button>
                    </form>
                </div>
            </section>
        @endif

        {{-- README end-to-end booking workflow: Guest → Client → Staff --}}
        <x-booking-workflow
            :workflow="$bookingWorkflow"
            accent="brand"
            id="admin-booking-workflow"
            heading="Booking Workflow"
            description="Guest → Client → Staff. Stages update from the booking, quotation, payment, inventory, and return records.">
            @if($nextStep && !$canCompleteReview)
                {{-- Next-step shortcut in place of the stage badge: opens the section where the current stage is handled. --}}
                <x-slot:action>
                    <div id="admin-next-step" class="flex flex-col items-stretch gap-1 sm:items-end">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.12em] text-orange-700">Next step · Stage {{ $bookingWorkflow['current_number'] }} of {{ $bookingWorkflow['total'] }}</span>
                        <button type="button" data-next-step-target="{{ $nextStep['target'] }}" onclick="goToBookingSection(this.dataset.nextStepTarget)" class="{{ $btnPrimary }} w-full sm:w-auto">
                            {{ $nextStep['button'] }} <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                        </button>
                    </div>
                </x-slot:action>
            @endif
        </x-booking-workflow>

        <div class="grid grid-cols-1 gap-6 {{ $showOperationalActions ? 'xl:grid-cols-12' : '' }}">
            <section class="rf-admin-card {{ $showOperationalActions ? 'xl:col-span-7' : '' }}" aria-labelledby="financial-summary-heading">
                <div class="{{ $cardHeader }}">
                    <div>
                        <h2 id="financial-summary-heading" class="{{ $cardTitle }}">Financial Summary &amp; Settlement</h2>
                        <p class="{{ $cardText }}">Verified payments against the total obligation, including any damage or loss charges.</p>
                    </div>
                    @if($totalObligation <= 0)
                        <span class="{{ $pill }} bg-slate-100 text-slate-600">Not yet quoted</span>
                    @elseif($remaining > 0)
                        <span class="{{ $pill }} bg-amber-100 text-amber-800">Balance Pending</span>
                    @else
                        <span class="{{ $pill }} bg-emerald-100 text-emerald-800"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Fully Paid</span>
                    @endif
                </div>
                <div class="p-5">
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 p-3.5">
                            <dt class="text-xs font-medium text-slate-500">Total Obligation</dt>
                            <dd class="mt-1 text-lg font-semibold text-slate-900 tabular-nums">₱{{ number_format($totalObligation, 2) }}</dd>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3.5">
                            <dt class="text-xs font-medium text-slate-500">Verified Paid</dt>
                            <dd class="mt-1 text-lg font-semibold text-emerald-700 tabular-nums">₱{{ number_format($paidAmount, 2) }}</dd>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-3.5">
                            <dt class="text-xs font-medium text-slate-500">Remaining Balance</dt>
                            <dd class="mt-1 text-lg font-semibold text-slate-900 tabular-nums">₱{{ number_format($remaining, 2) }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4">
                        <div class="flex items-center justify-between text-xs text-slate-500">
                            <span>Collected</span>
                            <span class="font-medium text-slate-700 tabular-nums">{{ $paidPercent }}%</span>
                        </div>
                        <div class="mt-1.5 h-2 w-full rounded-full bg-slate-100" role="progressbar" aria-label="Share of total obligation collected" aria-valuenow="{{ $paidPercent }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="h-2 rounded-full bg-[#0ca30c]" style="width: {{ $paidPercent }}%;"></div>
                        </div>
                    </div>

                    <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-slate-100 pt-4 text-sm">
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Base Quote</dt>
                            <dd class="mt-0.5 font-medium text-slate-800 tabular-nums">₱{{ number_format($baseQuote, 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Damage / Loss</dt>
                            <dd class="mt-0.5 font-medium tabular-nums {{ $damageLoss > 0 ? 'text-rose-700' : 'text-slate-800' }}">₱{{ number_format($damageLoss, 2) }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            @if($showOperationalActions)
                <section class="rf-admin-card xl:col-span-5" aria-labelledby="operational-actions-heading">
                    <div class="{{ $cardHeader }}">
                        <div>
                            <h2 id="operational-actions-heading" class="{{ $cardTitle }}">Operational Actions</h2>
                            <p class="{{ $cardText }}">{{ $isDeliveryOrder ? 'Delivery status updates and settlement.' : 'Event-day status updates, settlement and return audit.' }}</p>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2.5 p-5">
                        @if($showMarkInProgress)
                            <form method="POST" action="{{ route('admin.bookings.update', ['booking' => $booking->id]) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="event_type" value="{{ $booking->event_type }}">
                                <input type="hidden" name="event_date" value="{{ optional($booking->event_date)->format('Y-m-d') }}">
                                <input type="hidden" name="venue" value="{{ $booking->venue }}">
                                <input type="hidden" name="status" value="{{ $booking->status }}">
                                <button type="submit" name="action" value="mark_event_in_progress" class="{{ $btnPrimary }} w-full">
                                    @if($isDeliveryOrder)
                                        <i class="fa-solid fa-truck-fast" aria-hidden="true"></i>Mark Out for Delivery
                                    @else
                                        <i class="fa-solid fa-play" aria-hidden="true"></i>Mark Event In Progress
                                    @endif
                                </button>
                            </form>
                        @endif

                        @if($showMarkDeliveryReceived)
                            <form method="POST" action="{{ route('admin.bookings.delivery-received', $booking) }}" data-confirm="Mark the item as received?" data-confirm-message="Use this when the client confirmed by message or call that the order arrived. {{ $remaining > 0 ? 'The booking completes once the remaining balance is paid.' : 'The booking is fully paid, so it will be completed.' }} There is no return audit for delivery orders." data-confirm-label="Mark received">
                                @csrf
                                <button type="submit" class="{{ $btnPrimary }} w-full">
                                    <i class="fa-solid fa-box-open" aria-hidden="true"></i>Mark Item Received
                                </button>
                            </form>
                        @endif

                        @if($showMarkEventCompleted)
                            <form method="POST" action="{{ route('admin.bookings.update', ['booking' => $booking->id]) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="event_type" value="{{ $booking->event_type }}">
                                <input type="hidden" name="event_date" value="{{ optional($booking->event_date)->format('Y-m-d') }}">
                                <input type="hidden" name="venue" value="{{ $booking->venue }}">
                                <input type="hidden" name="status" value="{{ $booking->status }}">
                                <button type="submit" name="action" value="mark_event_completed" class="{{ $btnPrimary }} w-full">
                                    <i class="fa-solid fa-flag-checkered" aria-hidden="true"></i>Mark Event Completed
                                </button>
                            </form>
                        @endif

                        @if($canLogFinalPayment && $clientBalancePayment)
                            {{-- The client sent a reference for the balance: check it, then log it (or reject it). --}}
                            <form method="POST" action="{{ route('admin.payments.verify', $clientBalancePayment->id) }}" data-confirm="Log this as the final payment?" data-confirm-message="Only continue after the reference {{ $clientBalancePayment->reference_number }} matches a received payment of ₱{{ number_format((float) $clientBalancePayment->amount, 2) }} in Raflora's account." data-confirm-label="Log final payment">
                                @csrf
                                <button type="submit" class="{{ $btnPrimary }} w-full">
                                    <i class="fa-solid fa-money-bill" aria-hidden="true"></i>Log Final Payment
                                </button>
                            </form>
                            <div class="rounded-xl border border-amber-200 bg-amber-50/70 p-4" aria-labelledby="client-balance-payment-heading">
                                <div class="flex items-center justify-between gap-2">
                                    <h3 id="client-balance-payment-heading" class="text-sm font-semibold text-amber-900">Client payment to check</h3>
                                    <span class="{{ $pill }} bg-amber-100 text-amber-800"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>Awaiting verification</span>
                                </div>
                                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                    <div><dt class="text-xs text-slate-500">Amount</dt><dd class="font-semibold tabular-nums text-slate-900">₱{{ number_format((float) $clientBalancePayment->amount, 2) }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">Method</dt><dd class="font-semibold text-slate-900">{{ strtoupper(str_replace('_', ' ', $clientBalancePayment->payment_type)) }}</dd></div>
                                    <div class="col-span-2"><dt class="text-xs text-slate-500">Reference #</dt><dd class="font-mono font-semibold text-slate-900 break-all">{{ $clientBalancePayment->reference_number }}</dd></div>
                                    <div class="col-span-2"><dt class="text-xs text-slate-500">Sent</dt><dd class="text-slate-700">{{ optional($clientBalancePayment->created_at)->format('M d, Y h:i A') }}</dd></div>
                                </dl>
                                <p class="mt-3 text-xs text-amber-900">Check that this reference and amount appear in Raflora's GCash or bank account, then click <strong>Log Final Payment</strong>.</p>
                                <form method="POST" action="{{ route('admin.payments.reject', $clientBalancePayment->id) }}" class="mt-2" data-confirm="Reject this reference?" data-confirm-message="The client is told the reference could not be verified and can send a corrected one." data-confirm-label="Reject reference" data-confirm-tone="danger">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold text-rose-700 hover:text-rose-800 hover:underline">Reference doesn't match — Reject</button>
                                </form>
                            </div>
                        @elseif($canLogFinalPayment)
                            <button type="button" onclick="openBookingModal('booking-show-final-payment-modal')" class="{{ $btnPrimary }} w-full">
                                <i class="fa-solid fa-money-bill" aria-hidden="true"></i>Log Final Payment
                            </button>
                        @endif

                        @if($showReturnAuditLink)
                            @if(!$hasCompletedReturn)
                                <a href="{{ route('admin.return-tracking.manage', ['booking' => $booking->id]) }}" class="{{ $btnSecondary }} w-full">
                                    <i class="fa-solid fa-box-open" aria-hidden="true"></i>Manage Return Audit
                                </a>
                            @else
                                <a href="{{ route('admin.return-tracking.manage', ['booking' => $booking->id]) }}" class="{{ $btnSecondary }} w-full">
                                    <i class="fa-solid fa-box-open" aria-hidden="true"></i>View Return Audit
                                </a>
                            @endif
                        @endif
                    </div>

                    @if($canLogFinalPayment && !$clientBalancePayment)
                        <div id="booking-show-final-payment-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4" role="dialog" aria-modal="true" aria-labelledby="final-payment-title" onclick="closeBookingModal('booking-show-final-payment-modal')">
                            <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl" onclick="event.stopPropagation()">
                                <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                                    <div>
                                        <h3 id="final-payment-title" class="text-base font-semibold text-slate-900">Log Final Payment</h3>
                                        <p class="text-sm text-slate-500">#{{ $booking->id }} • {{ ucfirst($booking->event_type) }}</p>
                                    </div>
                                    <button type="button" onclick="closeBookingModal('booking-show-final-payment-modal')" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </div>

                                <div class="space-y-5 p-6">
                                    <dl class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-2 text-sm">
                                        <div class="flex justify-between"><dt class="text-slate-600">Total Obligation</dt><dd class="font-semibold tabular-nums">₱{{ number_format($totalObligation, 2) }}</dd></div>
                                        <div class="flex justify-between"><dt class="text-slate-600">Already Paid</dt><dd class="font-semibold tabular-nums">₱{{ number_format($paidAmount, 2) }}</dd></div>
                                        <div class="flex justify-between border-t border-slate-200 pt-2"><dt class="font-medium text-slate-800">Remaining Balance</dt><dd class="font-semibold tabular-nums">₱{{ number_format($remaining, 2) }}</dd></div>
                                    </dl>

                                    <form method="POST" action="{{ route('admin.bookings.final_payment', ['booking' => $booking->id]) }}" class="space-y-4">
                                        @csrf
                                        <div>
                                            <label for="final-payment-amount" class="{{ $fieldLabel }}">Final Payment Amount Received</label>
                                            <input id="final-payment-amount" type="number" step="0.01" name="amount_received" value="{{ number_format($remaining, 2, '.', '') }}" class="{{ $fieldInput }}">
                                        </div>

                                        <div>
                                            <label for="final-payment-method" class="{{ $fieldLabel }}">Payment Method</label>
                                            <select id="final-payment-method" name="payment_type" class="{{ $fieldInput }}">
                                                <option value="gcash">GCash</option>
                                                <option value="bank_transfer">Bank Transfer</option>
                                                <option value="cash">Cash</option>
                                            </select>
                                        </div>

                                        <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                                            <button type="button" onclick="closeBookingModal('booking-show-final-payment-modal')" class="{{ $btnSecondary }}">Close</button>
                                            <button type="submit" class="{{ $btnPrimary }}">Confirm Final Payment</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endif
                </section>
            @endif
        </div>

        @if($booking->payments->count() > 0)
            <section class="rf-admin-card overflow-hidden" aria-labelledby="payments-heading">
                <div class="{{ $cardHeader }}">
                    <div>
                        <h2 id="payments-heading" class="{{ $cardTitle }}">Submitted Payments</h2>
                        <p class="{{ $cardText }}">A submitted payment counts toward the balance only after you verify it.</p>
                    </div>
                    @if($pendingPaymentCount > 0)
                        <span class="{{ $pill }} bg-amber-100 text-amber-800"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>{{ $pendingPaymentCount }} awaiting verification</span>
                    @endif
                </div>
                <ul class="divide-y divide-slate-100" role="list">
                    @foreach($booking->payments as $payment)
                        @php
                            $paymentTone = match ($payment->status) {
                                'pending' => 'bg-amber-100 text-amber-800',
                                'rejected' => 'bg-rose-100 text-rose-800',
                                default => 'bg-emerald-100 text-emerald-800',
                            };
                        @endphp
                        <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-start gap-3 min-w-0">
                                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500" aria-hidden="true"><i class="fa-solid fa-receipt text-sm"></i></span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-900">
                                        Amount: <span class="tabular-nums">₱{{ number_format($payment->amount, 2) }}</span>
                                        <span class="ml-1.5 text-xs font-medium text-slate-500">{{ $payment->payment_option }}</span>
                                    </p>
                                    <p class="mt-0.5 text-[13px] text-slate-600">Ref: <span class="font-mono">{{ $payment->reference_number }}</span> · {{ strtoupper($payment->payment_type) }}</p>
                                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                        <span class="{{ $pill }} {{ $paymentTone }}">Status: {{ ucfirst($payment->status) }}</span>
                                        @if($payment->status !== 'pending' && $payment->verified_by)
                                            <span>Verified at {{ optional($payment->verified_at)->format('M d, Y h:i A') }}</span>
                                        @endif
                                    </p>
                                </div>
                            </div>
                            @if($payment->status === 'pending' && $clientBalancePayment && $clientBalancePayment->is($payment))
                                <p class="shrink-0 text-xs font-medium text-amber-800">Check and log it under <a href="#operational-actions-heading" class="underline">Log Final Payment</a>.</p>
                            @elseif($payment->status === 'pending')
                                <div class="flex shrink-0 gap-2">
                                    <form method="POST" action="{{ route('admin.payments.reject', $payment->id) }}" data-confirm="Reject this payment?" data-confirm-message="The client will be told the payment could not be verified. The booking stays unconfirmed until a valid payment is verified." data-confirm-label="Reject payment" data-confirm-tone="danger">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3.5 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 transition">Reject</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.payments.verify', $payment->id) }}" data-confirm="Verify this payment?" data-confirm-message="Only verify after checking the reference against Raflora's account. Verifying records the payment and can confirm the booking." data-confirm-label="Verify payment">
                                        @csrf
                                        <button type="submit" class="{{ $btnPrimary }}"><i class="fa-solid fa-check" aria-hidden="true"></i>Verify</button>
                                    </form>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    {{-- ============================== SECTION POP-UP ============================== --}}
    {{-- Every section below opens in this pop-up over the Overview; its forms and actions are unchanged. --}}
    <div id="booking-section-modal" class="fixed inset-0 z-[45] hidden items-center justify-center overflow-hidden bg-slate-950/50 p-2 sm:p-4 lg:p-6" role="dialog" aria-modal="true" aria-labelledby="booking-section-modal-title">
        <div class="relative isolate flex max-h-full w-full min-w-0 max-w-6xl flex-col overflow-hidden rounded-2xl bg-slate-50 shadow-2xl focus:outline-none" tabindex="-1" data-section-modal-panel>
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 sm:px-5">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700" aria-hidden="true"><i id="booking-section-modal-icon" class="fa-solid fa-table-columns text-sm"></i></span>
                    <div class="min-w-0">
                        <h2 id="booking-section-modal-title" class="truncate text-base font-semibold text-slate-900">Section</h2>
                        <p class="truncate text-xs text-slate-500">{{ $bookingHeading }} · {{ $bookingClientName }}</p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <span id="booking-section-modal-state" class="hidden rounded-full px-2 py-0.5 text-[11px] font-semibold"></span>
                    <button type="button" onclick="switchTab('tab-overview')" class="{{ $btnSecondary }} max-sm:!hidden"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i> Back to Overview</button>
                    <button type="button" onclick="switchTab('tab-overview')" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="Close section"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </div>
            </div>
            <div id="booking-section-modal-body" class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-3 [scrollbar-gutter:stable] sm:p-5">

    {{-- ============================== EVENT DETAILS & AI ============================== --}}
    <div id="tab-details" class="tab-content hidden space-y-6" role="tabpanel" aria-labelledby="tab-details-button" tabindex="0">
        <section class="rf-admin-card overflow-hidden" aria-labelledby="event-details-heading">
            <div class="{{ $cardHeader }}">
                <div>
                    <h2 id="event-details-heading" class="{{ $cardTitle }}">Event Details &amp; Inspiration</h2>
                    <p class="{{ $cardText }}">What the client asked for, as submitted with the booking.</p>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-6 p-5 lg:grid-cols-12">
                <figure class="lg:col-span-4">
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                        @if($booking->inspiration_image)
                            <a href="{{ route('secure.inspiration.show', $booking->id) }}" target="_blank" rel="noopener" class="group relative block aspect-[4/5]">
                                <img src="{{ route('secure.inspiration.show', $booking->id) }}" alt="Inspiration Image" class="absolute inset-0 h-full w-full object-cover">
                                <span class="absolute bottom-2 right-2 inline-flex items-center gap-1.5 rounded-md bg-slate-900/70 px-2 py-1 text-[11px] font-medium text-white opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">
                                    <i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i> Open full size
                                </span>
                            </a>
                        @else
                            <div class="flex aspect-[4/5] flex-col items-center justify-center gap-2 text-slate-400">
                                <i class="fa-regular fa-image text-3xl" aria-hidden="true"></i>
                                <span class="text-sm">No Image Provided</span>
                            </div>
                        @endif
                    </div>
                    <figcaption class="mt-2 text-xs text-slate-500">Client inspiration image</figcaption>
                </figure>

                <div class="lg:col-span-8 space-y-5">
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Event Type</dt>
                            <dd class="mt-0.5 text-sm font-medium text-slate-900">{{ ucfirst($booking->event_type) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Event Date</dt>
                            <dd class="mt-0.5 text-sm font-medium text-slate-900">{{ optional($booking->event_date)->format('F j, Y') ?? 'N/A' }}@if($bookingEventTime) · {{ $bookingEventTime }}@endif</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium text-slate-500">Venue</dt>
                            <dd class="mt-0.5 text-sm font-medium text-slate-900">{{ $booking->venue ?? 'N/A' }}</dd>
                            <x-venue-map :booking="$booking" class="mt-2" />
                        </div>
                        @if($booking->event_size)
                            <div>
                                <dt class="text-xs font-medium text-slate-500">Event Size</dt>
                                <dd class="mt-0.5 text-sm font-medium text-slate-900">{{ ucfirst((string) $booking->event_size) }}</dd>
                            </div>
                        @endif
                        @if($booking->table_count)
                            <div>
                                <dt class="text-xs font-medium text-slate-500">Tables</dt>
                                <dd class="mt-0.5 text-sm font-medium text-slate-900 tabular-nums">{{ $booking->table_count }}</dd>
                            </div>
                        @endif
                        @if($booking->setup_type)
                            <div>
                                <dt class="text-xs font-medium text-slate-500">Setup Type</dt>
                                <dd class="mt-0.5 text-sm font-medium text-slate-900">{{ \Illuminate\Support\Str::headline((string) $booking->setup_type) }}</dd>
                            </div>
                        @endif
                        @if($booking->package)
                            <div>
                                <dt class="text-xs font-medium text-slate-500">Package</dt>
                                <dd class="mt-0.5 text-sm font-medium text-slate-900">{{ $booking->package->title }}</dd>
                            </div>
                        @endif
                    </dl>

                    <x-design-brief-summary :booking="$booking" />

                    <div class="rounded-xl border border-slate-200 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Client contact</p>
                        <dl class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2 text-sm">
                            <div class="flex items-center gap-2 min-w-0">
                                <i class="fa-solid fa-phone text-xs text-slate-400" aria-hidden="true"></i>
                                <dt class="sr-only">Client Mobile</dt>
                                <dd class="font-medium text-slate-800 truncate">{{ $booking->client?->phone ?? $booking->guest_phone ?? 'Not provided' }}</dd>
                            </div>
                            <div class="flex items-center gap-2 min-w-0">
                                <i class="fa-regular fa-envelope text-xs text-slate-400" aria-hidden="true"></i>
                                <dt class="sr-only">Client Email</dt>
                                <dd class="font-medium text-slate-800 truncate">{{ $booking->client?->email ?? $booking->guest_email ?? 'Not provided' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div>
                        <p class="text-xs font-medium text-slate-500 mb-1.5">Special Request Notes from Client</p>
                        <div class="min-h-[5rem] whitespace-pre-line rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm leading-relaxed text-slate-700">{{ $booking->special_requests ?: 'No special requests provided.' }}</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="rf-admin-card overflow-hidden" aria-labelledby="ai-analysis-heading">
            <div class="{{ $cardHeader }}">
                <div>
                    <h2 id="ai-analysis-heading" class="{{ $cardTitle }} flex items-center gap-2">
                        <i class="fa-solid fa-wand-magic-sparkles text-brand-700 text-sm" aria-hidden="true"></i> AI Material Analysis
                    </h2>
                    <p class="{{ $cardText }}">
                        @if($analysis && $analysis->analyzed_at)
                            Analyzed {{ $analysis->analyzed_at->format('M j, Y g:i A') }} · {{ $aiSuggestions->count() }} {{ \Illuminate\Support\Str::plural('suggestion', $aiSuggestions->count()) }}
                        @else
                            Materials the AI identified from the inspiration image.
                        @endif
                    </p>
                </div>
                @if($aiSuggestions->isNotEmpty())
                    <button type="button" onclick="switchTab('tab-quotation')" class="{{ $btnSecondary }} shrink-0">
                        Review in Quotation <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </button>
                @endif
            </div>

            @if($aiSuggestions->isEmpty())
                <div class="flex flex-col items-center justify-center gap-2 px-5 py-10 text-center">
                    <i class="fa-solid fa-wand-magic-sparkles text-2xl text-slate-300" aria-hidden="true"></i>
                    <p class="text-sm font-medium text-slate-700">No AI analysis recorded for this booking</p>
                    <p class="text-xs text-slate-500">Suggestions appear here after the inspiration image has been analyzed.</p>
                </div>
            @else
                <div class="flex items-start gap-2.5 border-b border-slate-100 bg-amber-50/60 px-5 py-3 text-[13px] text-amber-900">
                    <i class="fa-solid fa-circle-info mt-0.5 text-amber-600" aria-hidden="true"></i>
                    <p>AI suggestions are decision support only. Confirm, link, or promote each material in the Quotation tab before sending the official quote.@if($aiNeedsReviewCount > 0) <strong>{{ $aiNeedsReviewCount }}</strong> {{ \Illuminate\Support\Str::plural('item', $aiNeedsReviewCount) }} flagged for review.@endif</p>
                </div>
                <x-decor-area-breakdown :materials="$aiSuggestions->all()" :booking="$booking" view="admin" class="mx-5 my-4" />
                <div class="overflow-x-auto">
                    <table class="rf-table--stack w-full min-w-[720px] text-left text-[13px]">
                        <thead class="rf-admin-thead">
                            <tr>
                                <th scope="col" class="!pl-5">Material</th>
                                <th scope="col">Category</th>
                                <th scope="col" class="!text-right">Est. Quantity</th>
                                <th scope="col">Placement</th>
                                <th scope="col" class="!text-right">Confidence</th>
                                <th scope="col" class="!pr-5">Review</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @foreach($aiSuggestions as $suggestion)
                                @php
                                    $suggestedQty = $suggestion['quantity'] ?? $suggestion['estimated_quantity'] ?? null;
                                    $confidence = isset($suggestion['confidence']) && is_numeric($suggestion['confidence']) ? (float) $suggestion['confidence'] : null;
                                @endphp
                                <tr class="align-top hover:bg-slate-50/70">
                                    <td class="py-3 pl-5 pr-4" data-label="Material">
                                        <p class="font-medium text-slate-900">{{ $suggestion['item_name'] ?? 'Unnamed material' }}</p>
                                        @if(!empty($suggestion['note']))
                                            <p class="mt-0.5 text-xs text-slate-500">{{ $suggestion['note'] }}</p>
                                        @endif
                                        @if(!empty($suggestion['suggested_alternative']))
                                            <p class="mt-0.5 text-xs text-slate-500">Alternative: {{ $suggestion['suggested_alternative'] }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3" data-label="Category">{{ !empty($suggestion['category']) ? ucfirst($suggestion['category']) : '—' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap" data-label="Est. Quantity">
                                        @if(is_numeric($suggestedQty))
                                            {{ $formatMaterialQty($suggestedQty) }} <span class="text-slate-500">{{ $suggestion['unit_type'] ?? '' }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3" data-label="Placement">{{ !empty($suggestion['area']) ? ucfirst($suggestion['area']) : '—' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums" data-label="Confidence">{{ $confidence !== null ? round($confidence * 100) . '%' : '—' }}</td>
                                    <td class="py-3 pl-4 pr-5" data-label="Review">
                                        @if(!empty($suggestion['needs_review']))
                                            <span class="{{ $pill }} bg-amber-100 text-amber-800" title="{{ $suggestion['review_message'] ?? '' }}"><i class="fa-solid fa-flag" aria-hidden="true"></i>Needs review</span>
                                        @elseif(!empty($suggestion['is_recommendation']))
                                            <span class="{{ $pill }} bg-slate-100 text-slate-600">Recommendation</span>
                                        @elseif(!empty($suggestion['is_detected']))
                                            <span class="{{ $pill }} bg-navy-50 text-navy-700">Detected in image</span>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    {{-- ============================== QUOTATION ============================== --}}
    <div id="tab-quotation" class="tab-content hidden space-y-6" role="tabpanel" aria-labelledby="tab-quotation-button" tabindex="0">
        @if(isset($activeQuotation))
            <section class="rf-admin-card border-brand-200" aria-label="Active issued quotation">
                <div class="flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700" aria-hidden="true"><i class="fa-solid fa-file-signature"></i></span>
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">Active Issued Quotation (v{{ $activeQuotation->version }})</h3>
                            <p class="text-xs text-slate-500">The version the client currently sees.</p>
                        </div>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm sm:grid-cols-4">
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Total Cost</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900 tabular-nums">₱{{ number_format($activeQuotation->final_quoted_price, 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Valid Until</dt>
                            <dd class="mt-0.5 font-medium text-slate-900">{{ optional($activeQuotation->valid_until)->format('M d, Y') ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Status</dt>
                            <dd class="mt-0.5 font-medium text-slate-900">{{ ucfirst($activeQuotation->status) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Issued By</dt>
                            <dd class="mt-0.5 font-medium text-slate-900">{{ $activeQuotation->issuedBy?->name ?? 'Admin' }}</dd>
                        </div>
                    </dl>
                </div>
            </section>
        @endif

        <!-- Item & pricing breakdown -->
        <section class="rf-admin-card" aria-labelledby="pricing-breakdown-heading">
            <div class="{{ $cardHeader }}">
                <div>
                    <h2 id="pricing-breakdown-heading" class="{{ $cardTitle }}">Material review &amp; quotation</h2>
                    <p class="{{ $cardText }} max-w-3xl">AI-suggested materials remain awaiting Admin confirmation until explicitly reviewed. Stock indicators reflect the existing inventory state.</p>
                </div>
                @php
                    // Same rows that show a "Confirm Material" button: AI suggestions already linked to inventory.
                    $readyToConfirm = $booking->bookingItems->filter(fn ($i) => $i->is_ai_suggested && !$i->confirmed_at && $i->inventory_item_id);
                    $readyZeroPriceCount = $readyToConfirm->filter(fn ($i) => (float) $i->quoted_unit_price <= 0)->count();
                @endphp
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    @if($materialItems->isNotEmpty())
                        <span class="{{ $pill }} {{ $allMaterialsValidated ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $validatedMaterialCount }}/{{ $materialItems->count() }} validated</span>
                    @endif
                    @if($readyToConfirm->isNotEmpty())
                        <form id="confirmAllMaterialsForm" method="POST" action="{{ route('admin.bookings.items.confirm-all', $booking) }}"
                              data-confirm="Confirm {{ $readyToConfirm->count() }} {{ \Illuminate\Support\Str::plural('material', $readyToConfirm->count()) }}?"
                              data-confirm-message="Every linked AI suggestion awaiting confirmation is confirmed with the quantity and unit cost shown in the table, and those values are saved.{{ $readyZeroPriceCount > 0 ? ' ' . $readyZeroPriceCount . ' of them still have a ₱0.00 unit cost.' : '' }} Unmatched suggestions still need Link or Promote."
                              data-confirm-label="Confirm all">
                            @csrf
                            @unless($quoteReadOnly)
                                {{-- Kept in step with the table by syncConfirmLineValues(); saved with the confirmation. --}}
                                @foreach($readyToConfirm as $readyItem)
                                    <input type="hidden" name="lines[{{ $readyItem->id }}][quantity]" value="{{ (int) $readyItem->quantity }}" data-sync-item="{{ $readyItem->id }}" data-sync-field="quantity" data-original-quantity="{{ (float) $readyItem->quantity }}" data-original-price="{{ (float) $readyItem->quoted_unit_price }}">
                                    <input type="hidden" name="lines[{{ $readyItem->id }}][unit_price]" value="{{ number_format((float) $readyItem->quoted_unit_price, 2, '.', '') }}" data-sync-item="{{ $readyItem->id }}" data-sync-field="unit_price" data-original-quantity="{{ (float) $readyItem->quantity }}" data-original-price="{{ (float) $readyItem->quoted_unit_price }}">
                                @endforeach
                            @endunless
                            <button type="submit" class="{{ $btnPrimary }} py-1.5 text-xs">
                                <i class="fa-solid fa-check-double" aria-hidden="true"></i>Confirm all linked ({{ $readyToConfirm->count() }})
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <form id="mainBookingForm" method="POST" action="{{ route('admin.bookings.update', ['booking' => $booking->id]) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="event_type" value="{{ $booking->event_type }}">
                <input type="hidden" name="event_date" value="{{ optional($booking->event_date)->format('Y-m-d') }}">
                <input type="hidden" name="venue" value="{{ $booking->venue }}">
                <input type="hidden" name="status" value="{{ $booking->status }}">

                <div class="overflow-x-auto">
                    <table class="rf-table--stack w-full min-w-[760px] text-left text-sm">
                        <thead class="rf-admin-thead">
                            <tr>
                                <th scope="col" class="!pl-5">Item Name</th>
                                <th scope="col" class="w-28">Qty</th>
                                <th scope="col" class="w-40">Unit Cost (₱)</th>
                                <th scope="col" class="w-36 !text-right">Total Cost (₱)</th>
                                <th scope="col" class="w-28 !pr-5 !text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @php
                                $itemsToDisplay = [];
                                $seenItemKeys = [];
                                // Keep source booking lines separate until they are linked and confirmed.
                                $addDisplayItem = function ($id, $name, $qty, $unitPrice, $inventoryId = null, $isAiSuggested = false, $notes = null, $confirmedAt = null, $procurementStatus = null) use (&$itemsToDisplay, &$seenItemKeys) {
                                    $normalizedName = strtolower(trim((string) $name));
                                    if ($normalizedName === '') {
                                        return;
                                    }

                                    $alreadyListed = isset($seenItemKeys[$normalizedName]);
                                    if ($alreadyListed && empty($id)) {
                                        return;
                                    }

                                    $seenItemKeys[$normalizedName] = true;
                                    $itemsToDisplay[] = [
                                        'duplicate' => $alreadyListed,
                                        'id' => $id,
                                        'name' => $name,
                                        'qty' => $qty,
                                        'unit_price' => $unitPrice,
                                        'inventory_id' => $inventoryId,
                                        'is_ai_suggested' => $isAiSuggested,
                                        'notes' => $notes,
                                        'confirmed_at' => $confirmedAt,
                                        'procurement_status' => $procurementStatus,
                                        'source_booking_item_ids' => $id ? [(int) $id] : [],
                                    ];
                                };

                                if ($booking->bookingItems->count() > 0) {
                                    foreach ($booking->bookingItems as $bookingItem) {
                                        $addDisplayItem(
                                            $bookingItem->id,
                                            $bookingItem->item_name ?? ($bookingItem->inventoryItem?->name ?? 'Unknown Item'),
                                            $bookingItem->quantity,
                                            $bookingItem->quoted_unit_price,
                                            $bookingItem->inventory_item_id,
                                            (bool) $bookingItem->is_ai_suggested,
                                            $bookingItem->notes,
                                            $bookingItem->confirmed_at,
                                            $bookingItem->procurement_status,
                                        );
                                    }
                                }

                                if ($analysis) {
                                    foreach ($analysis->suggested_materials as $item) {
                                        $suggestionName = $item['item_name'] ?? 'Unknown Item';
                                        $suggestionKey = \App\Models\InventoryItem::nameKey($suggestionName);
                                        $suggestionSingularKey = \App\Models\InventoryItem::singularNameKey($suggestionName);
                                        $suggestionIdentity = \App\Models\InventoryItem::materialIdentityKey($suggestionName);
                                        $alreadyLinked = $booking->bookingItems->contains(function ($bookingItem) use ($suggestionKey, $suggestionSingularKey, $suggestionIdentity, $allInventoryItems): bool {
                                            if (!$bookingItem->inventory_item_id) {
                                                return false;
                                            }

                                            $linkedInventory = $allInventoryItems->firstWhere('id', $bookingItem->inventory_item_id);
                                            if (!$linkedInventory) {
                                                return false;
                                            }

                                            return \App\Models\InventoryItem::nameKey($bookingItem->item_name) === $suggestionKey
                                                || \App\Models\InventoryItem::singularNameKey($bookingItem->item_name) === $suggestionSingularKey
                                                || \App\Models\InventoryItem::singularNameKey($linkedInventory->name) === $suggestionSingularKey
                                                || ($suggestionIdentity !== ''
                                                    && \App\Models\InventoryItem::materialIdentityKey($linkedInventory->name) === $suggestionIdentity);
                                        });
                                        if ($alreadyLinked) {
                                            continue;
                                        }

                                        $addDisplayItem(
                                            null,
                                            $suggestionName,
                                            $item['quantity'] ?? $item['estimated_quantity'] ?? 0,
                                            $item['estimated_unit_cost_php'] ?? 0,
                                            null,
                                            true,
                                            null,
                                            null,
                                        );
                                    }
                                }

                                if (empty($itemsToDisplay) && $booking->inventoryItems->count() > 0) {
                                    foreach ($booking->inventoryItems as $inv) {
                                        $addDisplayItem(
                                            null,
                                            $inv->name,
                                            $inv->pivot->quantity,
                                            $inv->pivot->quoted_unit_price,
                                            $inv->id,
                                            false,
                                            null,
                                            null,
                                        );
                                    }
                                }

                                $itemsToDisplay = collect($itemsToDisplay)
                                    ->groupBy(function ($item): string {
                                        if (!empty($item['id']) && !empty($item['inventory_id'])) {
                                            return implode(':', [
                                                'inventory',
                                                $item['inventory_id'],
                                                'ai',
                                                (int) $item['is_ai_suggested'],
                                                'confirmed',
                                                (int) !empty($item['confirmed_at']),
                                                'removed',
                                                (int) ($item['procurement_status'] === \App\Services\QuotationPricingService::REMOVED_FROM_QUOTATION),
                                            ]);
                                        }

                                        return 'line:' . ($item['id'] ?? uniqid('', true));
                                    })
                                    ->map(function ($group) {
                                        $item = $group->first();
                                        $quantity = (float) $group->sum(fn ($line) => (float) $line['qty']);
                                        $amount = (float) $group->sum(fn ($line) => (float) $line['qty'] * (float) $line['unit_price']);

                                        $item['qty'] = $quantity;
                                        $item['unit_price'] = $quantity > 0 ? $amount / $quantity : (float) $item['unit_price'];
                                        $item['source_booking_item_ids'] = $group->pluck('source_booking_item_ids')->flatten()->unique()->values()->all();
                                        $item['source_count'] = count($item['source_booking_item_ids']);
                                        $item['duplicate'] = $item['source_count'] === 1 && $item['duplicate'];

                                        return $item;
                                    })
                                    ->values()
                                    ->all();
                            @endphp

                            @php
                                // After a refused save, refill each line's typed Qty / Unit Cost (flashed input, keyed by line).
                                $typedLines = collect(old('items', []))
                                    ->filter(fn ($line) => is_array($line) && !empty($line['booking_item_id']))
                                    ->keyBy('booking_item_id');
                            @endphp
                            @forelse($itemsToDisplay as $idx => $item)
                                @php
                                    $typedLinesForItem = collect($item['source_booking_item_ids'] ?? [(int) ($item['id'] ?? 0)])
                                        ->map(fn ($sourceId) => $typedLines->get((string) $sourceId))
                                        ->filter(fn ($line) => is_array($line) && is_numeric($line['quantity'] ?? null));
                                    $typedLine = !empty($item['id']) ? $typedLines->get((string) $item['id']) : null;
                                    $shownQty = $typedLinesForItem->isNotEmpty()
                                        ? $typedLinesForItem->sum(fn ($line) => (float) $line['quantity'])
                                        : $item['qty'];
                                    $shownPrice = (is_array($typedLine) && is_numeric($typedLine['unit_price'] ?? null)) ? $typedLine['unit_price'] : $item['unit_price'];
                                    $invData = isset($allInventoryItems) ? $allInventoryItems->firstWhere('name', $item['name']) : null;
                                    $isOutOfStock = $invData ? ($item['qty'] > $invData->current_stock) : false;
                                    $stockStr = $invData ? floatval($invData->current_stock) : 0;
                                    $isUnmatchedSuggestion = $item['is_ai_suggested'] && empty($item['inventory_id']);
                                    $isConfirmed = !empty($item['confirmed_at']);
                                    $rowClass = $isUnmatchedSuggestion ? 'bg-amber-50/40' : ($isOutOfStock ? 'bg-rose-50/40' : '');
                                    $stockBadgeClass = $isUnmatchedSuggestion ? 'bg-amber-100 text-amber-800' : ($isOutOfStock ? 'bg-rose-100 text-rose-800' : 'bg-emerald-50 text-emerald-700');
                                    $stockBadgeLabel = $isUnmatchedSuggestion ? 'Unmatched' : ($isOutOfStock ? 'Out of Stock' : 'In Stock');
                                    $stockTitle = $isOutOfStock ? 'Only ' . $stockStr . ' left in master inventory' : 'Inventory remains available for this item';
                                @endphp
                                <tr class="{{ $rowClass }} align-top transition hover:bg-slate-50/80" id="row_{{ $idx }}">
                                    <td class="py-3 pl-5 pr-4" data-label="Item Name">
                                        <span class="block font-medium text-slate-900" id="display_name_{{ $idx }}">{{ $item['name'] }}</span>
                                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                            @if(($item['source_count'] ?? 1) > 1)
                                                <span class="{{ $pill }} bg-sky-100 text-sky-800" title="Lines linked to the same inventory item are combined here. Their source booking lines remain separate.">Combined {{ $item['source_count'] }} lines</span>
                                            @elseif(!empty($item['duplicate']))
                                                <span class="{{ $pill }} bg-sky-100 text-sky-800" title="Another line above has the same name (e.g. suggested for a second photo or area). Both are quoted: price both, or tick Remove on one.">Listed twice</span>
                                            @endif
                                            @if($isUnmatchedSuggestion)
                                                <span class="{{ $pill }} bg-amber-100 text-amber-800">Unmatched / AI Suggestion</span>
                                            @elseif($isConfirmed)
                                                <span class="{{ $pill }} bg-emerald-100 text-emerald-800"><i class="fa-solid fa-check" aria-hidden="true"></i>{{ $item['is_ai_suggested'] ? 'Admin Confirmed' : 'Package Inclusion' }}</span>
                                                <span class="{{ $pill }} {{ $stockBadgeClass }}" @if($isOutOfStock) data-stock-badge="out" @endif title="{{ $stockTitle }}">{{ $stockBadgeLabel }}</span>
                                            @else
                                                @if($item['is_ai_suggested'])
                                                    <span class="{{ $pill }} bg-amber-100 text-amber-800">AI Suggested - Awaiting Admin Confirmation</span>
                                                @endif
                                                <span class="{{ $pill }} {{ $stockBadgeClass }}" @if($isOutOfStock) data-stock-badge="out" @endif title="{{ $stockTitle }}">{{ $stockBadgeLabel }}</span>
                                            @endif
                                        </div>
                                        @if($isUnmatchedSuggestion || ($item['is_ai_suggested'] && !$isConfirmed && !empty($item['id']) && !empty($item['inventory_id'])) || (!$quoteReadOnly && $isOutOfStock && $invData && $invData->substitutes->count() > 0))
                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                @if($isUnmatchedSuggestion)
                                                    <button type="button" onclick="openAiMaterialModal({{ $idx }}, 'link')" class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"><i class="fa-solid fa-link text-[10px]" aria-hidden="true"></i>Link to Existing Inventory</button>
                                                    <button type="button" onclick="openAiMaterialModal({{ $idx }}, 'promote')" class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"><i class="fa-solid fa-plus text-[10px]" aria-hidden="true"></i>Promote to Catalog</button>
                                                @elseif($item['is_ai_suggested'] && !$isConfirmed && !empty($item['id']) && !empty($item['inventory_id']))
                                                    <button type="submit" form="confirmMaterial_{{ $idx }}" class="inline-flex items-center rounded-md bg-brand-700 px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-800">Confirm Material</button>
                                                @endif
                                                @if(!$quoteReadOnly && $isOutOfStock && $invData && $invData->substitutes->count() > 0)
                                                    <button type="button" onclick="openSubstituteModal({{ $idx }}, {{ $invData->id }})" class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"><i class="fa-solid fa-rotate text-[10px]" aria-hidden="true"></i>Substitute</button>
                                                @endif
                                            </div>
                                        @endif
                                        <input type="hidden" id="input_name_{{ $idx }}" name="items[{{ $idx }}][item_name]" value="{{ $item['name'] }}">
                                        <input type="hidden" id="input_inventory_{{ $idx }}" name="items[{{ $idx }}][inventory_item_id]" value="{{ $item['inventory_id'] ?? '' }}">
                                        <input type="hidden" name="items[{{ $idx }}][is_ai_suggested]" value="{{ !empty($item['is_ai_suggested']) ? '1' : '0' }}">
                                        @if(!empty($item['id']))
                                            <input type="hidden" name="items[{{ $idx }}][booking_item_id]" value="{{ $item['id'] }}">
                                            @if(($item['source_count'] ?? 1) > 1)
                                                @foreach($item['source_booking_item_ids'] as $sourceBookingItemId)
                                                    <input type="hidden" name="items[{{ $idx }}][source_booking_item_ids][]" value="{{ $sourceBookingItemId }}">
                                                @endforeach
                                            @endif
                                        @endif
                                    </td>
                                    <td class="px-4 py-3" data-label="Qty">
                                        @if($quoteReadOnly)
                                            <div class="pt-1.5 text-sm text-slate-800 tabular-nums">{{ (int) $item['qty'] }}</div>
                                        @else
                                            <label class="sr-only" for="input_qty_{{ $idx }}">Quantity for {{ $item['name'] }}</label>
                                            <input type="number" id="input_qty_{{ $idx }}" name="items[{{ $idx }}][quantity]" min="0" step="1" inputmode="numeric" value="{{ (int) $shownQty }}" class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm tabular-nums focus:border-brand-600 focus:ring-2 focus:ring-brand-500/20 focus:outline-none" @if($bookingLocked) disabled @endif>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3" data-label="Unit Cost (₱)">
                                        @if($quoteReadOnly)
                                            <div class="pt-1.5 text-sm text-slate-800 tabular-nums">₱ {{ number_format((float) $item['unit_price'], 2) }}</div>
                                        @else
                                            <div class="relative">
                                                <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-sm text-slate-400">₱</span>
                                                <label class="sr-only" for="input_price_{{ $idx }}">Unit cost for {{ $item['name'] }}</label>
                                                <input type="number" step="0.01" id="input_price_{{ $idx }}" name="items[{{ $idx }}][unit_price]" min="0" value="{{ number_format((float) $shownPrice, 2, '.', '') }}" class="w-full rounded-md border border-slate-300 bg-white py-1.5 pl-7 pr-2.5 text-sm tabular-nums focus:border-brand-600 focus:ring-2 focus:ring-brand-500/20 focus:outline-none" @if($bookingLocked) disabled @endif>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 pt-4 text-right text-sm font-medium text-slate-900 tabular-nums whitespace-nowrap" id="total_cost_{{ $idx }}" data-label="Total Cost (₱)">
                                        ₱ {{ number_format((float) $item['qty'] * (float) $item['unit_price'], 2) }}
                                    </td>
                                    <td class="py-3 pl-4 pr-5 text-center" data-label="Actions">
                                        @if(!$quoteReadOnly)
                                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-semibold text-slate-500 transition hover:bg-rose-50 hover:text-rose-700">
                                                <input type="checkbox" name="items[{{ $idx }}][remove]" value="1" class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500" @if($bookingLocked) disabled @endif>
                                                <span>Remove</span>
                                            </label>
                                        @else
                                            <span class="text-xs text-slate-500">Read-only</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-500">No items suggested or added.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @php
                    $quotationExpired = $booking->status === 'quotation_sent'
                        && $booking->price_valid_until
                        && \Carbon\Carbon::parse($booking->price_valid_until)->isPast();
                    $tentativeQuote = $booking->tentativeQuotation();
                    $hasTentative = !is_null($tentativeQuote);
                    $reconfirmationDue = $booking->isPriceReconfirmationDue();
                @endphp

                <div class="space-y-5 border-t border-slate-200 p-5">
                    @if($hasTentative)
                        <div class="rounded-xl border {{ $reconfirmationDue ? 'border-amber-300 bg-amber-50' : 'border-brand-200 bg-brand-50/60' }} p-4">
                            <div class="flex items-start gap-3">
                                <i class="fa-solid {{ $reconfirmationDue ? 'fa-clock-rotate-left text-amber-600' : 'fa-circle-info text-brand-700' }} mt-0.5 text-lg" aria-hidden="true"></i>
                                <div class="flex-1">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <h4 class="text-sm font-semibold {{ $reconfirmationDue ? 'text-amber-900' : 'text-brand-900' }}">
                                            {{ $reconfirmationDue ? 'Price Reconfirmation Due (Event Approaches in <=' . \App\Models\Setting::getPriceReconfirmationThresholdDays() . ' Days)' : 'Tentative Long-Term Floral Pricing' }}
                                        </h4>
                                        <span class="inline-flex rounded-full {{ $reconfirmationDue ? 'bg-amber-200 text-amber-900' : 'bg-brand-100 text-brand-900' }} px-2.5 py-0.5 text-xs font-semibold">
                                            v{{ $tentativeQuote->version }} Tentative
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-600">
                                        This quotation's pricing is tentative due to long-term floral market volatility.
                                        Review current material costs, inventory availability, and verified client payments before event execution.
                                    </p>

                                    <dl class="mt-3 grid grid-cols-2 gap-3 border-t border-slate-200/70 pt-3 text-xs sm:grid-cols-4">
                                        <div>
                                            <dt class="text-slate-500">Quoted Price (v{{ $tentativeQuote->version }})</dt>
                                            <dd class="font-semibold text-slate-800 tabular-nums">₱{{ number_format((float) $tentativeQuote->final_quoted_price, 2) }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">Current BOM Subtotal</dt>
                                            <dd class="font-semibold text-slate-800 tabular-nums">₱{{ number_format((float) (($booking->raw_materials_sum ?? 0) * ($booking->multiplier ?? 3.0)), 2) }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">Verified Payments</dt>
                                            <dd class="font-semibold text-emerald-700 tabular-nums">₱{{ number_format((float) $booking->total_paid, 2) }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-slate-500">Remaining Balance</dt>
                                            <dd class="font-semibold text-slate-800 tabular-nums">₱{{ number_format((float) $booking->remaining_balance, 2) }}</dd>
                                        </div>
                                    </dl>

                                    <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-200/70 pt-3">
                                        <button type="submit" name="action" value="reconfirm_price_unchanged" class="{{ $btnPrimary }}">
                                            <i class="fa-solid fa-check" aria-hidden="true"></i> Reconfirm Current Price (Unchanged)
                                        </button>
                                        <button type="submit" name="action" value="reconfirm_price_revised" class="{{ $btnSecondary }}">
                                            <i class="fa-solid fa-rotate" aria-hidden="true"></i> Issue Revised Quotation (Price / Terms Changed)
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12 lg:items-start">
                        <div class="lg:col-span-7">
                            <label for="admin_notes" class="{{ $fieldLabel }}">Admin Notes / Message to Client</label>
                            <textarea id="admin_notes" name="admin_notes" rows="6" class="{{ $fieldInput }} min-h-[150px]" placeholder="Write a custom message or update for the client...">{{ old('admin_notes', $booking->admin_notes) }}</textarea>
                            <p class="mt-1.5 text-xs text-slate-500">Sent with the official quotation.</p>
                        </div>

                        <div class="lg:col-span-5">
                            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pricing</p>
                                <dl class="mt-3 space-y-3 text-sm">
                                    <div class="flex items-center justify-between gap-3">
                                        <dt class="text-slate-600">Raw Wholesale Subtotal (BOM Cost):</dt>
                                        <dd id="raw-wholesale-subtotal" class="font-medium text-slate-800 tabular-nums">₱ {{ number_format($booking->raw_materials_sum ?? 0, 2) }}</dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <dt><label for="markup-multiplier" class="text-slate-600">Markup Multiplier:</label></dt>
                                        <dd><input id="markup-multiplier" type="number" step="0.1" name="multiplier" value="{{ old('multiplier', $booking->multiplier ?? 3.0) }}" class="w-24 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-right text-sm tabular-nums focus:border-brand-600 focus:ring-2 focus:ring-brand-500/20 focus:outline-none" @if($bookingLocked) disabled @endif></dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <dt class="text-slate-600">Calculated Quotation Total:</dt>
                                        <dd id="calculated-quotation-total" class="font-medium text-slate-800 tabular-nums">₱ {{ number_format(($booking->raw_materials_sum ?? 0) * ($booking->multiplier ?? 3.0), 2) }}</dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3 border-t border-slate-200 pt-3">
                                        <dt><label for="final-quoted-total-override" class="font-semibold text-slate-900">Final Quoted Total (Override):</label></dt>
                                        <dd class="relative w-44 shrink-0">
                                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-medium text-slate-400">₱</span>
                                            <input id="final-quoted-total-override" type="number" step="0.01" name="final_quoted_price" value="{{ old('final_quoted_price', $booking->final_quoted_price ?: $booking->total_quoted) }}" class="w-full rounded-md border border-brand-300 bg-white py-2 pl-7 pr-2.5 text-right font-semibold text-brand-800 tabular-nums focus:border-brand-600 focus:ring-2 focus:ring-brand-500/20 focus:outline-none" @if($bookingLocked) disabled @endif>
                                            {{-- 1 once the Admin types a price (or one was set before); while 0 the box only mirrors the running total and the calculated quotation price applies. --}}
                                            <input id="final-price-overridden" type="hidden" name="final_price_overridden" value="0" @if($bookingLocked) disabled @endif>
                                        </dd>
                                    </div>
                                </dl>
                                <p class="mt-3 text-xs text-slate-500">Calculated total = BOM subtotal × multiplier. Enter an override to set the official price.</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 border-t border-slate-100 pt-4 lg:flex-row lg:items-center lg:justify-end">
                        @if($pendingSchedule)
                            {{-- Client's schedule change request: check Raflora's side, agree it in Messages, then decide (ScheduleChangeService). --}}
                            <div id="schedule-change-request" class="w-full rounded-xl border border-amber-200 bg-amber-50/70 p-4 text-sm lg:mr-auto lg:max-w-2xl lg:flex-1" aria-labelledby="schedule-change-request-heading">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h3 id="schedule-change-request-heading" class="flex items-center gap-2 font-semibold text-amber-950">
                                        <i class="fa-solid fa-calendar-days text-amber-600" aria-hidden="true"></i>Schedule change requested
                                    </h3>
                                    <span class="{{ $pill }} bg-amber-100 text-amber-800">Awaiting your decision</span>
                                </div>
                                <dl class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <div><dt class="text-xs text-slate-500">Current</dt><dd class="font-medium text-slate-700">{{ $pendingSchedule->previousLabel() }}</dd></div>
                                    <div><dt class="text-xs text-slate-500">Requested</dt><dd class="font-semibold text-slate-900">{{ $pendingSchedule->requestedLabel() }}</dd></div>
                                    @if($pendingSchedule->reason)
                                        <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Client's reason</dt><dd class="text-slate-700">{{ $pendingSchedule->reason }}</dd></div>
                                    @endif
                                </dl>

                                <div class="mt-3 rounded-lg border border-amber-200/80 bg-white/70 p-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Raflora availability on {{ $pendingSchedule->requested_date->format('M j, Y') }}</p>
                                    <ul class="mt-2 space-y-1.5 text-xs text-slate-700">
                                        <li class="flex items-start gap-2">
                                            <i class="fa-solid {{ $scheduleAvailability['other_bookings']->isEmpty() ? 'fa-circle-check text-emerald-600' : 'fa-circle-info text-sky-600' }} mt-0.5" aria-hidden="true"></i>
                                            <span>
                                                @if($scheduleAvailability['other_bookings']->isEmpty())
                                                    No other bookings on this date.
                                                @else
                                                    {{ $scheduleAvailability['other_bookings']->count() }} other {{ \Illuminate\Support\Str::plural('booking', $scheduleAvailability['other_bookings']->count()) }} on this date:
                                                    {{ $scheduleAvailability['other_bookings']->take(5)->map(fn ($other) => '#' . $other->id . ' ' . ucfirst((string) $other->event_type) . ' (' . $other->status_display_label . ')')->implode(', ') }}{{ $scheduleAvailability['other_bookings']->count() > 5 ? ', …' : '' }}
                                                @endif
                                            </span>
                                        </li>
                                        @if(empty($scheduleAvailability['materials']))
                                            <li class="flex items-start gap-2"><i class="fa-solid fa-circle-info mt-0.5 text-slate-400" aria-hidden="true"></i><span>No linked reusable materials to check yet.</span></li>
                                        @elseif($scheduleAvailability['short'] === 0)
                                            <li class="flex items-start gap-2"><i class="fa-solid fa-circle-check mt-0.5 text-emerald-600" aria-hidden="true"></i><span>All {{ count($scheduleAvailability['materials']) }} linked reusable materials are in stock for this date.</span></li>
                                        @else
                                            @foreach($scheduleAvailability['materials'] as $material)
                                                @unless($material['ok'])
                                                    <li class="flex items-start gap-2 text-rose-700"><i class="fa-solid fa-triangle-exclamation mt-0.5" aria-hidden="true"></i><span>{{ $material['name'] }}: needs {{ rtrim(rtrim(number_format($material['needed'], 2), '0'), '.') }}, only {{ rtrim(rtrim(number_format($material['available'], 2), '0'), '.') }} free that day.</span></li>
                                                @endunless
                                            @endforeach
                                        @endif
                                    </ul>
                                </div>

                                <p class="mt-3 text-xs text-amber-900">Confirm the date with the client in Messages first. Accepting moves the event{!! in_array($booking->status, \App\Services\ScheduleChangeService::QUOTATION_STATUSES, true) ? '; then send the updated quotation with <strong>Approve &amp; Send Official Quote</strong>' : '' !!}.</p>
                                <label for="schedule-decision-note" class="sr-only">Note to the client</label>
                                <input id="schedule-decision-note" type="text" name="decision_note" form="scheduleDecisionForm" maxlength="1000" placeholder="Note to the client (optional, shown in Messages)" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-600 focus:ring-2 focus:ring-brand-500/20 focus:outline-none">
                                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:justify-end">
                                    <button type="submit" form="scheduleDecisionForm" name="decision" value="decline" class="{{ $btnSecondary }}">
                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>Decline Request
                                    </button>
                                    <button type="submit" form="scheduleDecisionForm" name="decision" value="accept" class="{{ $btnPrimary }}">
                                        <i class="fa-solid fa-calendar-check" aria-hidden="true"></i>Accept New Schedule
                                    </button>
                                </div>
                            </div>
                        @endif
                        @unless($quoteReadOnly && !$hasTentative)
                            @if($quotationExpired)
                                <div class="flex flex-1 items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
                                    <i class="fa-solid fa-clock-rotate-left text-amber-500" aria-hidden="true"></i>
                                    <span>Quotation expired on <strong>{{ \Carbon\Carbon::parse($booking->price_valid_until)->format('M d, Y') }}</strong>. Client cannot approve until re-issued.</span>
                                </div>
                                <button type="submit" name="action" value="reissue_quotation" class="inline-flex items-center justify-center gap-2 rounded-lg bg-amber-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-amber-700 transition">
                                    <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>Re-issue Quotation (Refresh 7-Day Window)
                                </button>
                            @else
                                @if($booking->status === 'approved')
                                    <button type="submit" form="finalApproveForm" class="{{ $btnSecondary }} py-2.5">Final Approve &amp; Enable Payment</button>
                                @endif
                                <button type="submit" name="action" value="send_quotation" class="{{ $btnPrimary }} px-5 py-2.5">
                                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Approve &amp; Send Official Quote to Client
                                </button>
                            @endif
                        @else
                            <div class="flex flex-1 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-700">
                                <i class="fa-solid fa-lock text-slate-400" aria-hidden="true"></i>
                                This quote is read-only while the booking is in progress or completed.
                            </div>
                        @endunless
                    </div>
                </div>
            </form>
        </section>

        @if($pendingSchedule)
            <form id="scheduleDecisionForm" method="POST" action="{{ route('admin.bookings.schedule-requests.decide', ['booking' => $booking->id, 'scheduleRequest' => $pendingSchedule->id]) }}" class="hidden" data-confirm="Decide this schedule request?" data-confirm-message="Accept moves the event to {{ $pendingSchedule->requestedLabel() }}; Decline keeps {{ $pendingSchedule->previousLabel() }}. The client is told in Messages either way." data-confirm-label="Continue">
                @csrf
            </form>
        @endif

        @if($booking->status === 'approved')
            <form id="finalApproveForm" method="POST" action="{{ route('admin.bookings.final-approve', ['booking' => $booking->id]) }}" class="hidden">
                @csrf
            </form>
        @endif

        @foreach($itemsToDisplay as $idx => $item)
            @if($item['is_ai_suggested'] && empty($item['inventory_id']))
                <form id="aiLinkForm_{{ $idx }}" method="POST" action="{{ !empty($item['id']) ? route('admin.bookings.ai.link', ['booking' => $booking->id, 'bookingItem' => $item['id']]) : route('admin.bookings.ai.link', ['booking' => $booking->id]) }}" class="hidden">
                    @csrf
                    @if(!empty($item['id']))
                        <input type="hidden" name="booking_item_id" value="{{ $item['id'] }}">
                    @else
                        {{-- Shown from the AI analysis with no booking line yet: the server creates the line. --}}
                        <input type="hidden" name="ai_item_name" value="{{ $item['name'] }}">
                        <input type="hidden" name="quantity" data-ai-row-field="quantity">
                        <input type="hidden" name="unit_price" data-ai-row-field="unit_price">
                    @endif
                    <input type="hidden" name="link_inventory_item_id" id="aiLinkInventory_{{ $idx }}">
                </form>
                <form id="aiPromoteForm_{{ $idx }}" method="POST" action="{{ route('admin.bookings.ai.promote', ['booking' => $booking->id]) }}" class="hidden">
                    @csrf
                    @if(!empty($item['id']))
                        <input type="hidden" name="booking_item_id" value="{{ $item['id'] }}">
                    @else
                        {{-- Shown from the AI analysis with no booking line yet: the server creates the line. --}}
                        <input type="hidden" name="ai_item_name" value="{{ $item['name'] }}">
                        <input type="hidden" name="quantity" data-ai-row-field="quantity">
                        <input type="hidden" name="unit_price" data-ai-row-field="unit_price">
                    @endif
                    <input type="hidden" name="catalog_name" id="aiPromoteName_{{ $idx }}">
                    <input type="hidden" name="confirm_distinct_catalog_item" id="aiPromoteDistinct_{{ $idx }}" value="0">
                    <input type="hidden" name="category" id="aiPromoteCategory_{{ $idx }}">
                    <input type="hidden" name="unit" id="aiPromoteUnit_{{ $idx }}">
                    <input type="hidden" name="is_perishable" id="aiPromotePerishable_{{ $idx }}" value="1">
                </form>
            @endif
        @endforeach

        @foreach($itemsToDisplay as $idx => $item)
            @if(!empty($item['id']) && !$item['confirmed_at'] && !empty($item['inventory_id']))
                {{-- The visible "Confirm Material" button submits this form through form="confirmMaterial_{idx}" --}}
                <form id="confirmMaterial_{{ $idx }}" method="POST" action="{{ route('admin.bookings.items.confirm', ['booking' => $booking->id, 'bookingItem' => $item['id']]) }}" class="hidden">
                    @csrf
                    @unless($quoteReadOnly)
                        @foreach($item['source_booking_item_ids'] as $sourceBookingItemId)
                            <input type="hidden" name="booking_item_ids[]" value="{{ $sourceBookingItemId }}">
                        @endforeach
                        <input type="hidden" name="quantity" value="{{ (int) $item['qty'] }}" data-sync-group-row="{{ $idx }}" data-sync-field="quantity">
                        <input type="hidden" name="unit_price" value="{{ number_format((float) $item['unit_price'], 2, '.', '') }}" data-sync-group-row="{{ $idx }}" data-sync-field="unit_price">
                    @endunless
                </form>
            @endif
        @endforeach

        <!-- Proposal uploads -->
        <section class="rf-admin-card overflow-hidden" aria-labelledby="proposal-uploads-heading">
            <div class="{{ $cardHeader }}">
                <div>
                    <h2 id="proposal-uploads-heading" class="{{ $cardTitle }}">Proposal &amp; Presentation Versions</h2>
                    <p class="{{ $cardText }}">Design proposals shared with the client, with their feedback.</p>
                </div>
                @if(!$quoteReadOnly)
                    <form method="POST" action="{{ route('admin.bookings.presentations.store', ['booking' => $booking->id]) }}" enctype="multipart/form-data" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        @csrf
                        <label for="proposal_file" class="sr-only">Proposal file</label>
                        <input id="proposal_file" type="file" name="proposal_file" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200 sm:w-64" required>
                        <button type="submit" class="{{ $btnPrimary }} shrink-0"><i class="fa-solid fa-upload" aria-hidden="true"></i>Upload Proposal</button>
                    </form>
                @else
                    <span class="text-sm text-slate-500">Proposal uploads are disabled while this booking is locked.</span>
                @endif
            </div>
            @php $presentations = $booking->presentations()->orderBy('created_at')->get(); @endphp
            @if($presentations->isEmpty())
                <p class="px-5 py-6 text-sm text-slate-500">No proposal versions uploaded yet.</p>
            @else
                <ul class="divide-y divide-slate-100" role="list">
                    @foreach($presentations as $presentation)
                        <li class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-start gap-3 min-w-0">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500" aria-hidden="true"><i class="fa-regular fa-file-lines"></i></span>
                                <div class="min-w-0">
                                    <p class="font-medium text-slate-900 truncate">{{ $presentation->version }} · {{ $presentation->file_name }}</p>
                                    <p class="text-xs text-slate-500">Uploaded {{ optional($presentation->sent_at)->format('M d, Y h:i A') }}</p>
                                    @if($presentation->feedback_text)
                                        <p class="mt-1.5 text-sm text-slate-600"><span class="font-medium text-slate-700">Client feedback:</span> {{ $presentation->feedback_text }}</p>
                                    @endif
                                </div>
                            </div>
                            <a href="{{ asset('storage/' . $presentation->file_path) }}" target="_blank" class="{{ $btnSecondary }} shrink-0"><i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>Preview / Download</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <!-- Decline Form Separated -->
        @unless($quoteReadOnly)
            <section class="rf-admin-card border-rose-200" aria-labelledby="decline-heading">
                <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 id="decline-heading" class="text-sm font-semibold text-rose-800">Decline this booking</h2>
                        <p class="mt-0.5 text-[13px] text-slate-500">Reject the request if Raflora cannot take this event.</p>
                    </div>
                    <form method="POST" action="{{ route('admin.bookings.decline', ['booking' => $booking->id]) }}" class="shrink-0" data-confirm="Decline this booking?" data-confirm-message="The client is told Raflora cannot take this event. This cannot be undone from this page." data-confirm-label="Decline booking" data-confirm-tone="danger">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 transition">
                            <i class="fa-solid fa-ban" aria-hidden="true"></i>Reject / Decline Booking
                        </button>
                    </form>
                </div>
            </section>
        @endunless
    </div>

    {{-- ============================== MATERIALS & INVENTORY ============================== --}}
    <div id="tab-materials" class="tab-content hidden space-y-6" role="tabpanel" aria-labelledby="tab-materials-button" tabindex="0">
        {{-- README Client Workflow: Material Preparation / Validation --}}
        <section class="rf-admin-card overflow-hidden" aria-labelledby="material-validation-heading">
            <div class="{{ $cardHeader }}">
                <div>
                    <h2 id="material-validation-heading" class="{{ $cardTitle }}">Material Preparation &amp; Validation</h2>
                    <p class="{{ $cardText }}">Confirm, link, or promote materials in the Quotation tab. Every material must be validated before the official quotation can be sent.</p>
                </div>
                <span class="{{ $pill }} shrink-0 {{ $allMaterialsValidated ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                    {{ $validatedMaterialCount }}/{{ $materialItems->count() }} validated
                </span>
            </div>

            @if($materialItems->isEmpty())
                <p class="px-5 py-6 text-sm text-slate-500">No materials have been listed for this booking yet.</p>
            @else
                @php $validationPercent = round(($validatedMaterialCount / max(1, $materialItems->count())) * 100); @endphp
                <div class="px-5 pt-4">
                    <div class="h-1.5 w-full rounded-full bg-slate-100" role="progressbar" aria-label="Materials validated" aria-valuenow="{{ $validationPercent }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-1.5 rounded-full {{ $allMaterialsValidated ? 'bg-[#0ca30c]' : 'bg-brand-600' }}" style="width: {{ $validationPercent }}%;"></div>
                    </div>
                </div>
                <div class="overflow-x-auto pt-4">
                    <table class="rf-table--stack w-full min-w-[560px] text-left text-sm text-slate-700">
                        <thead class="rf-admin-thead">
                            <tr>
                                <th scope="col" class="!pl-5">Material</th>
                                <th scope="col" class="!text-right">Qty</th>
                                <th scope="col">Source</th>
                                <th scope="col" class="!pr-5">Validation</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($materialItems as $materialItem)
                                <tr class="hover:bg-slate-50/70">
                                    <td class="py-3 pl-5 pr-4 font-medium text-slate-900" data-label="Material">{{ $materialItem->item_name ?? $materialItem->inventoryItem?->name ?? 'Material' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums" data-label="Qty">{{ $formatMaterialQty($materialItem->quantity) }}</td>
                                    <td class="px-4 py-3 text-[13px] text-slate-600" data-label="Source">
                                        @if($materialItem->is_ai_suggested)
                                            <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-wand-magic-sparkles text-[11px] text-slate-400" aria-hidden="true"></i>AI suggestion</span>
                                        @else
                                            <span class="inline-flex items-center gap-1.5"><i class="fa-solid fa-gift text-[11px] text-slate-400" aria-hidden="true"></i>Package / Admin</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pl-4 pr-5" data-label="Validation">
                                        @if($materialItem->confirmed_at)
                                            <span class="{{ $pill }} bg-emerald-100 text-emerald-800"><i class="fa-solid fa-check" aria-hidden="true"></i>Validated</span>
                                        @elseif(!$materialItem->inventory_item_id)
                                            <span class="{{ $pill }} bg-amber-100 text-amber-800">Unmatched — link or promote</span>
                                        @else
                                            <span class="{{ $pill }} bg-slate-100 text-slate-700">Awaiting confirmation</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- Inventory check for confirmed reusable materials (feeds Preparation & Reservation) --}}
        <section class="rf-admin-card overflow-hidden" aria-labelledby="reservation-status-heading">
            <div class="{{ $cardHeader }}">
                <div>
                    <h2 id="reservation-status-heading" class="{{ $cardTitle }}">Reusable Material Reservation</h2>
                    <p class="{{ $cardText }}">Stock held for this event from the master inventory.</p>
                </div>
                @if(!empty($reusableStatusList))
                    <span class="{{ $pill }} shrink-0 {{ !empty($allReusableReserved) ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ !empty($allReusableReserved) ? 'All reserved' : 'Not fully reserved' }}</span>
                @endif
            </div>
            @if(empty($reusableStatusList))
                <p class="px-5 py-6 text-sm text-slate-500">No confirmed reusable materials require reservation.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="rf-table--stack w-full min-w-[560px] text-left text-sm text-slate-700">
                        <thead class="rf-admin-thead">
                            <tr>
                                <th scope="col" class="!pl-5">Item</th>
                                <th scope="col" class="!text-right">Required</th>
                                <th scope="col" class="!text-right">Reserved</th>
                                <th scope="col" class="!text-right">Available</th>
                                <th scope="col" class="!pr-5">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($reusableStatusList as $reusableRow)
                                <tr class="hover:bg-slate-50/70">
                                    <td class="py-3 pl-5 pr-4 font-medium text-slate-900" data-label="Item">{{ $reusableRow['inventory_item']->name }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums" data-label="Required">{{ $formatMaterialQty($reusableRow['required']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums" data-label="Reserved">{{ $formatMaterialQty($reusableRow['locked']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums {{ $reusableRow['has_shortage'] ? 'font-semibold text-rose-700' : '' }}" data-label="Available">{{ $formatMaterialQty($reusableRow['available']) }}</td>
                                    <td class="py-3 pl-4 pr-5" data-label="Status">
                                        @if($reusableRow['is_reserved'])
                                            <span class="{{ $pill }} bg-emerald-100 text-emerald-800"><i class="fa-solid fa-lock" aria-hidden="true"></i>Reserved</span>
                                        @elseif($reusableRow['has_shortage'])
                                            <span class="{{ $pill }} bg-rose-100 text-rose-800"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>Shortage</span>
                                        @else
                                            <span class="{{ $pill }} bg-slate-100 text-slate-700">Awaiting reservation</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if(isset($freshFlowerItems) && $freshFlowerItems->isNotEmpty())
                <div class="border-t border-slate-100 bg-brand-50/40 px-5 py-4">
                    <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-brand-800"><i class="fa-solid fa-seedling" aria-hidden="true"></i>Fresh flowers (procured, not reserved)</p>
                    <ul class="mt-2 grid grid-cols-1 gap-1.5 text-sm text-slate-700 sm:grid-cols-2" role="list">
                        @foreach($freshFlowerItems as $freshItem)
                            <li class="flex items-center justify-between gap-3 rounded-lg bg-white px-3 py-2 ring-1 ring-brand-100">
                                <span class="truncate">{{ $freshItem->item_name ?? $freshItem->inventoryItem?->name }} — <span class="tabular-nums">{{ $formatMaterialQty($freshItem->quantity) }}</span></span>
                                <span class="text-xs font-medium text-slate-500 shrink-0">{{ ucfirst($freshItem->procurement_status ?? 'pending') }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>
    </div>

    {{-- ============================== PREPARATION ============================== --}}
    <div id="tab-preparation" class="tab-content hidden space-y-6" role="tabpanel" aria-labelledby="tab-preparation-button" tabindex="0">
        @php
            $operationalPaymentVerified = $booking->hasVerifiedPayment();
            $isPrepDateValid = $booking->isPreparationDateWithinEventWindow();
            $hasValidPreparationSchedule = $booking->hasValidPreparationSchedule();
            $operationalStatusAllowed = in_array($booking->status, ['downpayment_received', 'confirmed', 'fully_paid', 'in_preparation', 'event_in_progress'], true);
            $canManagePreparation = $operationalPaymentVerified && $operationalStatusAllowed && $hasValidPreparationSchedule;
            $canReserve = $canManagePreparation;
            $freshFlowerItems = ($bookingItems ?? $booking->bookingItems)->filter(fn ($item) => $item->requiresFreshProcurement() && $item->confirmed_at && (float) $item->quantity > 0)->values();
            $hasFreshFlowers = $freshFlowerItems->isNotEmpty();
            $freshFlowersReady = $hasFreshFlowers && $booking->areFreshFlowersReady();
            $prepMinDate = now()->toDateString();
            $prepMaxDate = $booking->event_date?->copy()->subDays(14)->toDateString();
            $prepWindowAvailable = $prepMaxDate !== null && $prepMaxDate >= $prepMinDate;

            // Mirrors InventoryDispatchService: outstanding = reserved (lock - release) - net dispatched, per inventory item.
            $dispatchClosed = in_array($booking->status, ['event_completed', 'completed', 'cancelled', 'declined', 'rejected', 'pending_return', 'pending_resolution'], true);
            $dispatchRows = \App\Models\InventoryTransaction::with('inventoryItem')
                ->where('booking_id', $booking->id)
                ->get()
                ->filter(fn ($tx) => $tx->inventoryItem !== null)
                ->groupBy('inventory_item_id')
                ->map(function ($txs) {
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
                ->filter(fn ($row) => $row['reserved'] > 0 || $row['dispatched'] > 0)
                ->values();
            $hasOutstanding = $dispatchRows->contains(fn ($row) => $row['outstanding'] > 0);
            $canDispatch = !$dispatchClosed && $canManagePreparation && !is_null($booking->confirmed_at) && $hasOutstanding;
            $fullyDispatched = $dispatchRows->isNotEmpty() && !$hasOutstanding;
            $formatQty = fn ($qty) => rtrim(rtrim(number_format((float) $qty, 2, '.', ','), '0'), '.');

            $stepCircle = fn (bool $done) => $done
                ? 'bg-emerald-600 text-white ring-4 ring-emerald-50'
                : 'bg-white text-slate-500 ring-1 ring-slate-300';
        @endphp

        <section class="rf-admin-card" aria-labelledby="preparation-heading">
            <div class="{{ $cardHeader }}">
                <div>
                    <h2 id="preparation-heading" class="{{ $cardTitle }}">Operational Preparation &amp; Dispatch</h2>
                    <p class="{{ $cardText }}">Operations unlock after payment is verified and a valid preparation date is saved.</p>
                </div>
            </div>

            @if(!$operationalPaymentVerified)
                <p class="mx-5 mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                    <i class="fa-solid fa-lock mr-1" aria-hidden="true"></i>Operational preparation and dispatch are locked until a down payment or full payment is verified.
                </p>
            @elseif(!$prepWindowAvailable)
                <p class="mx-5 mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">
                    No valid preparation dates remain. Preparation must start at least 14 days before the event. Review the event schedule with the client.
                </p>
            @endif

            <ol class="p-5" role="list">
                <!-- 1. Preparation Scheduling -->
                <li class="relative flex gap-4 pb-8">
                    <span class="absolute left-4 top-9 bottom-0 w-px bg-slate-200" aria-hidden="true"></span>
                    <span class="relative z-[1] flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $stepCircle(!is_null($booking->preparation_start_date)) }}" aria-hidden="true">
                        @if($booking->preparation_start_date)<i class="fa-solid fa-check text-xs"></i>@else 1 @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-slate-900">1. Schedule Preparation</h3>
                            @if($booking->preparation_start_date)
                                <span class="{{ $pill }} bg-emerald-100 text-emerald-800">Starts {{ $booking->preparation_start_date->format('M j, Y') }}</span>
                            @else
                                <span class="{{ $pill }} bg-slate-100 text-slate-600">Not scheduled</span>
                            @endif
                        </div>
                        <p class="mt-1 text-[13px] text-slate-500">Set the date when material reservation and preparation should begin. Choose today or later, and at least 14 days before the event.</p>

                        <form method="POST" action="{{ route('admin.bookings.update', $booking) }}" class="mt-3 flex flex-wrap items-center gap-2">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="status" value="{{ $booking->status }}">
                            <input type="hidden" name="event_type" value="{{ $booking->event_type }}">
                            <input type="hidden" name="event_date" value="{{ optional($booking->event_date)->format('Y-m-d') }}">
                            <input type="hidden" name="venue" value="{{ $booking->venue }}">

                            <label for="preparation_start_date" class="sr-only">Preparation start date</label>
                            <input id="preparation_start_date" type="date" name="preparation_start_date" min="{{ $prepMinDate }}" max="{{ $prepMaxDate }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-600 focus:ring-2 focus:ring-brand-500/20 focus:outline-none" value="{{ optional($booking->preparation_start_date)->format('Y-m-d') }}" required {{ !$operationalPaymentVerified || !$prepWindowAvailable ? 'disabled' : '' }}>

                            <button type="submit" class="{{ $btnDark }}" {{ !$operationalPaymentVerified || !$prepWindowAvailable ? 'disabled' : '' }}>Save Date</button>
                        </form>
                        @error('preparation_start_date')
                            <p class="mt-2 text-xs font-medium text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>
                </li>

                <!-- 2. Reserve Materials -->
                <li class="relative flex gap-4 pb-8">
                    <span class="absolute left-4 top-9 bottom-0 w-px bg-slate-200" aria-hidden="true"></span>
                    <span class="relative z-[1] flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $stepCircle(!empty($allReusableReserved)) }}" aria-hidden="true">
                        @if(!empty($allReusableReserved))<i class="fa-solid fa-check text-xs"></i>@else 2 @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-slate-900">2. Reserve Materials</h3>
                            @if(!empty($allReusableReserved))
                                <span class="{{ $pill }} bg-emerald-100 text-emerald-800"><i class="fa-solid fa-lock" aria-hidden="true"></i>All reserved</span>
                            @elseif(!$canReserve)
                                <span class="{{ $pill }} bg-slate-100 text-slate-600"><i class="fa-solid fa-lock" aria-hidden="true"></i>Locked</span>
                            @endif
                        </div>
                        <p class="mt-1 text-[13px] text-slate-500">Lock reusable materials after payment is verified and a valid preparation date is saved. The date records when preparation is planned; it does not delay reservation.</p>

                        <form method="POST" action="{{ route('admin.bookings.reserve-materials', $booking) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="{{ $btnPrimary }}" {{ !$canReserve ? 'disabled' : '' }}>
                                <i class="fa-solid fa-lock" aria-hidden="true"></i>Reserve Materials
                            </button>
                            @if(!$operationalPaymentVerified)
                                <p class="mt-2 flex items-center gap-1.5 text-xs font-medium text-amber-700"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>Cannot reserve: verified payment is required first.</p>
                            @elseif(!$isPrepDateValid)
                                <p class="mt-2 flex items-center gap-1.5 text-xs font-medium text-amber-700"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>Cannot reserve: choose a valid preparation date from today through at least 14 days before the event.</p>
                            @elseif($booking->preparation_start_date->isFuture())
                                <p class="mt-2 flex items-center gap-1.5 text-xs font-medium text-emerald-700"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>Reservation can be started now. Preparation is planned for {{ $booking->preparation_start_date->format('M j, Y') }}.</p>
                            @endif
                        </form>
                    </div>
                </li>

                <!-- 3. Confirm Fresh Flowers -->
                @if($hasFreshFlowers)
                    <li class="relative flex gap-4 pb-8">
                        <span class="absolute left-4 top-9 bottom-0 w-px bg-slate-200" aria-hidden="true"></span>
                        <span class="relative z-[1] flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $stepCircle($freshFlowersReady) }}" aria-hidden="true">
                            @if($freshFlowersReady)<i class="fa-solid fa-check text-xs"></i>@else 3 @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-semibold text-slate-900">3. Fresh Flower Procurement</h3>
                            <p class="mt-1 text-[13px] text-slate-500">Review the fresh items below. You can correct an item that belongs with reusable inventory without removing it from the booking.</p>

                            <ul class="mt-3 space-y-2" role="list">
                                @foreach($freshFlowerItems as $freshItem)
                                    @php
                                        $freshItemInventory = $freshItem->inventoryItem;
                                        $hasItemLedger = $freshItemInventory && \App\Models\InventoryTransaction::where('booking_id', $booking->id)->where('inventory_item_id', $freshItemInventory->id)->exists();
                                    @endphp
                                    <li class="flex flex-col gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-slate-800">{{ $freshItem->item_name ?? $freshItemInventory?->name ?? 'Unlinked item' }} <span class="font-normal text-slate-600">— {{ $formatMaterialQty($freshItem->quantity) }}</span></p>
                                            <p class="text-xs text-slate-500">Procurement: {{ ucfirst($freshItem->procurement_status ?? 'pending') }}</p>
                                        </div>
                                        @if($freshItemInventory)
                                            <form method="POST" action="{{ route('admin.bookings.items.fresh-procurement', [$booking, $freshItem]) }}" class="shrink-0">
                                                @csrf
                                                <input type="hidden" name="fresh_procurement" value="0">
                                                <button type="submit" class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" {{ !$canManagePreparation || $hasItemLedger ? 'disabled' : '' }}>
                                                    Treat as reusable inventory
                                                </button>
                                            </form>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            <form method="POST" action="{{ route('admin.bookings.confirm-fresh-flowers', $booking) }}" class="mt-3">
                                @csrf
                                <button type="submit" class="{{ $freshFlowersReady ? $btnSecondary : $btnPrimary }}" {{ !$canManagePreparation || $freshFlowersReady ? 'disabled' : '' }}>
                                    <i class="fa-solid fa-seedling" aria-hidden="true"></i>{{ $booking->areFreshFlowersReady() ? 'Fresh Flowers Confirmed' : 'Confirm Fresh Flowers' }}
                                </button>
                            </form>
                            @if(!$canManagePreparation)
                                <p class="mt-2 text-xs font-medium text-amber-700">{{ !$operationalPaymentVerified ? 'Payment must be verified before confirming procurement.' : 'Set a valid preparation date before confirming procurement.' }}</p>
                            @endif
                        </div>
                    </li>
                @endif

                <!-- 4. Inventory Dispatch & Tracking (README Staff Workflow: Dispatch) -->
                <li class="relative flex gap-4" aria-labelledby="dispatch-tracking-heading">
                    <span class="relative z-[1] flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $stepCircle($fullyDispatched) }}" aria-hidden="true">
                        @if($fullyDispatched)<i class="fa-solid fa-check text-xs"></i>@else {{ $hasFreshFlowers ? 4 : 3 }} @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 id="dispatch-tracking-heading" class="text-sm font-semibold text-slate-900">4. Inventory Dispatch &amp; Tracking</h3>
                            @if($dispatchClosed)
                                <span class="{{ $pill }} bg-slate-100 text-slate-700"><i class="fa-solid fa-lock" aria-hidden="true"></i>Dispatch Closed ({{ $booking->status_display_label }})</span>
                            @elseif($fullyDispatched)
                                <span class="{{ $pill }} bg-emerald-100 text-emerald-800"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Fully Dispatched</span>
                            @elseif($hasOutstanding)
                                <span class="{{ $pill }} bg-navy-50 text-navy-700"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i>Reserved Materials Ready for Dispatch</span>
                            @endif
                        </div>
                        <p class="mt-1 text-[13px] text-slate-500">Dispatch the reserved reusable materials to the event location. Dispatch physically deducts stock and is required before the event can start.</p>

                        @if($dispatchRows->isEmpty())
                            <p class="mt-3 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-500">No reserved reusable materials available for dispatch. Please reserve materials first.</p>
                        @else
                            <form method="POST" action="{{ route('admin.bookings.dispatch', $booking) }}" class="mt-3 space-y-3">
                                @csrf
                                <input type="hidden" name="reason" value="Dispatch for Event #{{ $booking->id }}">

                                <div class="overflow-x-auto rounded-xl border border-slate-200">
                                    <table class="rf-table--stack w-full min-w-[560px] text-left text-sm text-slate-700">
                                        <thead class="rf-admin-thead">
                                            <tr>
                                                <th scope="col">Item</th>
                                                <th scope="col" class="!text-right">Reserved</th>
                                                <th scope="col" class="!text-right">Dispatched</th>
                                                <th scope="col" class="!text-right">Outstanding</th>
                                                <th scope="col">Status</th>
                                                @if($canDispatch)
                                                    <th scope="col" class="!text-right">Qty to Dispatch</th>
                                                @endif
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach($dispatchRows as $index => $row)
                                                <tr>
                                                    <td class="px-4 py-3 font-medium text-slate-900" data-label="Item">{{ $row['item']->name }}</td>
                                                    <td class="px-4 py-3 text-right tabular-nums" data-label="Reserved">{{ $formatQty($row['reserved']) }}</td>
                                                    <td class="px-4 py-3 text-right tabular-nums" data-label="Dispatched">{{ $formatQty($row['dispatched']) }}</td>
                                                    <td class="px-4 py-3 text-right font-semibold tabular-nums {{ $row['outstanding'] > 0 ? 'text-amber-700' : 'text-slate-500' }}" data-label="Outstanding">{{ $formatQty($row['outstanding']) }}</td>
                                                    <td class="px-4 py-3" data-label="Status">
                                                        @if($row['outstanding'] <= 0)
                                                            <span class="{{ $pill }} bg-emerald-100 text-emerald-800">Fully Dispatched</span>
                                                        @elseif($row['dispatched'] > 0)
                                                            <span class="{{ $pill }} bg-amber-100 text-amber-800">Partially Dispatched</span>
                                                        @else
                                                            <span class="{{ $pill }} bg-slate-100 text-slate-700">Awaiting Dispatch</span>
                                                        @endif
                                                    </td>
                                                    @if($canDispatch)
                                                        <td class="px-4 py-3 text-right" data-label="Qty to Dispatch">
                                                            @if($row['outstanding'] > 0)
                                                                <input type="hidden" name="items[{{ $index }}][inventory_item_id]" value="{{ $row['item']->id }}">
                                                                <label class="sr-only" for="dispatch-qty-{{ $index }}">Quantity to dispatch for {{ $row['item']->name }}</label>
                                                                <input id="dispatch-qty-{{ $index }}" type="number" name="items[{{ $index }}][quantity]" value="{{ $row['outstanding'] }}" max="{{ $row['outstanding'] }}" min="0" step="0.01" class="w-24 rounded-md border border-slate-300 px-2.5 py-1.5 text-right text-sm tabular-nums focus:border-brand-600 focus:ring-2 focus:ring-brand-500/20 focus:outline-none">
                                                            @else
                                                                <span class="text-xs text-slate-400">—</span>
                                                            @endif
                                                        </td>
                                                    @endif
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                @if($canDispatch)
                                    <button type="submit" class="{{ $btnPrimary }}">
                                        <i class="fa-solid fa-truck-fast" aria-hidden="true"></i>Dispatch Selected
                                    </button>
                                @elseif($dispatchClosed)
                                    <p class="text-sm font-medium text-slate-600"><i class="fa-solid fa-lock mr-1" aria-hidden="true"></i>Dispatch unavailable — this booking is {{ strtolower($booking->status_display_label) }}.</p>
                                @elseif(!$operationalPaymentVerified)
                                    <p class="text-sm font-medium text-amber-700">Dispatch unavailable — verified payment is required.</p>
                                @elseif(!$hasValidPreparationSchedule)
                                    <p class="text-sm font-medium text-amber-700">Dispatch unavailable — schedule a valid preparation start date first.</p>
                                @elseif(is_null($booking->confirmed_at))
                                    <p class="text-sm font-medium text-amber-700">Dispatch unavailable — the booking confirmation date is not recorded.</p>
                                @else
                                    <p class="text-sm font-medium text-emerald-700">All reserved materials have been fully dispatched.</p>
                                @endif
                            </form>
                        @endif

                        @if($dispatchClosed && $dispatchRows->isEmpty())
                            <p class="mt-2 text-sm font-medium text-slate-600"><i class="fa-solid fa-lock mr-1" aria-hidden="true"></i>Dispatch unavailable — this booking is {{ strtolower($booking->status_display_label) }}.</p>
                        @endif
                    </div>
                </li>
            </ol>
        </section>

        {{-- Staff Preparation Checklist (README Staff Workflow: Preparation & Reservation) — read-only for Admin --}}
        <section class="rf-admin-card overflow-hidden" aria-labelledby="staff-checklist-heading">
            <div class="{{ $cardHeader }}">
                <div>
                    <h2 id="staff-checklist-heading" class="{{ $cardTitle }}">Staff Preparation Checklist</h2>
                    <p class="{{ $cardText }}">Read-only view of the assigned Staff member's preparation progress.</p>
                </div>
                <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                    <span class="{{ $pill }} bg-slate-100 text-slate-600"><i class="fa-regular fa-eye" aria-hidden="true"></i>Read-only</span>
                    @if($prepChecklist->isNotEmpty())
                        <span class="{{ $pill }} bg-slate-100 text-slate-600 tabular-nums">{{ $prepCompletedCount }}/{{ $prepChecklist->count() }} complete</span>
                        <span class="{{ $pill }} {{ $prepAllComplete ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                            {{ $prepAllComplete ? 'Preparation Complete' : 'Preparation Incomplete' }}
                        </span>
                    @endif
                </div>
            </div>

            @if($prepChecklist->isEmpty())
                <div class="m-5 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center">
                    <i class="fa-solid fa-list-check text-2xl text-slate-300" aria-hidden="true"></i>
                    <p class="mt-2 text-sm font-medium text-slate-700">No preparation checklist items recorded</p>
                    <p class="mt-1 text-xs text-slate-500">Checklist items will appear once operational staff accesses this assigned event.</p>
                </div>
            @else
                @php $prepPercent = round(($prepCompletedCount / max(1, $prepChecklist->count())) * 100); @endphp
                <div class="px-5 pt-4">
                    <div class="h-1.5 w-full rounded-full bg-slate-100" role="progressbar" aria-label="Checklist completion" aria-valuenow="{{ $prepPercent }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-1.5 rounded-full {{ $prepAllComplete ? 'bg-[#0ca30c]' : 'bg-brand-600' }}" style="width: {{ $prepPercent }}%;"></div>
                    </div>
                </div>
                <ul class="divide-y divide-slate-100 pt-2" role="list">
                    @foreach($prepChecklist as $checklistItem)
                        <li class="flex items-start gap-3 px-5 py-3.5">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full {{ $checklistItem->is_completed ? 'bg-emerald-600 text-white' : 'ring-1 ring-slate-300 bg-white' }}" aria-hidden="true">
                                @if($checklistItem->is_completed)<i class="fa-solid fa-check text-[10px]"></i>@endif
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                                    <p class="text-sm font-medium {{ $checklistItem->is_completed ? 'text-slate-900' : 'text-slate-700' }}">{{ $checklistItem->title }}</p>
                                    @if($checklistItem->is_completed)
                                        <span class="{{ $pill }} bg-emerald-100 text-emerald-800"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Completed</span>
                                    @else
                                        <span class="{{ $pill }} bg-slate-100 text-slate-600"><i class="fa-regular fa-circle" aria-hidden="true"></i>Pending completion</span>
                                    @endif
                                </div>
                                @if($checklistItem->is_completed && $checklistItem->completed_at)
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ $checklistItem->completed_at->format('M d, Y h:i A') }}@if($checklistItem->completedBy) by {{ $checklistItem->completedBy->name }}@endif
                                    </p>
                                @endif
                                @if($checklistItem->notes)
                                    <p class="mt-1.5 whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-[13px] text-slate-600">{{ $checklistItem->notes }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- ============================== STAFF ASSIGNMENT ============================== --}}
    <div id="tab-staff" class="tab-content hidden space-y-6" role="tabpanel" aria-labelledby="tab-staff-button" tabindex="0">
        <section class="rf-admin-card" aria-labelledby="staff-assignment-heading">
            <div class="{{ $cardHeader }}">
                <div>
                    <h2 id="staff-assignment-heading" class="{{ $cardTitle }}">Operational Staff Assignment</h2>
                    <p class="{{ $cardText }}">Assign this event to one Staff member for operational follow-up. This does not change the Admin handler.</p>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-6 p-5 lg:grid-cols-2">
                <div class="flex items-center gap-4 rounded-xl border {{ $assignedStaff ? 'border-slate-200' : 'border-dashed border-slate-300 bg-slate-50' }} p-4">
                    @if($assignedStaff)
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-800" aria-hidden="true">{{ strtoupper(substr($assignedStaff->name, 0, 2)) }}</span>
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-slate-500">Currently assigned</p>
                            <p class="truncate text-sm font-semibold text-slate-900">{{ $assignedStaff->name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ $assignedStaff->email }}</p>
                        </div>
                    @else
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white text-slate-400 ring-1 ring-slate-200" aria-hidden="true"><i class="fa-solid fa-user-plus"></i></span>
                        <div>
                            <p class="text-sm font-semibold text-slate-800">No staff assigned yet</p>
                            <p class="text-xs text-slate-500">The assigned staff member sees this event and its checklist.</p>
                        </div>
                    @endif
                </div>

                <form method="POST" action="{{ route('admin.bookings.assign-staff', ['booking' => $booking->id]) }}" class="flex flex-col justify-center gap-2">
                    @csrf
                    <label class="{{ $fieldLabel }}" for="staff_id">Operational Staff</label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <select id="staff_id" name="staff_id" class="{{ $fieldInput }}">
                            <option value="">Unassigned</option>
                            @foreach($staffUsers as $staffUser)
                                <option value="{{ $staffUser->id }}" {{ (string) $booking->staff_id === (string) $staffUser->id ? 'selected' : '' }}>{{ $staffUser->name }} ({{ $staffUser->email }})</option>
                            @endforeach
                        </select>
                        <button type="submit" class="{{ $btnPrimary }} shrink-0"><i class="fa-solid fa-user-check" aria-hidden="true"></i> Save Assignment</button>
                    </div>
                </form>
            </div>
            @if($prepChecklist->isNotEmpty())
                <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 text-[13px] text-slate-600">
                    <span>Checklist progress: <strong class="tabular-nums text-slate-900">{{ $prepCompletedCount }}/{{ $prepChecklist->count() }}</strong> items done</span>
                    <button type="button" onclick="switchTab('tab-preparation')" class="rf-admin-card__link">View checklist</button>
                </div>
            @endif
        </section>
    </div>

            </div>
        </div>
    </div>

    {{-- ============================== COMMUNICATION ============================== --}}
    {{-- A floating message button opens the conversation from any tab; Client Meetings sit behind a switch inside it. --}}
    @php
        $adminMeetings = $booking->meetings()->orderByDesc('scheduled_datetime')->get();
        $adminMeetingsAllowed = \App\Models\Meeting::bookingAllowsMeetings($booking);
        $openMeetingCount = $adminMeetings->filter(fn ($m) => $m->isOpen())->count();
        $meetingFormFailed = $errors->hasAny(['meeting_type', 'scheduled_datetime', 'meeting_link', 'address', 'agenda', 'outcome_notes', 'reason']);
    @endphp
    <style>
        #admin-chat-panel #booking-conversation-root { border: 0; border-radius: 0; box-shadow: none; display: flex; flex-direction: column; min-height: 100%; }
        /* The panel header already names the conversation, so the component's own title bar is hidden here. */
        #admin-chat-panel #booking-conversation-root > div:first-child { display: none; }
        #admin-chat-panel #booking-conversation-root > .relative { flex: 1 1 0; display: flex; flex-direction: column; min-height: 160px; }
        #admin-chat-panel #comm-messages-scroll { flex: 1 1 0; max-height: none; min-height: 0; padding: 1rem; }
        #admin-chat-panel #comm-empty-state { padding-top: 2rem; padding-bottom: 2rem; }
        #admin-chat-panel #comm-message-text { height: 4.5rem; min-height: 4.5rem; }
        #admin-chat-panel #meetings-section { border: 0; border-radius: 0; box-shadow: none; }
    </style>
    <div id="admin-chat-panel" class="fixed inset-x-3 top-16 bottom-24 z-[46] hidden flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl sm:inset-x-auto sm:right-6 sm:top-auto sm:h-[min(760px,calc(100vh-8rem))] sm:w-[480px]" role="dialog" aria-modal="false" aria-labelledby="admin-chat-panel-title" data-meeting-error="{{ $meetingFormFailed ? '1' : '0' }}">
        <div class="flex items-center justify-between gap-3 bg-brand-800 px-4 py-3 text-white">
            <div class="min-w-0">
                <h2 id="admin-chat-panel-title" class="text-sm font-semibold !text-white">Messages · Booking #{{ $booking->id }}</h2>
                <p class="truncate text-xs text-white/75">{{ $bookingClientName }}</p>
            </div>
            <button type="button" id="admin-chat-close" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-white/80 hover:bg-white/10 hover:text-white" aria-label="Close messages"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="flex gap-1 border-b border-slate-200 bg-slate-50 p-1.5" role="group" aria-label="Messages or meetings">
            <button type="button" data-chat-view="messages" aria-pressed="true" class="admin-chat-view-btn inline-flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-1.5 text-sm font-semibold transition">
                <i class="fa-regular fa-comments text-xs" aria-hidden="true"></i>Messages
            </button>
            <button type="button" data-chat-view="meetings" aria-pressed="false" class="admin-chat-view-btn inline-flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-1.5 text-sm font-semibold transition">
                <i class="fa-regular fa-calendar text-xs" aria-hidden="true"></i>Client Meetings
                @if($openMeetingCount > 0)
                    <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-amber-800 tabular-nums">{{ $openMeetingCount }}</span>
                @endif
            </button>
        </div>
        <div id="admin-chat-view-messages" class="min-h-0 flex-1 overflow-y-auto">
            <x-booking-conversation
                :booking="$booking"
                role="admin"
                :booking-messages="$bookingMessages"
                :unread-count="$unreadMessageCount ?? 0"
                :active-quotation="$activeQuotation"
            />
        </div>
        <div id="admin-chat-view-meetings" class="hidden min-h-0 flex-1 overflow-y-auto">

        {{-- Client Meetings (README Client Workflow: client and Raflora can meet about the booking) --}}
        <section id="meetings-section" class="rf-admin-card overflow-hidden" aria-labelledby="meetings-heading">
            <div class="{{ $cardHeader }}">
                <div>
                    <h2 id="meetings-heading" class="{{ $cardTitle }}">Client Meetings</h2>
                    <p class="{{ $cardText }}">Consultations and site visits with the client for this booking.</p>
                </div>
                <span class="{{ $pill }} shrink-0 bg-slate-100 text-slate-600 tabular-nums">{{ $openMeetingCount }} open</span>
            </div>

            @if(!$adminMeetingsAllowed && $adminMeetings->isEmpty())
                <p class="px-5 py-6 text-sm text-slate-500">Meetings are available once a client account has claimed this booking and while it is still active.</p>
            @endif

            @if($adminMeetings->isNotEmpty())
                <ul class="divide-y divide-slate-100" role="list">
                    @foreach($adminMeetings as $meeting)
                        <li class="px-5 py-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="w-12 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-white text-center" aria-hidden="true">
                                        <span class="block bg-slate-50 py-0.5 text-[9px] font-semibold uppercase tracking-wider text-slate-500">{{ $meeting->scheduled_datetime->format('M') }}</span>
                                        <span class="block text-base font-semibold leading-6 text-slate-900 tabular-nums">{{ $meeting->scheduled_datetime->format('j') }}</span>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-slate-900">{{ $meeting->type_label }} · {{ $meeting->scheduled_datetime->format('M j, Y g:i A') }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ $meeting->status === \App\Models\Meeting::STATUS_REQUESTED ? 'Client-requested time' : 'Scheduled by ' . ($meeting->scheduledBy?->name ?? 'Admin') }}
                                        </p>
                                        @if($meeting->meeting_link)
                                            <p class="mt-1 text-sm break-all"><i class="fa-solid fa-video mr-1 text-xs text-slate-400" aria-hidden="true"></i><a href="{{ $meeting->meeting_link }}" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-700 underline underline-offset-2">{{ $meeting->meeting_link }}</a></p>
                                        @endif
                                        @if($meeting->address)
                                            <p class="mt-1 text-sm text-slate-600"><i class="fa-solid fa-location-dot mr-1 text-xs text-slate-400" aria-hidden="true"></i>Location: {{ $meeting->address }}</p>
                                        @endif
                                        @if($meeting->agenda)
                                            <p class="mt-1 whitespace-pre-line text-xs text-slate-500">Agenda: {{ $meeting->agenda }}</p>
                                        @endif
                                        @if($meeting->outcome_notes)
                                            <p class="mt-1 whitespace-pre-line text-xs text-slate-500">Notes: {{ $meeting->outcome_notes }}</p>
                                        @endif
                                    </div>
                                </div>
                                <span class="{{ $pill }} shrink-0 {{ match($meeting->status) { 'scheduled' => 'bg-emerald-100 text-emerald-800', 'completed' => 'bg-slate-100 text-slate-700', 'cancelled' => 'bg-rose-100 text-rose-800', default => 'bg-amber-100 text-amber-800' } }}">{{ $meeting->status_label }}</span>
                            </div>

                            @if($meeting->status === \App\Models\Meeting::STATUS_REQUESTED && $adminMeetingsAllowed)
                                <form method="POST" action="{{ route('admin.bookings.meetings.confirm', ['booking' => $booking->id, 'meeting' => $meeting->id]) }}" class="mt-4 grid grid-cols-1 gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-2">
                                    @csrf
                                    <div>
                                        <label for="confirm-type-{{ $meeting->id }}" class="{{ $fieldLabel }}">Meeting type</label>
                                        <select id="confirm-type-{{ $meeting->id }}" name="meeting_type" class="{{ $fieldInput }}" required>
                                            @foreach(\App\Models\Meeting::TYPES as $typeValue => $typeLabel)
                                                <option value="{{ $typeValue }}" @selected($meeting->meeting_type === $typeValue)>{{ $typeLabel }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="confirm-time-{{ $meeting->id }}" class="{{ $fieldLabel }}">Date &amp; time</label>
                                        <input id="confirm-time-{{ $meeting->id }}" type="datetime-local" name="scheduled_datetime" value="{{ $meeting->scheduled_datetime->format('Y-m-d\TH:i') }}" class="{{ $fieldInput }}" required>
                                    </div>
                                    <div>
                                        <label for="confirm-link-{{ $meeting->id }}" class="{{ $fieldLabel }}">Meeting link (online)</label>
                                        <input id="confirm-link-{{ $meeting->id }}" type="url" name="meeting_link" placeholder="https://" class="{{ $fieldInput }}">
                                    </div>
                                    <div>
                                        <label for="confirm-address-{{ $meeting->id }}" class="{{ $fieldLabel }}">Address (in-person)</label>
                                        <input id="confirm-address-{{ $meeting->id }}" type="text" name="address" maxlength="500" class="{{ $fieldInput }}">
                                    </div>
                                    <div class="flex justify-end sm:col-span-2">
                                        <button type="submit" class="{{ $btnPrimary }}">Confirm Meeting</button>
                                    </div>
                                </form>
                            @endif

                            @if($meeting->status === \App\Models\Meeting::STATUS_SCHEDULED)
                                <form method="POST" action="{{ route('admin.bookings.meetings.complete', ['booking' => $booking->id, 'meeting' => $meeting->id]) }}" class="mt-4 flex flex-col gap-2 rounded-xl bg-slate-50 p-4 sm:flex-row sm:items-end">
                                    @csrf
                                    <div class="flex-1">
                                        <label for="outcome-{{ $meeting->id }}" class="{{ $fieldLabel }}">Meeting notes (optional)</label>
                                        <textarea id="outcome-{{ $meeting->id }}" name="outcome_notes" rows="2" maxlength="2000" class="{{ $fieldInput }}"></textarea>
                                    </div>
                                    <button type="submit" class="{{ $btnDark }}" @disabled($meeting->scheduled_datetime->isFuture())>Mark Completed</button>
                                </form>
                                @if($meeting->scheduled_datetime->isFuture())
                                    <p class="mt-1.5 text-xs text-slate-500">Can be marked completed after the scheduled time.</p>
                                @endif
                            @endif

                            @if($meeting->isOpen())
                                <form method="POST" action="{{ route('admin.bookings.meetings.cancel', ['booking' => $booking->id, 'meeting' => $meeting->id]) }}" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                                    @csrf
                                    <div class="flex-1">
                                        <label for="cancel-reason-{{ $meeting->id }}" class="{{ $fieldLabel }}">Cancellation reason (optional)</label>
                                        <input id="cancel-reason-{{ $meeting->id }}" type="text" name="reason" maxlength="500" class="{{ $fieldInput }}">
                                    </div>
                                    <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 transition">Cancel Meeting</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if($adminMeetingsAllowed)
                <form method="POST" action="{{ route('admin.bookings.meetings.store', $booking) }}" class="grid grid-cols-1 gap-3 border-t border-slate-100 bg-slate-50/60 p-5 sm:grid-cols-2">
                    @csrf
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900 sm:col-span-2"><i class="fa-regular fa-calendar-plus text-brand-700" aria-hidden="true"></i>Schedule a meeting</h3>
                    <div>
                        <label for="new-meeting-type" class="{{ $fieldLabel }}">Meeting type</label>
                        <select id="new-meeting-type" name="meeting_type" class="{{ $fieldInput }}" required>
                            @foreach(\App\Models\Meeting::TYPES as $typeValue => $typeLabel)
                                <option value="{{ $typeValue }}" @selected(old('meeting_type') === $typeValue)>{{ $typeLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="new-meeting-time" class="{{ $fieldLabel }}">Date &amp; time</label>
                        <input id="new-meeting-time" type="datetime-local" name="scheduled_datetime" value="{{ old('scheduled_datetime') }}" min="{{ now()->format('Y-m-d\TH:i') }}" class="{{ $fieldInput }}" required>
                    </div>
                    <div>
                        <label for="new-meeting-link" class="{{ $fieldLabel }}">Meeting link (online)</label>
                        <input id="new-meeting-link" type="url" name="meeting_link" value="{{ old('meeting_link') }}" placeholder="https://" class="{{ $fieldInput }}">
                    </div>
                    <div>
                        <label for="new-meeting-address" class="{{ $fieldLabel }}">Address (in-person)</label>
                        <input id="new-meeting-address" type="text" name="address" value="{{ old('address') }}" maxlength="500" class="{{ $fieldInput }}">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="new-meeting-agenda" class="{{ $fieldLabel }}">Agenda (optional)</label>
                        <textarea id="new-meeting-agenda" name="agenda" rows="2" maxlength="1000" class="{{ $fieldInput }}">{{ old('agenda') }}</textarea>
                    </div>
                    @if($errors->hasAny(['meeting_type', 'scheduled_datetime', 'meeting_link', 'address', 'agenda']))
                        <ul class="list-disc pl-5 text-xs text-rose-600 sm:col-span-2">
                            @foreach(['meeting_type', 'scheduled_datetime', 'meeting_link', 'address', 'agenda'] as $meetingField)
                                @error($meetingField)<li>{{ $message }}</li>@enderror
                            @endforeach
                        </ul>
                    @endif
                    <div class="flex justify-end sm:col-span-2">
                        <button type="submit" class="{{ $btnPrimary }}"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i>Schedule Meeting</button>
                    </div>
                </form>
            @endif
        </section>
        </div>
    </div>

    <button type="button" id="admin-chat-launcher" class="fixed bottom-6 right-6 z-[46] inline-flex h-14 w-14 items-center justify-center rounded-full bg-brand-700 text-white shadow-lg ring-4 ring-white transition hover:bg-brand-800 focus:outline-none focus-visible:ring-brand-300" aria-controls="admin-chat-panel" aria-expanded="false" aria-label="Open messages and client meetings" title="Messages &amp; client meetings">
        <i id="admin-chat-launcher-icon" class="fa-solid fa-comments text-xl" aria-hidden="true"></i>
        <span id="admin-comm-unread-badge" class="absolute -right-1 -top-1 min-w-[1.25rem] rounded-full bg-rose-500 px-1.5 py-0.5 text-center text-[11px] font-semibold leading-none text-white tabular-nums ring-2 ring-white {{ ($unreadMessageCount ?? 0) > 0 ? '' : 'hidden' }}">{{ $unreadMessageCount ?? 0 }}</span>
    </button>

    {{-- ============================== MODALS ============================== --}}
    <div id="aiMaterialModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="aiMaterialModalTitle">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 id="aiMaterialModalTitle" class="text-base font-semibold text-slate-900">Review AI material</h2>
                    <p id="aiMaterialModalDescription" class="mt-1 text-sm text-slate-500"></p>
                </div>
                <button type="button" onclick="closeAiMaterialModal()" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="Close material review"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="aiMaterialLinkFields" class="mt-5 hidden">
                {{-- Search by name (starts with the material's own name); pick the matching record. --}}
                <label for="aiMaterialInventorySearch" class="{{ $fieldLabel }}">Find the inventory item</label>
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                    <input id="aiMaterialInventorySearch" type="search" autocomplete="off" placeholder="Type an item name…" aria-controls="aiMaterialInventoryResults" class="{{ $fieldInput }} !pl-8">
                </div>
                <input type="hidden" id="aiMaterialInventorySelect" value="">
                <p class="mt-1.5 text-xs text-slate-500">Check the match before continuing (e.g. Floral Foam vs Floral Spray).</p>
                <p id="aiMaterialInventoryPartial" class="mt-2 hidden text-xs font-medium text-amber-700">No item matches every word — closest matches:</p>
                <ul id="aiMaterialInventoryResults" role="listbox" aria-label="Matching inventory items" class="mt-2 max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200"></ul>
                <p id="aiMaterialInventoryEmpty" class="mt-2 hidden text-sm text-slate-500">No inventory item matches. Try fewer words, or use Promote to Catalog instead.</p>
                @php
                    $inventoryForLink = $allInventoryItems->map(fn ($inventoryItem) => [
                        'id' => $inventoryItem->id,
                        'name' => $inventoryItem->name,
                        'code' => $inventoryItem->item_code,
                        'category' => $inventoryItem->category,
                        'stock' => (float) $inventoryItem->current_stock,
                        'unit' => $inventoryItem->unit,
                        'perishable' => (bool) $inventoryItem->is_perishable,
                    ])->values();
                @endphp
                <script type="application/json" id="aiMaterialInventoryData">@json($inventoryForLink)</script>
                <script type="application/json" id="aiMaterialSimilarityData">@json($catalogSimilarityItems->map(fn ($item) => ['name' => $item->name, 'archived' => $item->trashed()])->values())</script>
            </div>
            <div id="aiMaterialPromoteFields" class="mt-5 hidden space-y-3">
                <div>
                    <label for="aiMaterialCatalogName" class="{{ $fieldLabel }}">Catalog name <span class="text-rose-600">*</span></label>
                    <input id="aiMaterialCatalogName" type="text" class="{{ $fieldInput }}" required>
                </div>
                <div id="aiMaterialDuplicateReview" class="hidden rounded-lg border border-amber-300 bg-amber-50 p-3">
                    <p class="text-xs font-semibold text-amber-900">Possible catalog matches — review before creating</p>
                    <ul id="aiMaterialDuplicateCandidates" class="mt-1 list-disc pl-5 text-xs text-amber-800"></ul>
                    <label class="mt-2 flex items-start gap-2 text-xs text-amber-900">
                        <input id="aiMaterialConfirmDistinct" type="checkbox" class="mt-0.5 rounded border-amber-400 text-brand-700 focus:ring-brand-500">
                        <span>I reviewed these matches and confirm this is a genuinely different item.</span>
                    </label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="aiMaterialCategory" class="{{ $fieldLabel }}">Category</label>
                        <input id="aiMaterialCategory" type="text" value="floral" class="{{ $fieldInput }}">
                    </div>
                    <div>
                        <label for="aiMaterialUnit" class="{{ $fieldLabel }}">Unit</label>
                        <input id="aiMaterialUnit" type="text" value="piece" class="{{ $fieldInput }}">
                    </div>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700"><input id="aiMaterialPerishable" type="checkbox" checked class="h-4 w-4 rounded border-slate-300 text-brand-700 focus:ring-brand-500"> Perishable material</label>
            </div>
            <p id="aiMaterialModalError" class="mt-3 hidden text-sm text-rose-700" role="alert"></p>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" onclick="closeAiMaterialModal()" class="{{ $btnSecondary }}">Cancel</button>
                <button type="button" id="aiMaterialModalSubmit" onclick="submitAiMaterialModal()" class="{{ $btnPrimary }}">Continue</button>
            </div>
        </div>
    </div>

    <!-- Substitute Modal -->
    <div id="substituteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 hidden" role="dialog" aria-modal="true" aria-labelledby="substituteModalTitle">
        <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                <h3 id="substituteModalTitle" class="text-base font-semibold text-slate-900">Select Substitute Item</h3>
                <button type="button" onclick="closeSubstituteModal()" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="max-h-[60vh] overflow-y-auto p-6">
                <p class="mb-4 text-sm text-slate-600">Choose a designated alternative for this item:</p>
                <div id="substitutesList" class="space-y-2.5">
                    <!-- Substitutes will be rendered here by JS -->
                </div>
            </div>
            <div class="flex justify-end border-t border-slate-200 px-6 py-4">
                <button type="button" onclick="closeSubstituteModal()" class="{{ $btnSecondary }}">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const ACTIVE = ['border-brand-600', 'bg-brand-50', 'text-brand-800'];
            const INACTIVE = ['border-slate-200', 'bg-brand-50', 'text-brand-800', 'text-slate-600', 'hover:border-slate-300', 'hover:text-slate-800'];
            const storageKey = 'raflora_admin_booking_tab_{{ $booking->id }}';

            const sectionModal = document.getElementById('booking-section-modal');
            const STATE_CHIPS = {
                done: ['bg-emerald-100', 'text-emerald-800', 'Completed'],
                action: ['bg-orange-100', 'text-orange-800', 'Needs action'],
                waiting: ['bg-sky-100', 'text-sky-800', 'Waiting for client'],
            };
            let lastFocus = null;

            // Overview is the page itself; every other section opens in the pop-up over it.
            function switchTab(tabId) {
                const selected = document.getElementById(tabId);
                if (!selected || !selected.classList.contains('tab-content')) return;
                const isOverview = tabId === 'tab-overview';

                document.querySelectorAll('.tab-content').forEach(el => {
                    if (el.id === 'tab-overview') return;
                    el.classList.toggle('hidden', el !== selected);
                    el.classList.toggle('block', el === selected);
                });

                if (isOverview) {
                    if (!sectionModal.classList.contains('hidden')) {
                        sectionModal.classList.add('hidden');
                        sectionModal.classList.remove('flex');
                        document.body.classList.remove('overflow-hidden');
                        if (lastFocus) lastFocus.focus({ preventScroll: true });
                    }
                } else {
                    const button = document.getElementById(tabId + '-button');
                    document.getElementById('booking-section-modal-title').textContent = button.querySelector('span:not([aria-hidden]):not(.sr-only)')?.textContent.trim() || '';
                    document.getElementById('booking-section-modal-icon').className = (button.querySelector('i')?.className || '').replace('opacity-80', '').trim();
                    const chip = document.getElementById('booking-section-modal-state');
                    const chipStyle = STATE_CHIPS[button.dataset.tabState];
                    chip.className = 'rounded-full px-2 py-0.5 text-[11px] font-semibold ' + (chipStyle ? chipStyle.slice(0, 2).join(' ') : 'hidden');
                    chip.textContent = chipStyle ? chipStyle[2] : '';
                    if (sectionModal.classList.contains('hidden')) lastFocus = document.activeElement;
                    sectionModal.classList.remove('hidden');
                    sectionModal.classList.add('flex');
                    document.getElementById('booking-section-modal-body').scrollTop = 0;
                    document.body.classList.add('overflow-hidden');
                    sectionModal.querySelector('[data-section-modal-panel]').focus?.({ preventScroll: true });
                }

                document.querySelectorAll('.tab-button').forEach(btn => {
                    const isActive = btn.dataset.target === tabId;
                    btn.classList.remove(...(isActive ? INACTIVE : ACTIVE));
                    btn.classList.add(...(isActive ? ACTIVE : INACTIVE));
                    btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
                    btn.setAttribute('tabindex', isActive ? '0' : '-1');
                });

                // Remember the tab so a form submit (which reloads the page) returns here.
                try { sessionStorage.setItem(storageKey, tabId); } catch (e) {}
                if (history.replaceState) {
                    history.replaceState(null, '', '#' + tabId.replace('tab-', ''));
                }
            }
            window.switchTab = switchTab;

            // Arrow keys move between tabs (WAI-ARIA tabs pattern).
            document.querySelectorAll('.tab-button').forEach((btn, index, all) => {
                btn.addEventListener('keydown', (event) => {
                    if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;
                    event.preventDefault();
                    const last = all.length - 1;
                    const next = event.key === 'Home' ? 0
                        : event.key === 'End' ? last
                        : event.key === 'ArrowRight' ? (index === last ? 0 : index + 1)
                        : (index === 0 ? last : index - 1);
                    all[next].focus();
                });
            });

            // "Next step" shortcut: a section id opens its pop-up; anything else is a card on the Overview.
            window.goToBookingSection = function (target) {
                const el = document.getElementById(target);
                if (!el) return;
                if (el.classList.contains('tab-content')) {
                    switchTab(target);
                    return;
                }
                // A card inside a section opens that section's pop-up and scrolls to the card.
                const section = el.closest('.tab-content');
                if (section && section.id !== 'tab-overview') {
                    switchTab(section.id);
                    setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 60);
                    return;
                }
                switchTab('tab-overview');
                el.closest('section')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            };

            sectionModal.addEventListener('click', (event) => {
                if (event.target === sectionModal) switchTab('tab-overview');
            });
            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape' || sectionModal.classList.contains('hidden')) return;
                // A pop-up opened from inside the section (material review, confirm, etc.) closes first.
                const nested = [...document.querySelectorAll('[aria-modal="true"]')].some(el => el !== sectionModal && el.getClientRects().length > 0);
                if (nested) return;
                event.stopImmediatePropagation();
                switchTab('tab-overview');
            });

            document.addEventListener('DOMContentLoaded', () => {
                const fromHash = location.hash ? 'tab-' + location.hash.slice(1) : null;
                let stored = null;
                try { stored = sessionStorage.getItem(storageKey); } catch (e) {}
                const initial = [fromHash, stored].find(id => id && document.getElementById(id)?.classList.contains('tab-content'));
                if (initial && initial !== 'tab-overview') {
                    switchTab(initial);
                }
            });
        })();

        // Floating messages panel: stays available on every tab and reopens after a page reload.
        (function () {
            const panel = document.getElementById('admin-chat-panel');
            const launcher = document.getElementById('admin-chat-launcher');
            const launcherIcon = document.getElementById('admin-chat-launcher-icon');
            if (!panel || !launcher) return;
            const storageKey = 'raflora_admin_booking_chat_{{ $booking->id }}';
            const ACTIVE = ['bg-white', 'text-brand-800', 'shadow-sm'];
            const INACTIVE = ['text-slate-500', 'hover:text-slate-800'];
            let currentView = 'messages';

            function remember(open) {
                try { sessionStorage.setItem(storageKey, JSON.stringify({ open, view: currentView })); } catch (e) {}
            }

            function setView(view) {
                currentView = view === 'meetings' ? 'meetings' : 'messages';
                document.getElementById('admin-chat-view-messages').classList.toggle('hidden', currentView !== 'messages');
                document.getElementById('admin-chat-view-meetings').classList.toggle('hidden', currentView !== 'meetings');
                panel.querySelectorAll('.admin-chat-view-btn').forEach(btn => {
                    const isActive = btn.dataset.chatView === currentView;
                    btn.classList.remove(...(isActive ? INACTIVE : ACTIVE));
                    btn.classList.add(...(isActive ? ACTIVE : INACTIVE));
                    btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
                if (panel.classList.contains('hidden')) return;
                if (currentView === 'messages') document.getElementById('comm-refresh-btn')?.click();
                remember(true);
            }

            function openChat(view) {
                panel.classList.remove('hidden');
                panel.classList.add('flex');
                launcher.setAttribute('aria-expanded', 'true');
                launcher.setAttribute('aria-label', 'Close messages');
                launcherIcon.className = 'fa-solid fa-xmark text-xl';
                setView(view || currentView);
                remember(true);
                if (currentView === 'messages') document.getElementById('comm-message-text')?.focus({ preventScroll: true });
            }

            function closeChat() {
                panel.classList.add('hidden');
                panel.classList.remove('flex');
                launcher.setAttribute('aria-expanded', 'false');
                launcher.setAttribute('aria-label', 'Open messages and client meetings');
                launcherIcon.className = 'fa-solid fa-comments text-xl';
                remember(false);
            }

            window.openBookingChat = openChat;
            launcher.addEventListener('click', () => panel.classList.contains('hidden') ? openChat() : closeChat());
            document.getElementById('admin-chat-close').addEventListener('click', () => { closeChat(); launcher.focus(); });
            panel.querySelectorAll('.admin-chat-view-btn').forEach(btn => btn.addEventListener('click', () => setView(btn.dataset.chatView)));
            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape' || panel.classList.contains('hidden')) return;
                // Let an open pop-up (material review, substitute, etc.) close first.
                if ([...document.querySelectorAll('[aria-modal="true"]')].some(el => el.getClientRects().length > 0)) return;
                closeChat();
                launcher.focus();
            });

            document.addEventListener('DOMContentLoaded', () => {
                let saved = null;
                try { saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null'); } catch (e) {}
                if (panel.dataset.meetingError === '1') {
                    openChat('meetings');
                } else if (location.hash === '#communication') {
                    openChat('messages');
                } else if (saved && saved.open) {
                    openChat(saved.view);
                } else {
                    setView(saved?.view || 'messages');
                }
            });
        })();

        function openBookingModal(id) {
            document.getElementById(id).classList.remove('hidden');
            document.getElementById(id).classList.add('flex');
        }

        function closeBookingModal(id) {
            document.getElementById(id).classList.add('hidden');
            document.getElementById(id).classList.remove('flex');
        }
    </script>

    <script>
        let activeAiMaterialIndex = null;
        let activeAiMaterialMode = null;

        function openAiMaterialModal(index, mode) {
            activeAiMaterialIndex = index;
            activeAiMaterialMode = mode;
            const modal = document.getElementById('aiMaterialModal');
            const linkFields = document.getElementById('aiMaterialLinkFields');
            const promoteFields = document.getElementById('aiMaterialPromoteFields');
            const description = document.getElementById('aiMaterialModalDescription');
            const error = document.getElementById('aiMaterialModalError');
            error.classList.add('hidden');
            document.getElementById('aiMaterialConfirmDistinct').checked = false;
            linkFields.classList.toggle('hidden', mode !== 'link');
            promoteFields.classList.toggle('hidden', mode !== 'promote');
            const itemName = document.getElementById(`display_name_${index}`)?.textContent.trim() || '';
            if (mode === 'promote') {
                // Start from the item's own name; Admin can still edit it.
                document.getElementById('aiMaterialCatalogName').value = itemName;
                renderCatalogDuplicateReview();
            } else {
                // Start the search from the item's own name; Admin picks the right record.
                document.getElementById('aiMaterialInventorySearch').value = itemName;
                pickInventoryForLink(null);
                renderInventorySearch();
            }
            description.textContent = mode === 'link'
                ? 'Choose the existing inventory record that represents this AI suggestion.'
                : 'Provide the catalog details required to create this AI suggestion as a new inventory item.';
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            (mode === 'link' ? document.getElementById('aiMaterialInventorySearch') : document.getElementById('aiMaterialCatalogName')).focus();
        }

        // Link to Existing Inventory: search active inventory by name. Matches are ranked: the same
        // name first (case, spacing and punctuation ignored), then singular/plural, then names
        // containing every typed word, then names sharing a word.
        const inventoryForLink = JSON.parse(document.getElementById('aiMaterialInventoryData')?.textContent || '[]');
        const catalogSimilarityItems = JSON.parse(document.getElementById('aiMaterialSimilarityData')?.textContent || '[]');
        const nameKey = (text) => String(text || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
        const singularKey = (text) => nameKey(text).split(' ').map((word) => (word.length <= 3 || /(ss|us|is)$/.test(word))
            ? word
            : word.replace(/ies$/, 'y').replace(/(sh|ch|x|z)es$/, '$1').replace(/s$/, '')).join(' ');
        const editDistance = (first, second) => {
            const row = Array.from({ length: second.length + 1 }, (_, index) => index);
            for (let i = 1; i <= first.length; i++) {
                let diagonal = row[0];
                row[0] = i;
                for (let j = 1; j <= second.length; j++) {
                    const previous = row[j];
                    row[j] = Math.min(row[j] + 1, row[j - 1] + 1, diagonal + (first[i - 1] === second[j - 1] ? 0 : 1));
                    diagonal = previous;
                }
            }
            return row[second.length];
        };

        function possibleCatalogMatches(name) {
            const typed = singularKey(name).split(' ').filter(Boolean);
            if (!typed.length) return [];
            const sortedTyped = typed.slice().sort().join(' ');
            return catalogSimilarityItems.map((item) => {
                const words = singularKey(item.name).split(' ').filter(Boolean);
                const sortedWords = words.slice().sort().join(' ');
                const overlap = new Set(typed.filter((word) => words.includes(word))).size / Math.max(typed.length, words.length);
                const spelling = 1 - editDistance(sortedTyped, sortedWords) / Math.max(sortedTyped.length, sortedWords.length, 1);
                return { ...item, score: Math.max(overlap, spelling), key: sortedWords };
            }).filter((item) => item.score >= 0.68 && item.key !== sortedTyped)
                .sort((a, b) => b.score - a.score || a.name.localeCompare(b.name))
                .slice(0, 5);
        }

        function renderCatalogDuplicateReview() {
            const matches = possibleCatalogMatches(document.getElementById('aiMaterialCatalogName').value);
            const panel = document.getElementById('aiMaterialDuplicateReview');
            const list = document.getElementById('aiMaterialDuplicateCandidates');
            const confirm = document.getElementById('aiMaterialConfirmDistinct');
            list.replaceChildren(...matches.map((item) => {
                const row = document.createElement('li');
                row.textContent = item.name + (item.archived ? ' (archived)' : '');
                return row;
            }));
            panel.classList.toggle('hidden', matches.length === 0);
            if (!matches.length) confirm.checked = false;
            return matches;
        }

        document.getElementById('aiMaterialCatalogName')?.addEventListener('input', renderCatalogDuplicateReview);

        function inventoryMatchScore(item, query) {
            const key = nameKey(item.name);
            const typed = nameKey(query);
            if (!typed) return 1;
            if (key === typed) return 100;
            const sortedWords = (text) => text.split(' ').filter(Boolean).sort().join(' ');
            if (sortedWords(key) === sortedWords(typed)) return 95;
            if (singularKey(item.name) === singularKey(query)) return 90;
            if (sortedWords(singularKey(item.name)) === sortedWords(singularKey(query))) return 88;
            const words = singularKey(query).split(' ').filter(Boolean);
            const itemWords = singularKey(item.name).split(' ');
            const shared = words.filter((word) => itemWords.some((itemWord) => itemWord.startsWith(word))).length;
            if (shared === words.length) return 60 + (key.startsWith(typed.split(' ')[0]) ? 5 : 0);
            return shared > 0 ? 10 * shared : 0;
        }

        function pickInventoryForLink(id) {
            document.getElementById('aiMaterialInventorySelect').value = id ? String(id) : '';
            document.querySelectorAll('#aiMaterialInventoryResults [role="option"]').forEach((option) => {
                const selected = option.dataset.id === String(id);
                option.setAttribute('aria-selected', selected ? 'true' : 'false');
                option.classList.toggle('bg-brand-50', selected);
                option.querySelector('[data-picked]')?.classList.toggle('invisible', !selected);
            });
            if (id) document.getElementById('aiMaterialModalError').classList.add('hidden');
        }

        function renderInventorySearch() {
            const query = document.getElementById('aiMaterialInventorySearch').value;
            const list = document.getElementById('aiMaterialInventoryResults');
            const picked = document.getElementById('aiMaterialInventorySelect').value;
            const matches = inventoryForLink
                .map((item) => ({ item, score: inventoryMatchScore(item, query) }))
                .filter((match) => match.score > 0)
                .sort((a, b) => b.score - a.score || a.item.name.localeCompare(b.item.name))
                .slice(0, 30);

            list.replaceChildren(...matches.map(({ item, score }) => {
                const option = document.createElement('li');
                option.setAttribute('role', 'option');
                option.tabIndex = 0;
                option.dataset.id = String(item.id);
                option.setAttribute('aria-selected', String(item.id) === picked ? 'true' : 'false');
                option.className = 'flex cursor-pointer items-start gap-2.5 px-3 py-2.5 text-sm hover:bg-slate-50 focus:bg-slate-50 focus:outline-none' + (String(item.id) === picked ? ' bg-brand-50' : '');

                const check = document.createElement('i');
                check.dataset.picked = '';
                check.className = 'fa-solid fa-circle-check mt-0.5 text-brand-700' + (String(item.id) === picked ? '' : ' invisible');
                check.setAttribute('aria-hidden', 'true');

                const text = document.createElement('div');
                text.className = 'min-w-0 flex-1';
                const name = document.createElement('p');
                name.className = 'font-medium text-slate-900';
                name.textContent = item.name;
                if (score >= 90) {
                    const badge = document.createElement('span');
                    badge.className = 'ml-2 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800';
                    badge.textContent = score === 100 ? 'Same name' : 'Singular/plural';
                    name.append(badge);
                }
                const meta = document.createElement('p');
                meta.className = 'text-xs text-slate-500';
                meta.textContent = [item.code, item.category, `${item.stock} ${item.unit || ''} in stock`.trim(), item.perishable ? 'Fresh / perishable' : 'Non-perishable'].filter(Boolean).join(' · ');
                text.append(name, meta);

                option.append(check, text);
                option.addEventListener('click', () => pickInventoryForLink(item.id));
                option.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); pickInventoryForLink(item.id); }
                    if (event.key === 'ArrowDown') { event.preventDefault(); option.nextElementSibling?.focus(); }
                    if (event.key === 'ArrowUp') { event.preventDefault(); (option.previousElementSibling || document.getElementById('aiMaterialInventorySearch')).focus(); }
                });
                return option;
            }));

            document.getElementById('aiMaterialInventoryEmpty').classList.toggle('hidden', matches.length > 0);
            // Only partial word matches: say so, so a near name (Floral Foam vs Floral Spray) is not picked by mistake.
            document.getElementById('aiMaterialInventoryPartial').classList.toggle('hidden', !matches.length || matches[0].score >= 60 || !nameKey(query));
            list.classList.toggle('hidden', matches.length === 0);
        }

        document.getElementById('aiMaterialInventorySearch')?.addEventListener('input', renderInventorySearch);
        document.getElementById('aiMaterialInventorySearch')?.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                document.querySelector('#aiMaterialInventoryResults [role="option"]')?.focus();
            }
            if (event.key === 'Enter') event.preventDefault();
        });

        function closeAiMaterialModal() {
            const modal = document.getElementById('aiMaterialModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            activeAiMaterialIndex = null;
            activeAiMaterialMode = null;
        }

        function submitAiMaterialModal() {
            const error = document.getElementById('aiMaterialModalError');
            const index = activeAiMaterialIndex;
            if (index === null) return;

            ['aiLinkForm_', 'aiPromoteForm_'].forEach((prefix) => {
                document.getElementById(prefix + index)?.querySelectorAll('[data-ai-row-field]').forEach((hidden) => {
                    const source = document.getElementById((hidden.dataset.aiRowField === 'quantity' ? 'input_qty_' : 'input_price_') + index);
                    hidden.value = source ? source.value : '';
                });
            });

            if (activeAiMaterialMode === 'link') {
                const inventoryId = document.getElementById('aiMaterialInventorySelect').value;
                if (!inventoryId) {
                    error.textContent = 'Pick the matching inventory item from the list before continuing.';
                    error.classList.remove('hidden');
                    return;
                }
                document.getElementById(`aiLinkInventory_${index}`).value = inventoryId;
                document.getElementById(`aiLinkForm_${index}`).submit();
                return;
            }

            const catalogName = document.getElementById('aiMaterialCatalogName').value.trim();
            if (!catalogName) {
                error.textContent = 'Catalog name is required.';
                error.classList.remove('hidden');
                return;
            }
            if (possibleCatalogMatches(catalogName).length > 0 && !document.getElementById('aiMaterialConfirmDistinct').checked) {
                error.textContent = 'Review the possible catalog matches. Link the existing item, or confirm below if this is genuinely different.';
                error.classList.remove('hidden');
                return;
            }
            document.getElementById(`aiPromoteName_${index}`).value = catalogName;
            document.getElementById(`aiPromoteDistinct_${index}`).value = document.getElementById('aiMaterialConfirmDistinct').checked ? '1' : '0';
            document.getElementById(`aiPromoteCategory_${index}`).value = document.getElementById('aiMaterialCategory').value.trim();
            document.getElementById(`aiPromoteUnit_${index}`).value = document.getElementById('aiMaterialUnit').value.trim();
            document.getElementById(`aiPromotePerishable_${index}`).value = document.getElementById('aiMaterialPerishable').checked ? '1' : '0';
            document.getElementById(`aiPromoteForm_${index}`).submit();
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && activeAiMaterialIndex !== null) closeAiMaterialModal();
        });
    </script>

    <script>
        const inventoryData = @json(isset($allInventoryItems) ? $allInventoryItems->keyBy('id') : []);
        let currentRowIdx = null;

        function formatCurrency(value) {
            const numericValue = Number.isFinite(Number(value)) ? Number(value) : 0;
            const formatted = new Intl.NumberFormat('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(numericValue);

            return '₱ ' + formatted;
        }

        function formatQtyValue(value) {
            const numericValue = Number(value) || 0;
            return String(Math.max(0, Math.trunc(numericValue)));
        }

        function updateLineTotals() {
            let subtotal = 0;

            document.querySelectorAll('tr[id^="row_"]').forEach((row) => {
                const qtyInput = row.querySelector('input[name$="[quantity]"]');
                const unitPriceInput = row.querySelector('input[name$="[unit_price]"]');
                const removeCheckbox = row.querySelector('input[name$="[remove]"]');
                const totalCell = row.querySelector('[id^="total_cost_"]');
                const nameDisplay = row.querySelector('[id^="display_name_"]');

                if (!qtyInput || !unitPriceInput || !totalCell) {
                    return;
                }

                const qty = Number(qtyInput.value) || 0;
                const unitPrice = Number(unitPriceInput.value) || 0;
                const isRemoved = removeCheckbox ? removeCheckbox.checked : false;
                const lineTotal = qty * unitPrice;

                qtyInput.value = formatQtyValue(qtyInput.value);
                totalCell.textContent = formatCurrency(lineTotal);

                if (nameDisplay) {
                    nameDisplay.style.textDecoration = isRemoved ? 'line-through' : '';
                    nameDisplay.style.opacity = isRemoved ? '0.55' : '1';
                }

                row.classList.toggle('opacity-50', isRemoved);

                if (!isRemoved) {
                    subtotal += lineTotal;
                }
            });

            syncConfirmLineValues();

            const rawSubtotalValue = document.getElementById('raw-wholesale-subtotal');
            const calculatedTotalValue = document.getElementById('calculated-quotation-total');
            const multiplierInput = document.getElementById('markup-multiplier');
            const finalOverrideInput = document.getElementById('final-quoted-total-override');

            if (rawSubtotalValue) {
                rawSubtotalValue.textContent = formatCurrency(subtotal);
            }

            const multiplier = Number(multiplierInput?.value) || 0;
            const calculatedTotal = subtotal * multiplier;

            if (calculatedTotalValue) {
                calculatedTotalValue.textContent = formatCurrency(calculatedTotal);
            }

            if (finalOverrideInput && !finalOverrideInput.matches(':focus')) {
                // Only auto-update if the user hasn't manually overridden the value
                if (finalOverrideInput.dataset.manualOverride !== 'true') {
                    finalOverrideInput.value = Number.isFinite(calculatedTotal) ? calculatedTotal.toFixed(2) : '0.00';
                }
            }
        }

        function setupItemCalculationListeners() {
            document.querySelectorAll('tr[id^="row_"]').forEach((row) => {
                const qtyInput = row.querySelector('input[name$="[quantity]"]');
                const unitPriceInput = row.querySelector('input[name$="[unit_price]"]');
                const removeCheckbox = row.querySelector('input[name$="[remove]"]');

                [qtyInput, unitPriceInput].forEach((input) => {
                    if (input) {
                        input.addEventListener('input', updateLineTotals);
                        input.addEventListener('change', updateLineTotals);
                    }
                });

                if (removeCheckbox) {
                    removeCheckbox.addEventListener('change', updateLineTotals);
                }
            });

            // "Confirm all" saves the rows it confirms; warn when edits in any other row would be discarded.
            const confirmAllForm = document.getElementById('confirmAllMaterialsForm');
            if (confirmAllForm) {
                const baseMessage = confirmAllForm.dataset.confirmMessage;
                document.querySelectorAll('tr[id^="row_"] input[name$="[quantity]"], tr[id^="row_"] input[name$="[unit_price]"]').forEach((input) => {
                    input.addEventListener('input', () => {
                        const itemId = input.closest('tr')?.querySelector('input[name$="[booking_item_id]"]')?.value;
                        if (itemId && confirmAllForm.querySelector(`input[data-sync-item="${CSS.escape(itemId)}"]`)) return;
                        confirmAllForm.dataset.confirmMessage = 'Quantities or prices you typed in rows that are already confirmed are not saved by Confirm all and will be discarded (Approve & Send saves them). ' + baseMessage;
                    });
                });
            }

            const multiplierInput = document.getElementById('markup-multiplier');
            if (multiplierInput) {
                multiplierInput.addEventListener('input', updateLineTotals);
                multiplierInput.addEventListener('change', updateLineTotals);
            }

            const finalOverrideInput = document.getElementById('final-quoted-total-override');
            const finalOverrideFlag = document.getElementById('final-price-overridden');
            const markManualOverride = () => {
                finalOverrideInput.dataset.manualOverride = 'true';
                if (finalOverrideFlag) {
                    finalOverrideFlag.value = '1';
                }
            };

            if (finalOverrideInput) {
                // Initial check: if the server provided a value that differs from the calculation, treat it as a manual override
                const initialVal = Number(finalOverrideInput.value);
                const calculatedTotal = Number(document.getElementById('calculated-quotation-total')?.textContent.replace(/[^0-9.-]+/g, '')) || 0;
                if (initialVal > 0 && Math.abs(initialVal - calculatedTotal) > 0.01) {
                    markManualOverride();
                }

                finalOverrideInput.addEventListener('input', markManualOverride);

                finalOverrideInput.addEventListener('focus', () => {
                    finalOverrideInput.dataset.manualEdit = 'true';
                });

                finalOverrideInput.addEventListener('blur', () => {
                    finalOverrideInput.dataset.manualEdit = 'false';
                    updateLineTotals();
                });
            }
        }

        // Confirm Material / Confirm all post the Qty and Unit Cost typed in the table for the rows
        // they confirm (the server saves them with the confirmation): copy each row's current values
        // into those forms' hidden fields.
        function syncConfirmLineValues() {
            const rowIndexByItem = {};
            document.querySelectorAll('tr[id^="row_"] input[name$="[booking_item_id]"]').forEach((hidden) => {
                const match = hidden.name.match(/^items\[(\d+)\]/);
                if (match) rowIndexByItem[hidden.value] = match[1];
            });
            document.querySelectorAll('tr[id^="row_"] input[name$="[source_booking_item_ids][]"]').forEach((hidden) => {
                const match = hidden.name.match(/^items\[(\d+)\]/);
                if (match) rowIndexByItem[hidden.value] = match[1];
            });

            document.querySelectorAll('input[data-sync-group-row]').forEach((hidden) => {
                const rowIndex = hidden.dataset.syncGroupRow;
                const source = document.getElementById((hidden.dataset.syncField === 'quantity' ? 'input_qty_' : 'input_price_') + rowIndex);
                if (source && !source.disabled) hidden.value = source.value;
            });

            const groupedValues = new Map();
            document.querySelectorAll('input[data-sync-item]').forEach((hidden) => {
                const rowIndex = rowIndexByItem[hidden.dataset.syncItem];
                if (rowIndex === undefined) return;
                const values = groupedValues.get(rowIndex) || [];
                values.push(hidden);
                groupedValues.set(rowIndex, values);
            });
            groupedValues.forEach((fields, rowIndex) => {
                const quantityInput = document.getElementById('input_qty_' + rowIndex);
                const priceInput = document.getElementById('input_price_' + rowIndex);
                if (!quantityInput || !priceInput || quantityInput.disabled || priceInput.disabled) return;

                const quantityFields = fields.filter((field) => field.dataset.syncField === 'quantity');
                const totalQuantity = Number(quantityInput.value);
                const totalWeight = quantityFields.reduce((sum, field) => sum + Number(field.dataset.originalQuantity || 0), 0);
                let remaining = totalQuantity;
                quantityFields.forEach((field, index) => {
                    const share = index === quantityFields.length - 1
                        ? remaining
                        : (totalWeight > 0
                            ? Math.floor(totalQuantity * Number(field.dataset.originalQuantity || 0) / totalWeight)
                            : Math.floor(totalQuantity / quantityFields.length));
                    field.value = String(share);
                    remaining -= share;
                });

                const originalAmount = quantityFields.reduce((sum, field) => (
                    sum + Number(field.dataset.originalQuantity || 0) * Number(field.dataset.originalPrice || 0)
                ), 0);
                const originalAverage = totalWeight > 0 ? originalAmount / totalWeight : null;
                if (originalAverage === null || Number(priceInput.value).toFixed(2) !== originalAverage.toFixed(2)) {
                    fields.filter((field) => field.dataset.syncField === 'unit_price')
                        .forEach((field) => { field.value = priceInput.value; });
                }
            });
        }

        function escapeSubstituteText(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        function openSubstituteModal(rowIdx, itemId) {
            currentRowIdx = rowIdx;
            const item = inventoryData[itemId];
            const listDiv = document.getElementById('substitutesList');
            listDiv.innerHTML = '';

            if (!item || !item.substitutes || item.substitutes.length === 0) {
                listDiv.innerHTML = '<p class="text-sm text-slate-500">No designated substitutes available.</p>';
            } else {
                item.substitutes.forEach(sub => {
                    const isLow = sub.current_stock <= 0;
                    const stockBadge = isLow
                        ? `<span class="ml-2 rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-800">Out of Stock</span>`
                        : `<span class="ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">${escapeSubstituteText(sub.current_stock)} in stock</span>`;

                    listDiv.innerHTML += `
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 transition hover:bg-slate-50">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">${escapeSubstituteText(sub.name)} ${stockBadge}</p>
                                <p class="text-xs text-slate-500">Unit Cost: ₱${escapeSubstituteText(sub.unit_cost)}</p>
                            </div>
                            <button type="button" onclick="applySubstitute(${Number(sub.id)})" class="rounded-lg bg-brand-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-800 disabled:opacity-50" ${isLow ? 'disabled' : ''}>
                                Select
                            </button>
                        </div>
                    `;
                });
            }

            document.getElementById('substituteModal').classList.remove('hidden');
        }

        function closeSubstituteModal() {
            document.getElementById('substituteModal').classList.add('hidden');
            currentRowIdx = null;
        }

        function applySubstitute(subId) {
            if (currentRowIdx === null) return;

            const sub = inventoryData[subId];
            if (!sub) return;

            document.getElementById('input_name_' + currentRowIdx).value = sub.name;
            document.getElementById('input_inventory_' + currentRowIdx).value = sub.id;

            const displaySpan = document.getElementById('display_name_' + currentRowIdx);
            displaySpan.innerHTML = `${escapeSubstituteText(sub.name)} <span class="ml-2 rounded-full bg-navy-50 px-2 py-0.5 text-[11px] font-semibold text-navy-700">Swapped</span>`;

            const priceInput = document.getElementById('input_price_' + currentRowIdx);
            priceInput.value = sub.unit_cost;

            const qtyInput = document.getElementById('input_qty_' + currentRowIdx);
            const newTotal = (parseFloat(qtyInput.value) * parseFloat(sub.unit_cost)).toFixed(2);
            document.getElementById('total_cost_' + currentRowIdx).innerText = formatCurrency(newTotal);

            const row = document.getElementById('row_' + currentRowIdx);
            closeSubstituteModal();

            row.querySelectorAll('[data-stock-badge="out"], button[onclick^="openSubstituteModal"]').forEach(b => b.style.display = 'none');

            updateLineTotals();
        }

        document.addEventListener('DOMContentLoaded', function () {
            setupItemCalculationListeners();
            updateLineTotals();
        });
    </script>
</x-admin-layout>
