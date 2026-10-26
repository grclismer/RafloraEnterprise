<x-staff-layout title="Assigned Event">
    <div class="space-y-6">
        <div class="flex flex-col gap-3 border-b border-slate-200 pb-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.24em] text-purple-600">Assigned event</p>
                <h2 class="serif mt-1 text-3xl font-bold text-slate-900">{{ ucfirst($booking->event_type ?? 'Event') }}</h2>
                <p class="mt-1 text-sm text-slate-500">Booking #{{ $booking->id }} · {{ $booking->status_display_label }}</p>
            </div>

            <a href="{{ route('staff.dashboard') }}" class="inline-flex items-center gap-2 rounded-full border border-purple-200 bg-purple-50 px-3 py-2 text-sm font-semibold text-purple-700 transition hover:bg-purple-100">
                <i class="fa-solid fa-arrow-left"></i>
                Back to workspace
            </a>
        </div>

        <section class="grid grid-cols-3 gap-2 sm:gap-4" aria-label="Event schedule">
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:p-5">
                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400 sm:text-[11px]"><span class="mr-1 hidden sm:inline" aria-hidden="true"><i class="fa-regular fa-calendar"></i></span>Event date</p>
                <p class="mt-1.5 text-sm font-semibold text-slate-900 sm:mt-2 sm:text-base">{{ optional($booking->event_date)->format('F j, Y') ?? 'Not scheduled' }}</p>
            </div>
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:p-5">
                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400 sm:text-[11px]"><span class="mr-1 hidden sm:inline" aria-hidden="true"><i class="fa-regular fa-clock"></i></span>Event time</p>
                <p class="mt-1.5 text-sm font-semibold text-slate-900 sm:mt-2 sm:text-base">{{ $booking->event_time ?? 'Not specified' }}@if($booking->event_time && $booking->end_time) – {{ $booking->end_time }}@endif</p>
            </div>
            <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:p-5">
                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400 sm:text-[11px]"><span class="mr-1 hidden sm:inline" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span>Venue</p>
                <p class="mt-1.5 break-words text-sm font-semibold text-slate-900 sm:mt-2 sm:text-base">{{ $booking->venue ?? 'Not specified' }}</p>
            </div>
        </section>

        @if($booking->venue || $booking->hasVenueCoordinates())
            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="venue-location-heading">
                <h3 id="venue-location-heading" class="mb-3 font-bold text-slate-900"><i class="fa-solid fa-map-location-dot mr-1.5 text-purple-600" aria-hidden="true"></i>Venue location</h3>
                <x-venue-map :booking="$booking" height="h-64" />
            </section>
        @endif

        {{-- README end-to-end booking workflow; Staff see operational details for the Staff Workflow only --}}
        <x-booking-workflow
            :booking="$booking"
            accent="purple"
            audience="staff"
            id="staff-booking-workflow"
            heading="Booking Workflow"
            description="Your Staff Workflow: Preparation & Reservation → Dispatch → Event Execution → Material Return → Inventory Reconciliation → Completion." />

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="font-bold text-slate-900">Event information</h3>
                    <p class="mt-1 text-sm text-slate-500">Operational details for this assigned event.</p>
                </div>
                <span class="rounded-full bg-purple-50 px-3 py-1 text-xs font-semibold text-purple-700">{{ $booking->status_display_label }}</span>
            </div>

            <dl class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-[0.2em] text-slate-400">Client</dt>
                    <dd class="mt-1 text-sm text-slate-700">{{ $booking->client?->full_name ?? $booking->guest_name ?? 'Guest' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-bold uppercase tracking-[0.2em] text-slate-400">Contact</dt>
                    <dd class="mt-1 text-sm text-slate-700">{{ $booking->client?->phone ?? $booking->guest_phone ?? 'Not provided' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-[11px] font-bold uppercase tracking-[0.2em] text-slate-400">Client notes</dt>
                    <dd class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $booking->special_requests ?: 'No special requests provided.' }}</dd>
                </div>
            </dl>

            <x-design-brief-summary :booking="$booking" class="mt-5" />
            <x-decor-area-breakdown :materials="$aiMaterials ?? []" :booking="$booking" view="staff" class="mt-4" />
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            @php
                $completedChecklist = $booking->staffChecklistItems->where('is_completed', true)->count();
            @endphp
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 class="font-bold text-slate-900">Event preparation checklist</h3>
                    <p class="mt-1 text-sm text-slate-500">Update only the preparation work for this assigned event.</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ $completedChecklist }}/{{ $booking->staffChecklistItems->count() }} complete</span>
            </div>

            <div class="mt-5 space-y-3">
                @foreach($booking->staffChecklistItems as $checklistItem)
                    <form method="POST" action="{{ route('staff.events.checklist.update', ['booking' => $booking->id, 'checklist' => $checklistItem->id]) }}" class="rounded-2xl border {{ $checklistItem->is_completed ? 'border-emerald-200 bg-emerald-50/30' : 'border-slate-200 bg-slate-50' }} p-4 transition-colors">
                        @csrf
                        @method('PUT')
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="flex items-start gap-3">
                                <input type="hidden" name="is_completed" value="0">
                                <input id="checklist-check-{{ $checklistItem->id }}" 
                                       type="checkbox" 
                                       name="is_completed" 
                                       value="1" 
                                       {{ $checklistItem->is_completed ? 'checked' : '' }} 
                                       class="mt-1 h-5 w-5 rounded border-slate-300 text-purple-700 focus:ring-purple-500">
                                <div>
                                    <label for="checklist-check-{{ $checklistItem->id }}" class="font-semibold text-slate-800 cursor-pointer {{ $checklistItem->is_completed ? 'line-through text-slate-500' : '' }}">
                                        {{ $checklistItem->title }}
                                    </label>
                                    <p class="mt-1 text-xs {{ $checklistItem->is_completed ? 'text-emerald-700 font-semibold' : 'text-slate-500' }}">
                                        @if($checklistItem->is_completed)
                                            <i class="fa-solid fa-circle-check mr-1 text-emerald-600" aria-hidden="true"></i>Completed
                                        @else
                                            <i class="fa-regular fa-circle mr-1 text-slate-400" aria-hidden="true"></i>Pending
                                        @endif
                                    </p>
                                </div>
                            </div>

                            <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                                <label class="sr-only" for="checklist-notes-{{ $checklistItem->id }}">Checklist notes for {{ $checklistItem->title }}</label>
                                <input id="checklist-notes-{{ $checklistItem->id }}" 
                                       aria-label="Checklist notes for {{ $checklistItem->title }}"
                                       type="text" 
                                       name="notes" 
                                       value="{{ $checklistItem->notes }}" 
                                       placeholder="Add an operational note" 
                                       class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm sm:min-w-[260px] min-h-[40px] focus:ring-2 focus:ring-purple-500">
                                <button type="submit" class="inline-flex min-h-[40px] items-center justify-center gap-2 rounded-xl bg-purple-700 px-4 py-2 text-xs font-semibold text-white transition hover:bg-purple-800 focus:outline-none focus:ring-2 focus:ring-purple-500">
                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                    Save
                                </button>
                            </div>
                        </div>
                    </form>
                @endforeach
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="font-bold text-slate-900">Confirmed materials & Dispatch</h3>
            <p class="mt-1 text-sm text-slate-500">Materials currently recorded and their dispatch status.</p>
            @if(!$canDispatch && $dispatchLockMessage)
                <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $dispatchLockMessage }}</p>
            @endif

            @php
                $hasDispatchable = false;
                foreach ($booking->bookingItems as $i) {
                    if ($i->inventoryItem && $i->confirmed_at && (float) $i->quantity > 0 && !$i->requiresFreshProcurement()) {
                        $hasDispatchable = true;
                        break;
                    }
                }
            @endphp

            @if($hasDispatchable)
                {{-- Mobile Stacked Cards (<sm) to prevent horizontal overflow on small screens --}}
                <div class="mt-5 space-y-3 sm:hidden">
                    @foreach($booking->bookingItems as $item)
                        @if($item->inventoryItem && $item->confirmed_at && (float) $item->quantity > 0 && !$item->requiresFreshProcurement())
                            @php
                                $lock = abs(\App\Models\InventoryTransaction::where('inventory_item_id', $item->inventoryItem->id)->where('booking_id', $booking->id)->where('transaction_type', 'booking_lock')->sum('quantity_change'));
                                $release = \App\Models\InventoryTransaction::where('inventory_item_id', $item->inventoryItem->id)->where('booking_id', $booking->id)->where('transaction_type', 'booking_release')->sum('quantity_change');
                                $netDispatch = abs((float) \App\Models\InventoryTransaction::where('inventory_item_id', $item->inventoryItem->id)->where('booking_id', $booking->id)->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])->sum('quantity_change'));
                                $outstanding = $lock - $release - $netDispatch;
                            @endphp
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <div class="flex items-center justify-between gap-2">
                                    <h4 class="font-semibold text-slate-900 text-sm">{{ $item->item_name ?? $item->inventoryItem?->name ?? 'Material' }}</h4>
                                    @if($outstanding <= 0)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700">
                                            <i class="fa-solid fa-circle-check text-emerald-600" aria-hidden="true"></i> Fully dispatched
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                                    <div class="rounded-xl border border-slate-200 bg-white p-2">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Required</p>
                                        <p class="mt-0.5 text-sm font-semibold text-slate-700">{{ $lock - $release }}</p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 bg-white p-2">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Dispatched</p>
                                        <p class="mt-0.5 text-sm font-semibold text-slate-700">{{ $netDispatch }}</p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 bg-white p-2">
                                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Outstanding</p>
                                        <p class="mt-0.5 text-sm font-semibold {{ $outstanding > 0 ? 'text-amber-700' : 'text-slate-700' }}">{{ $outstanding > 0 ? $outstanding : 0 }}</p>
                                    </div>
                                </div>
                                @if($outstanding > 0 && $canDispatch)
                                    <form method="POST" action="{{ route('staff.events.dispatch', $booking) }}" class="mt-3 flex items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="items[0][inventory_item_id]" value="{{ $item->inventoryItem->id }}">
                                        <input type="hidden" name="reason" value="Staff dispatch">
                                        <label for="dispatch-qty-mobile-{{ $item->inventoryItem->id }}" class="sr-only">Dispatch quantity for {{ $item->item_name ?? $item->inventoryItem?->name ?? 'Material' }}</label>
                                        <input id="dispatch-qty-mobile-{{ $item->inventoryItem->id }}" 
                                               aria-label="Dispatch quantity for {{ $item->item_name ?? $item->inventoryItem?->name ?? 'Material' }}" 
                                               type="number" 
                                               name="items[0][quantity]" 
                                               min="0.01" 
                                               max="{{ $outstanding }}" 
                                               step="0.01" 
                                               class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm min-h-[42px] focus:ring-2 focus:ring-sky-500" 
                                               placeholder="Qty (max {{ $outstanding }})">
                                        <button type="submit" class="inline-flex shrink-0 min-h-[42px] items-center justify-center gap-1.5 rounded-xl bg-sky-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-500">
                                            <i class="fa-solid fa-truck-fast" aria-hidden="true"></i>
                                            Dispatch
                                        </button>
                                    </form>
                                @elseif($outstanding > 0)
                                    <p class="mt-3 text-xs font-medium text-amber-700">Dispatch is currently locked.</p>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>

                {{-- Desktop Table View (sm+) --}}
                <div class="mt-5 hidden sm:block overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-y border-slate-100 bg-slate-50 text-[11px] uppercase tracking-[0.2em] text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Material</th>
                                <th class="px-3 py-3">Required</th>
                                <th class="px-3 py-3">Dispatched</th>
                                <th class="px-3 py-3">Outstanding</th>
                                <th class="px-3 py-3">Dispatch action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($booking->bookingItems as $item)
                                @if($item->inventoryItem && $item->confirmed_at && (float) $item->quantity > 0 && !$item->requiresFreshProcurement())
                                    @php
                                        $lock = abs(\App\Models\InventoryTransaction::where('inventory_item_id', $item->inventoryItem->id)->where('booking_id', $booking->id)->where('transaction_type', 'booking_lock')->sum('quantity_change'));
                                        $release = \App\Models\InventoryTransaction::where('inventory_item_id', $item->inventoryItem->id)->where('booking_id', $booking->id)->where('transaction_type', 'booking_release')->sum('quantity_change');
                                        $netDispatch = abs((float) \App\Models\InventoryTransaction::where('inventory_item_id', $item->inventoryItem->id)->where('booking_id', $booking->id)->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])->sum('quantity_change'));
                                        $outstanding = $lock - $release - $netDispatch;
                                    @endphp
                                    <tr>
                                        <td class="px-3 py-3 font-medium text-slate-800">{{ $item->item_name ?? $item->inventoryItem?->name ?? 'Material' }}</td>
                                        <td class="px-3 py-3 text-slate-600">{{ $lock - $release }}</td>
                                        <td class="px-3 py-3 text-slate-600">{{ $netDispatch }}</td>
                                        <td class="px-3 py-3 text-slate-600">{{ $outstanding > 0 ? $outstanding : 0 }}</td>
                                        <td class="px-3 py-3">
                                            @if($outstanding > 0 && $canDispatch)
                                                <form method="POST" action="{{ route('staff.events.dispatch', $booking) }}" class="flex items-center gap-2">
                                                    @csrf
                                                    <input type="hidden" name="items[0][inventory_item_id]" value="{{ $item->inventoryItem->id }}">
                                                    <input type="hidden" name="reason" value="Staff dispatch">
                                                    <label for="dispatch-qty-desktop-{{ $item->inventoryItem->id }}" class="sr-only">Dispatch quantity for {{ $item->item_name ?? $item->inventoryItem?->name ?? 'Material' }}</label>
                                                    <input id="dispatch-qty-desktop-{{ $item->inventoryItem->id }}" 
                                                           aria-label="Dispatch quantity for {{ $item->item_name ?? $item->inventoryItem?->name ?? 'Material' }}" 
                                                           type="number" 
                                                           name="items[0][quantity]" 
                                                           min="0.01" 
                                                           max="{{ $outstanding }}" 
                                                           step="0.01" 
                                                           class="w-24 rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm" 
                                                           placeholder="Qty">
                                                    <button type="submit" class="rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-700 transition">Dispatch</button>
                                                </form>
                                            @elseif($outstanding > 0)
                                                <span class="text-xs text-amber-700 font-medium">Dispatch locked</span>
                                            @else
                                                <span class="text-xs text-slate-400 font-medium">Fully dispatched</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="mt-5 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-500">
                    No dispatchable materials recorded.
                </div>
            @endif
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div>
                <h3 class="font-bold text-slate-900">Physical stock recording</h3>
                <p class="mt-1 text-sm text-slate-500">Record the quantity physically observed for confirmed materials on this event. Submitted counts will be reviewed by Admin before stock adjustments are applied.</p>
            </div>

            @php
                $recordableItems = $booking->bookingItems->filter(fn ($item) => $item->inventoryItem && $item->confirmed_at);
            @endphp
            <div class="mt-5 space-y-3">
                @forelse($recordableItems as $item)
                    <form method="POST" action="{{ route('staff.events.inventory.update', ['booking' => $booking->id, 'inventoryItem' => $item->inventory_item_id]) }}" class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-end sm:justify-between">
                        @csrf
                        @method('PUT')
                        <div>
                            <p class="font-semibold text-slate-800">{{ $item->item_name ?? $item->inventoryItem->name }}</p>
                            <p class="mt-1 text-xs text-slate-500">System quantity: {{ rtrim(rtrim(number_format((float) $item->inventoryItem->current_stock, 2), '0'), '.') }} {{ $item->inventoryItem->unit }}</p>
                        </div>
                        <div class="flex w-full gap-2 sm:w-auto">
                            <label class="sr-only" for="observed-stock-{{ $item->inventory_item_id }}">Observed stock for {{ $item->item_name ?? $item->inventoryItem->name }}</label>
                            <input id="observed-stock-{{ $item->inventory_item_id }}" 
                                   aria-label="Observed stock for {{ $item->item_name ?? $item->inventoryItem->name }}"
                                   type="number" 
                                   name="observed_stock" 
                                   min="0" 
                                   step="0.01" 
                                   required 
                                   placeholder="Observed quantity" 
                                   class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm sm:w-44 min-h-[42px] focus:ring-2 focus:ring-sky-500">
                            <button type="submit" class="inline-flex shrink-0 min-h-[42px] items-center justify-center gap-2 rounded-xl bg-sky-700 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-800">
                                <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                                Submit Count
                            </button>
                        </div>
                    </form>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center text-sm text-slate-500">No confirmed inventory materials are available for physical counting.</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="rounded-md bg-sky-100 px-2 py-0.5 text-xs font-bold text-sky-800 uppercase tracking-wider">Step 1 of 2</span>
                        <h3 class="font-bold text-slate-900">Material Return (Physical Count)</h3>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">Record physical quantities returned to the warehouse (Good, Damaged, Lost). If any damage or loss is recorded, proceed to Step 2 below to log observations and attach required photo evidence.</p>
                </div>
                @if($returnRecord)
                    <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">{{ $returnRecord->status }}</span>
                @endif
            </div>

            @if($returnRecord)
                @php
                    $hasDamagedReported = $returnRecord->returnItems->contains(fn($item) => (float) $item->quantity_damaged > 0);
                    $hasLostReported = $returnRecord->returnItems->contains(fn($item) => (float) $item->quantity_lost > 0);
                @endphp

                @if($hasDamagedReported || $hasLostReported)
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3.5 text-xs text-amber-800 flex items-start gap-2">
                        <i class="fa-solid fa-triangle-exclamation text-amber-600 mt-0.5" aria-hidden="true"></i>
                        <div>
                            <span class="font-semibold">Action Required:</span> Non-good materials have been recorded. Please complete <strong>Step 2 (Condition Observation & Evidence)</strong> below to document observations and upload required damage photos.
                        </div>
                    </div>
                @endif

                <form method="POST" action="{{ route('staff.events.return.update', ['booking' => $booking->id]) }}" class="mt-5 space-y-4">
                    @csrf
                    @method('PUT')
                    @foreach($returnRecord->returnItems as $returnItem)
                        @php
                            $dispatchedQuantity = (float) ($dispatchedQuantities[$returnItem->inventory_item_id] ?? 0);
                        @endphp
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div>
                                    <p class="font-semibold text-slate-800">{{ $returnItem->inventoryItem?->name ?? 'Material' }}</p>
                                    <p class="mt-1 text-xs text-slate-500">Dispatched: {{ rtrim(rtrim(number_format($dispatchedQuantity, 2), '0'), '.') }} {{ $returnItem->inventoryItem?->unit }}</p>
                                </div>
                                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                                    <div class="grid grid-cols-3 gap-2 w-full sm:w-auto">
                                        <div class="flex flex-col sm:flex-row sm:items-center gap-1">
                                            <label class="text-xs text-emerald-700 font-semibold" for="good-{{ $returnItem->id }}">Good:</label>
                                            <input id="good-{{ $returnItem->id }}" 
                                                   aria-label="Quantity good for {{ $returnItem->inventoryItem?->name ?? 'Material' }}"
                                                   type="number" 
                                                   name="items[{{ $returnItem->id }}][quantity_good]" 
                                                   value="{{ $returnItem->quantity_good ?? 0 }}" 
                                                   min="0" 
                                                   max="{{ $dispatchedQuantity }}" 
                                                   step="1" 
                                                   class="w-full sm:w-20 rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-sm text-center min-h-[40px] focus:ring-2 focus:ring-purple-500">
                                        </div>
                                        <div class="flex flex-col sm:flex-row sm:items-center gap-1">
                                            <label class="text-xs text-amber-700 font-semibold" for="damaged-{{ $returnItem->id }}">Damaged:</label>
                                            <input id="damaged-{{ $returnItem->id }}" 
                                                   aria-label="Quantity damaged for {{ $returnItem->inventoryItem?->name ?? 'Material' }}"
                                                   type="number" 
                                                   name="items[{{ $returnItem->id }}][quantity_damaged]" 
                                                   value="{{ $returnItem->quantity_damaged ?? 0 }}" 
                                                   min="0" 
                                                   max="{{ $dispatchedQuantity }}" 
                                                   step="1" 
                                                   class="w-full sm:w-20 rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-sm text-center min-h-[40px] focus:ring-2 focus:ring-purple-500">
                                        </div>
                                        <div class="flex flex-col sm:flex-row sm:items-center gap-1">
                                            <label class="text-xs text-rose-700 font-semibold" for="lost-{{ $returnItem->id }}">Lost:</label>
                                            <input id="lost-{{ $returnItem->id }}" 
                                                   aria-label="Quantity lost for {{ $returnItem->inventoryItem?->name ?? 'Material' }}"
                                                   type="number" 
                                                   name="items[{{ $returnItem->id }}][quantity_lost]" 
                                                   value="{{ $returnItem->quantity_lost ?? 0 }}" 
                                                   min="0" 
                                                   max="{{ $dispatchedQuantity }}" 
                                                   step="1" 
                                                   class="w-full sm:w-20 rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-sm text-center min-h-[40px] focus:ring-2 focus:ring-purple-500">
                                        </div>
                                    </div>
                                    <div class="w-full sm:w-auto">
                                        <label class="sr-only" for="return-notes-{{ $returnItem->id }}">Observation note for {{ $returnItem->inventoryItem?->name ?? 'Material' }}</label>
                                        <input id="return-notes-{{ $returnItem->id }}" 
                                               aria-label="Observation note for {{ $returnItem->inventoryItem?->name ?? 'Material' }}"
                                               type="text" 
                                               name="items[{{ $returnItem->id }}][notes]" 
                                               value="{{ $returnItem->notes }}" 
                                               placeholder="Observation note" 
                                               class="w-full sm:w-48 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm min-h-[40px] focus:ring-2 focus:ring-purple-500">
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase tracking-[0.2em] text-slate-500" for="return-notes">Submission notes</label>
                        <textarea id="return-notes" name="notes" rows="3" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500" placeholder="Add an operational return note">{{ $returnRecord->notes }}</textarea>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="inline-flex min-h-[44px] items-center gap-2 rounded-xl bg-sky-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-500">
                            <i class="fa-solid fa-box-open" aria-hidden="true"></i>
                            Submit Return Record
                        </button>
                    </div>
                </form>
            @else
                <div class="mt-5 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center text-sm text-slate-500">
                    @if(in_array($booking->status, ['event_completed', 'pending_return', 'pending_resolution', 'completed'], true) && empty($dispatchedQuantities))
                        <i class="fa-solid fa-circle-check text-slate-400 mr-1.5" aria-hidden="true"></i>
                        No non-perishable returnable hardware materials were dispatched for this event.
                    @else
                        Return recording becomes available when this event reaches the return workflow.
                    @endif
                </div>
            @endif
        </section>

        @if($returnRecord)
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="rounded-md bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800 uppercase tracking-wider">Step 2 of 2</span>
                        <h3 class="font-bold text-slate-900">Condition Observation & Evidence</h3>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">Record your physical condition observation. Photographic evidence is strictly required for damaged items before Admin adjudication.</p>
                </div>

                <form method="POST" action="{{ route('staff.events.return.condition', ['booking' => $booking->id]) }}" class="mt-5 space-y-4" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    @foreach($returnRecord->returnItems as $returnItem)
                        @php
                            $isDamagedReported = (float) $returnItem->quantity_damaged > 0;
                            $isLostReported = (float) $returnItem->quantity_lost > 0;
                            $isGoodReported = (float) $returnItem->quantity_good > 0;
                            
                            $defaultCondition = $returnItem->condition !== 'pending' 
                                ? $returnItem->condition 
                                : ($isDamagedReported ? 'damaged' : ($isLostReported ? 'lost' : ($isGoodReported ? 'good' : 'pending')));
                            $currentCondition = old("items.{$returnItem->id}.condition", $defaultCondition);
                            $shouldShowEvidence = in_array($currentCondition, ['damaged', 'mixed'], true) || $isDamagedReported;
                        @endphp
                        <div class="rounded-2xl border {{ $isDamagedReported ? 'border-amber-300 bg-amber-50/30' : 'border-slate-200 bg-slate-50' }} p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <p class="font-semibold text-slate-800">{{ $returnItem->inventoryItem?->name ?? 'Material' }}</p>
                                        @if($isDamagedReported)
                                            <span class="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-700 uppercase">Damage Reported ({{ (int) $returnItem->quantity_damaged }})</span>
                                        @elseif($isLostReported)
                                            <span class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-bold text-slate-700 uppercase">Lost Reported ({{ (int) $returnItem->quantity_lost }})</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Returned physically: <strong class="text-slate-700">{{ (int) $returnItem->quantity_returned }}</strong>
                                        (Good: {{ (int) $returnItem->quantity_good }}, Damaged: {{ (int) $returnItem->quantity_damaged }}, Lost: {{ (int) $returnItem->quantity_lost }})
                                    </p>
                                </div>
                                <div class="flex flex-col gap-2 w-full sm:w-auto">
                                    <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                                        <div>
                                            <label class="sr-only" for="condition-{{ $returnItem->id }}">Condition for {{ $returnItem->inventoryItem?->name ?? 'Material' }}</label>
                                            <select id="condition-{{ $returnItem->id }}" 
                                                    aria-label="Condition for {{ $returnItem->inventoryItem?->name ?? 'Material' }}"
                                                    name="items[{{ $returnItem->id }}][condition]" 
                                                    class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm sm:w-36 min-h-[40px] focus:ring-2 focus:ring-amber-500" 
                                                    onchange="toggleEvidenceContainer({{ $returnItem->id }}, this.value)">
                                                <option value="pending" disabled {{ $currentCondition === 'pending' ? 'selected' : '' }}>Select condition</option>
                                                <option value="good" {{ $currentCondition === 'good' ? 'selected' : '' }}>Good</option>
                                                <option value="damaged" {{ $currentCondition === 'damaged' ? 'selected' : '' }}>Damaged</option>
                                                <option value="lost" {{ $currentCondition === 'lost' ? 'selected' : '' }}>Lost</option>
                                                <option value="mixed" {{ $currentCondition === 'mixed' ? 'selected' : '' }}>Mixed</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="sr-only" for="condition-notes-{{ $returnItem->id }}">Condition notes for {{ $returnItem->inventoryItem?->name ?? 'Material' }}</label>
                                            <input id="condition-notes-{{ $returnItem->id }}" 
                                                   aria-label="Condition notes for {{ $returnItem->inventoryItem?->name ?? 'Material' }}"
                                                   type="text" 
                                                   name="items[{{ $returnItem->id }}][notes]" 
                                                   value="{{ $returnItem->notes }}" 
                                                   placeholder="Observation note" 
                                                   class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm sm:w-56 min-h-[40px] focus:ring-2 focus:ring-amber-500">
                                        </div>
                                    </div>

                                    <div id="evidence-container-{{ $returnItem->id }}" class="mt-2 text-sm text-slate-500" style="display: {{ $shouldShowEvidence ? 'block' : 'none' }};">
                                        <label class="block text-xs font-semibold mb-1 text-rose-700" for="evidence-{{ $returnItem->id }}">
                                            Damage Evidence Photos (Required for Damaged)
                                        </label>
                                        <input type="file" 
                                               id="evidence-{{ $returnItem->id }}" 
                                               name="items[{{ $returnItem->id }}][evidence][]" 
                                               multiple 
                                               accept="image/jpeg,image/png" 
                                               class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs focus:ring-2 focus:ring-amber-500"
                                               onchange="previewEvidencePhotos(this, {{ $returnItem->id }})">
                                        <p class="text-[11px] text-slate-400 mt-1">Accepted formats: JPG, PNG (Max 5MB per photo).</p>

                                        {{-- Client-side preview thumbnails for newly selected files --}}
                                        <div id="new-evidence-previews-{{ $returnItem->id }}" class="mt-2 flex flex-wrap gap-2 empty:hidden" aria-live="polite"></div>

                                        {{-- Previously uploaded evidence records --}}
                                        @if($returnItem->evidences->isNotEmpty())
                                            <div class="mt-3 border-t border-slate-100 pt-2.5">
                                                <p class="text-xs font-semibold text-slate-700 mb-1.5 flex items-center gap-1.5">
                                                    <i class="fa-solid fa-images text-purple-600" aria-hidden="true"></i>
                                                    Uploaded Evidence ({{ $returnItem->evidences->count() }})
                                                </p>
                                                <div class="flex flex-wrap gap-2">
                                                    @foreach($returnItem->evidences as $evidence)
                                                        <div class="group relative rounded-xl border border-slate-200 bg-white p-1 shadow-sm hover:border-purple-300 transition">
                                                            <a href="{{ route('secure.evidence.show', $evidence) }}" target="_blank" rel="noopener noreferrer" class="block overflow-hidden rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-600" aria-label="View evidence photo {{ $evidence->file_name }} in full size">
                                                                <img src="{{ route('secure.evidence.show', $evidence) }}" 
                                                                     alt="Damage evidence for {{ $returnItem->inventoryItem?->name ?? 'Material' }}: {{ $evidence->file_name }}" 
                                                                     class="h-16 w-16 object-cover rounded-lg bg-slate-100 transition group-hover:scale-105"
                                                                     loading="lazy"
                                                                     onerror="this.onerror=null; this.parentElement.innerHTML='<span class=\'flex h-16 w-16 items-center justify-center text-[10px] text-slate-400 text-center p-1 bg-slate-100 rounded-lg\'>Photo unavailable</span>';">
                                                            </a>
                                                            <a href="{{ route('secure.evidence.show', $evidence) }}" target="_blank" rel="noopener noreferrer" class="mt-1 block max-w-[64px] truncate text-[10px] text-slate-500 hover:text-purple-700 text-center" title="{{ $evidence->file_name }}">
                                                                {{ $evidence->file_name }}
                                                            </a>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <div class="flex justify-end">
                        <button type="submit" class="inline-flex min-h-[44px] items-center gap-2 rounded-xl bg-amber-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500">
                            <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
                            Save Observation & Evidence
                        </button>
                    </div>
                </form>
            </section>
        @endif

        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <i class="fa-solid fa-circle-info mr-2" aria-hidden="true"></i>
            Checklist updates record operational progress only. Booking approvals, pricing, payments, and inventory reconciliation remain Admin responsibilities.
        </div>
        
        {{-- Communication Feed --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 mb-4">Event Discussion</h3>
            
            @if(isset($bookingMessages) && $bookingMessages->count() > 0)
                <div class="space-y-4 mb-6">
                    @foreach($bookingMessages as $msg)
                        <div class="flex gap-3 {{ $msg->sender_type === 'staff' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[85%] rounded-2xl p-4 {{ $msg->sender_type === 'staff' ? 'bg-purple-600 text-white rounded-tr-sm' : 'bg-white border border-slate-200 text-slate-800 rounded-tl-sm' }}">
                                <p class="text-xs font-semibold mb-1 opacity-80">{{ $msg->sender_type === 'staff' ? 'You' : ucfirst($msg->sender_type) }} • {{ $msg->created_at->format('M j, g:i A') }}</p>
                                <p class="text-sm whitespace-pre-wrap">{{ $msg->message }}</p>
                                @if($msg->attachment_path)
                                    <div class="mt-3 pt-3 border-t {{ $msg->sender_type === 'staff' ? 'border-purple-500/50' : 'border-slate-100' }}">
                                        <a href="{{ route('secure.message.attachment', ['id' => $msg->id]) }}" target="_blank" class="inline-flex items-center gap-2 text-xs font-medium hover:underline {{ $msg->sender_type === 'staff' ? 'text-purple-50' : 'text-purple-600' }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                                            {{ $msg->attachment_name ?? 'View Attachment' }}
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('staff.events.reply', $booking->id) }}" enctype="multipart/form-data" class="bg-slate-50 p-4 rounded-xl border border-slate-200 shadow-sm">
                @csrf
                <div class="mb-3">
                    <label for="message" class="sr-only">Your Message</label>
                    <textarea id="message" name="message" rows="3" class="w-full border-0 focus:ring-0 resize-none bg-transparent placeholder-slate-400 text-sm" placeholder="Send an update or discussion reply..." required></textarea>
                </div>
                <div class="flex items-center justify-between pt-3 border-t border-slate-200">
                    <div class="flex items-center gap-4 flex-wrap">
                        <select name="visibility" class="text-xs border-slate-200 rounded-lg text-slate-600 focus:ring-purple-500 focus:border-purple-500 py-1.5 px-2" required>
                            <option value="shared">Shared (Client & Admin)</option>
                            <option value="admin_staff">Admin Only (Private)</option>
                        </select>
                        <div class="relative group">
                            <input type="file" id="attachment" name="attachment" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" accept=".jpg,.jpeg,.png,.webp,.pdf" onchange="const n=this.files[0]?.name; document.getElementById('attachment-name').textContent=n||'Attach File'; document.getElementById('attachment-category').style.display=n?'block':'none';">
                            <button type="button" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 group-hover:text-purple-600 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                                <span id="attachment-name">Attach File</span>
                            </button>
                        </div>
                        <select name="attachment_category" class="text-xs border-slate-200 rounded-lg text-slate-600 focus:ring-purple-500 focus:border-purple-500 pl-2 pr-8 py-1.5" style="display: none;" id="attachment-category">
                            <option value="general">General File</option>
                            <option value="venue">Venue Image</option>
                        </select>
                    </div>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-purple-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-800 transition-colors">Send</button>
                </div>
            </form>
        </section>
    </div>

    <script>
        function toggleEvidenceContainer(itemId, condition) {
            const container = document.getElementById('evidence-container-' + itemId);
            if (container) {
                container.style.display = (condition === 'damaged' || condition === 'mixed') ? 'block' : 'none';
            }
        }

        const activePreviewUrls = {};

        function previewEvidencePhotos(input, itemId) {
            const container = document.getElementById('new-evidence-previews-' + itemId);
            if (!container) return;

            // Revoke any previous preview URLs for this item to prevent memory leaks
            if (activePreviewUrls[itemId]) {
                activePreviewUrls[itemId].forEach(url => URL.revokeObjectURL(url));
            }
            activePreviewUrls[itemId] = [];
            container.innerHTML = '';

            if (!input.files || input.files.length === 0) return;

            Array.from(input.files).forEach((file, index) => {
                if (!file.type.startsWith('image/')) return;

                const objectUrl = URL.createObjectURL(file);
                activePreviewUrls[itemId].push(objectUrl);

                const card = document.createElement('div');
                card.className = 'relative flex flex-col items-center rounded-xl border border-purple-200 bg-white p-1.5 shadow-sm text-center';

                card.innerHTML = `
                    <div class="relative h-16 w-16 overflow-hidden rounded-lg bg-slate-100">
                        <img src="${objectUrl}" alt="Preview: ${file.name}" class="h-full w-full object-cover">
                        <button type="button" 
                                onclick="removeSelectedEvidenceFile(${itemId}, ${index})" 
                                class="absolute top-0.5 right-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-rose-600 text-white shadow hover:bg-rose-700 focus:outline-none focus:ring-1 focus:ring-rose-400" 
                                aria-label="Remove ${file.name}">
                            <i class="fa-solid fa-xmark text-[10px]" aria-hidden="true"></i>
                        </button>
                    </div>
                    <span class="mt-1 block max-w-[64px] truncate text-[10px] text-slate-600 font-medium" title="${file.name}">
                        ${file.name}
                    </span>
                `;
                container.appendChild(card);
            });
        }

        function removeSelectedEvidenceFile(itemId, removeIndex) {
            const input = document.getElementById('evidence-' + itemId);
            if (!input || !input.files) return;

            const dt = new DataTransfer();
            Array.from(input.files).forEach((file, idx) => {
                if (idx !== removeIndex) {
                    dt.items.add(file);
                }
            });
            input.files = dt.files;
            previewEvidencePhotos(input, itemId);
        }
    </script>
</x-staff-layout>
