<x-staff-layout title="Material Dispatch">
    <div class="space-y-6">
        {{-- Admin-Style Search & Filter Toolbar --}}
        @php
            $hasActiveFilters = !empty($currentSearch);
        @endphp
        <form method="GET" action="{{ route('staff.dispatch') }}" id="dispatchFilterForm" class="bg-white rounded-xl border border-slate-200 p-3 sm:p-4 shadow-2xs">
            <div class="flex flex-wrap items-center gap-2.5 sm:gap-3">
                {{-- Search Box --}}
                <div class="relative w-full md:min-w-[240px] md:flex-1 lg:max-w-md">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                        <i class="fa-solid fa-magnifying-glass text-xs"></i>
                    </div>
                    <input
                        type="text"
                        name="search"
                        value="{{ $currentSearch ?? '' }}"
                        placeholder="Search booking, client, or venue..."
                        autocomplete="off"
                        class="block w-full pl-9 pr-3 py-2 border border-slate-200 rounded-xl text-sm bg-slate-50 placeholder-slate-400 focus:outline-none focus:bg-white focus:ring-1 focus:ring-rose-500 focus:border-rose-600 transition"
                    >
                </div>

                {{-- Search Submit Button --}}
                <button
                    type="submit"
                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-navy-950 hover:bg-navy-900 text-white text-sm font-semibold rounded-xl transition shadow-2xs focus:outline-none focus:ring-2 focus:ring-rose-500 cursor-pointer"
                >
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                    <span>Search</span>
                </button>

                @if($hasActiveFilters)
                    <div class="w-full sm:w-auto flex gap-2">
                        <a href="{{ route('staff.dispatch') }}"
                            class="inline-flex w-full items-center justify-center px-3.5 py-2 border border-slate-200 text-xs font-semibold rounded-xl text-slate-600 bg-white hover:bg-slate-50 transition whitespace-nowrap">
                            Clear
                        </a>
                    </div>
                @endif

                <div class="ml-auto flex items-center gap-2 pt-1 sm:pt-0">
                    <span class="rounded-full bg-sky-50 px-3 py-1 text-xs font-bold text-sky-700 tabular-nums">
                        {{ $entries->count() }} Events with Reserved Materials
                    </span>
                </div>
            </div>
        </form>

        @if($entries->isEmpty())
            <div class="rounded-2xl border border-slate-200/80 bg-white px-6 py-14 text-center shadow-2xs">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 text-2xl text-slate-400">
                    <i class="fa-solid fa-truck-fast"></i>
                </span>
                <h4 class="mt-4 text-base font-bold text-navy-900">No materials awaiting dispatch</h4>
                <p class="mt-1 text-xs text-slate-500 max-w-md mx-auto">There are currently no assigned events with reserved materials ready for dispatch.</p>
            </div>
        @else
            <div class="space-y-6">
                @foreach($entries as $entry)
                    @php
                        $b = $entry['booking'];
                        $rows = $entry['rows'];
                        $cName = $b->client?->full_name ?? $b->guest_name ?? 'Client';
                        $evtDate = optional($b->event_date)->format('M d, Y') ?? 'Date not set';
                        $outstandingCount = $rows->where('outstanding', '>', 0)->count();
                    @endphp
                    <div class="rounded-2xl border border-slate-200/80 bg-white shadow-2xs overflow-hidden">
                        {{-- Event Header --}}
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-100 bg-slate-50/50 p-4 sm:px-6">
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-rose-600">Booking #{{ $b->id }}</span>
                                <h4 class="font-serif text-base font-bold text-navy-900">{{ $cName }} · {{ ucfirst((string) $b->event_type) }}</h4>
                                <p class="text-xs text-slate-500">
                                    <i class="fa-regular fa-calendar text-slate-400 mr-1"></i>{{ $evtDate }} · {{ $b->venue ?? 'No venue specified' }}
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                @if($outstandingCount === 0)
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-700">Fully Dispatched</span>
                                @else
                                    <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-700 tabular-nums">{{ $outstandingCount }} items outstanding</span>
                                @endif
                                @if($entry['can_dispatch'])
                                    <a href="{{ route('staff.events.show', $b) }}#dispatch" class="rounded-xl bg-navy-900 px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-navy-800 transition">
                                        Manage Dispatch
                                    </a>
                                @else
                                    <a href="{{ route('staff.events.show', $b) }}#dispatch" class="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-1.5 text-xs font-semibold text-amber-800">
                                        Dispatch locked
                                    </a>
                                @endif
                            </div>
                        </div>

                        {{-- Items Table --}}
                        <div class="overflow-x-auto">
                            <table class="rf-table--stack w-full text-left text-xs">
                                <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500 border-b border-slate-100">
                                    <tr>
                                        <th class="py-3 px-6">Material Item</th>
                                        <th class="py-3 px-4 text-right">Reserved</th>
                                        <th class="py-3 px-4 text-right">Dispatched</th>
                                        <th class="py-3 px-4 text-right">Outstanding</th>
                                        <th class="py-3 px-6 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                                    @foreach($rows as $r)
                                        @php
                                            $item = $r['item'];
                                            $isDone = ($r['outstanding'] <= 0);
                                        @endphp
                                        <tr class="hover:bg-slate-50/50 transition">
                                            <td class="py-3 px-6">
                                                <p class="font-bold text-navy-900">{{ $item->name }}</p>
                                                <p class="text-[11px] text-slate-400">{{ ucfirst($item->category ?? 'decor') }} · {{ $item->unit }}</p>
                                            </td>
                                            <td data-label="Reserved" class="py-3 px-4 text-right tabular-nums">{{ rtrim(rtrim(number_format($r['reserved'], 2), '0'), '.') }}</td>
                                            <td data-label="Dispatched" class="py-3 px-4 text-right tabular-nums text-emerald-600 font-semibold">{{ rtrim(rtrim(number_format($r['dispatched'], 2), '0'), '.') }}</td>
                                            <td data-label="Outstanding" class="py-3 px-4 text-right tabular-nums {{ $isDone ? 'text-slate-400' : 'text-rose-600 font-bold' }}">{{ rtrim(rtrim(number_format($r['outstanding'], 2), '0'), '.') }}</td>
                                            <td data-label="Status" class="py-3 px-6 text-center">
                                                @if($isDone)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[10px] font-bold text-emerald-700">
                                                        <i class="fa-solid fa-check"></i> Dispatched
                                                    </span>
                                                @elseif($entry['can_dispatch'])
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-[10px] font-bold text-amber-700">
                                                        <i class="fa-solid fa-clock"></i> Pending
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-bold text-slate-600">
                                                        <i class="fa-solid fa-lock"></i> Locked
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-staff-layout>
