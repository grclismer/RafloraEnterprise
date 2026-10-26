<x-admin-layout
    title="Inventory Management"
    description="Track and manage floral materials, props, and event inventory."
>

    <!-- 4 KPI Cards -->
    <div class="rf-stat-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Card 1: TOTAL ITEMS -->
        <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-xs flex items-center justify-between hover:border-slate-200 transition">
            <div>
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">TOTAL ITEMS</p>
                <p class="text-2xl sm:text-3xl font-extrabold text-slate-900">{{ number_format($totalItemsCount) }}</p>
                <p class="text-xs text-slate-500 mt-1">All active inventory items</p>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-brand-50 text-brand-700 border border-brand-100 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-boxes-stacked text-lg"></i>
            </div>
        </div>

        <!-- Card 2: IN STOCK -->
        <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-xs flex items-center justify-between hover:border-slate-200 transition">
            <div class="flex-1 pr-2">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">IN STOCK</p>
                    @if($totalItemsCount > 0)
                        <span class="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-100">
                            {{ number_format(($inStockCount / $totalItemsCount) * 100, 1) }}%
                        </span>
                    @endif
                </div>
                <p class="text-2xl sm:text-3xl font-extrabold text-slate-900">{{ number_format($inStockCount) }}</p>
                <p class="text-xs text-slate-500 mt-1">Items meeting stock threshold</p>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-check-circle text-lg"></i>
            </div>
        </div>

        <!-- Card 3: LOW STOCK -->
        <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-xs flex items-center justify-between hover:border-slate-200 transition">
            <div class="flex-1 pr-2">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">LOW STOCK</p>
                    @if($totalItemsCount > 0)
                        <span class="text-[10px] font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-100">
                            {{ number_format(($lowStockCount / $totalItemsCount) * 100, 1) }}%
                        </span>
                    @endif
                </div>
                <p class="text-2xl sm:text-3xl font-extrabold text-slate-900">{{ number_format($lowStockCount) }}</p>
                <p class="text-xs text-slate-500 mt-1">At or below minimum threshold</p>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 border border-amber-100 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-triangle-exclamation text-lg"></i>
            </div>
        </div>

        <!-- Card 4: SHORTAGE -->
        <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-xs flex items-center justify-between hover:border-slate-200 transition">
            <div class="flex-1 pr-2">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">SHORTAGE</p>
                    @if($totalItemsCount > 0)
                        <span class="text-[10px] font-bold text-rose-700 bg-rose-50 px-2 py-0.5 rounded-full border border-rose-100">
                            {{ number_format(($shortageCount / $totalItemsCount) * 100, 1) }}%
                        </span>
                    @endif
                </div>
                <p class="text-2xl sm:text-3xl font-extrabold text-slate-900">{{ number_format($shortageCount) }}</p>
                <p class="text-xs text-slate-500 mt-1">Negative available stock</p>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 border border-rose-100 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-circle-exclamation text-lg"></i>
            </div>
        </div>
    </div>

    <!-- Main Container -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-xs overflow-hidden mb-8">
        <!-- Compact Toolbar Form -->
        <form method="GET" action="{{ route('admin.inventory.index') }}" id="inventoryFilterForm" class="p-4 sm:p-5 border-b border-slate-100">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                <!-- Left: Search Box, Show Filters Toggle, Search Action -->
                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2.5">
                    <!-- Search Input -->
                    <div class="relative min-w-0 max-w-md flex-1 basis-full sm:basis-auto sm:min-w-[240px]">
                        <span class="absolute inset-y-0 left-3.5 flex items-center pointer-events-none text-slate-400">
                            <i class="fa-solid fa-search text-xs"></i>
                        </span>
                        <input
                            type="text"
                            name="search"
                            id="inventorySearchInput"
                            value="{{ request('search') }}"
                            placeholder="Search inventory items..."
                            class="w-full pl-9 pr-3.5 py-2 bg-slate-50/70 border border-slate-200 rounded-xl text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition shadow-2xs"
                        >
                    </div>

                    <!-- Show Filters Button -->
                    @php
                        $hasActiveFilters = (
                            (request('category', 'all') !== 'all') ||
                            (request('status', 'all') !== 'all') ||
                            (request('stock_level', 'all') !== 'all') ||
                            (request('perishable', 'all') !== 'all') ||
                            (request('sort', 'default') !== 'default')
                        );
                    @endphp
                    <button
                        type="button"
                        id="filterToggleBtn"
                        onclick="toggleFilterPanel()"
                        aria-expanded="{{ $hasActiveFilters ? 'true' : 'false' }}"
                        aria-controls="filterPanel"
                        class="inline-flex items-center gap-2 px-3.5 py-2 border rounded-xl text-xs font-semibold transition shadow-2xs cursor-pointer {{ $hasActiveFilters ? 'bg-emerald-50 text-emerald-800 border-emerald-300' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50' }}"
                    >
                        <i class="fa-solid fa-filter text-xs {{ $hasActiveFilters ? 'text-emerald-600' : 'text-slate-400' }}"></i>
                        <span id="filterToggleText">{{ $hasActiveFilters ? 'Hide Filters' : 'Show Filters' }}</span>
                        @if($hasActiveFilters)
                            <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span>
                        @endif
                        <i id="filterToggleChevron" class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition-transform duration-200 {{ $hasActiveFilters ? 'rotate-180' : '' }}"></i>
                    </button>

                    <!-- Search Button -->
                    <button
                        type="submit"
                        class="px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-xs rounded-xl transition shadow-2xs focus:outline-none focus:ring-2 focus:ring-slate-900 cursor-pointer"
                    >
                        Search
                    </button>

                    @if(request('search') || $hasActiveFilters)
                        <a
                            href="{{ route('admin.inventory.index') }}"
                            class="px-3 py-2 text-xs font-semibold text-slate-500 hover:text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition"
                        >
                            Clear
                        </a>
                    @endif
                </div>

                <!-- Right: Action Controls (Inventory Tools ▼ & + Add Item) -->
                <div class="flex w-full min-w-0 flex-wrap items-center gap-2.5 self-start lg:w-auto lg:shrink-0 lg:self-center">
                    <!-- Inventory CSV Tools Dropdown Container -->
                    <div class="relative" id="csvToolsContainer">
                        <button
                            type="button"
                            id="csvToolsButton"
                            onclick="toggleCsvDropdown()"
                            aria-haspopup="true"
                            aria-expanded="false"
                            aria-controls="csvToolsMenu"
                            class="inline-flex items-center gap-2 px-3.5 py-2 border border-slate-200 hover:border-emerald-600 bg-white hover:bg-emerald-50/40 text-slate-700 hover:text-emerald-700 font-semibold text-xs rounded-xl transition shadow-2xs focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                            title="CSV Tools"
                        >
                            <i class="fa-regular fa-file-lines text-emerald-600 text-sm"></i>
                            <span>Inventory Tools</span>
                            <span class="sr-only">CSV Tools</span>
                            <i id="csvToolsChevron" class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition-transform duration-200"></i>
                        </button>

                        <!-- Popover Dropdown Menu -->
                        <div
                            id="csvToolsMenu"
                            style="display:none; width: 520px; max-width: calc(100vw - 2rem);"
                            role="region"
                            aria-labelledby="csvToolsButton"
                            class="absolute left-0 right-auto top-full z-50 mt-2 transform rounded-2xl border border-slate-100 bg-white p-4 text-left shadow-xl transition-all sm:left-auto sm:right-0 sm:p-5"
                        >
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <!-- Column 1: EXPORT (DOWNLOAD) -->
                                <div>
                                    <p class="text-[10px] font-bold tracking-wider text-slate-400 uppercase mb-2.5">EXPORT (DOWNLOAD)</p>
                                    <div class="space-y-2">
                                        <a href="{{ route('admin.inventory.export') }}" onclick="closeCsvDropdown()" class="group flex items-start gap-3 p-2.5 rounded-xl hover:bg-emerald-50/50 border border-transparent hover:border-emerald-100 transition">
                                            <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 group-hover:bg-emerald-100 transition">
                                                <i class="fa-solid fa-file-arrow-down text-sm"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-xs font-bold text-slate-900 group-hover:text-emerald-700 transition">Download Inventory CSV</p>
                                                <p class="text-[11px] text-slate-500 mt-0.5">Export all inventory items</p>
                                            </div>
                                        </a>
                                        <a href="{{ route('admin.inventory.template') }}" onclick="closeCsvDropdown()" class="group flex items-start gap-3 p-2.5 rounded-xl hover:bg-slate-50 border border-transparent hover:border-slate-200 transition">
                                            <div class="w-9 h-9 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center shrink-0 group-hover:bg-slate-200 transition">
                                                <i class="fa-regular fa-file-lines text-sm"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-xs font-bold text-slate-900 group-hover:text-emerald-700 transition">Download Template</p>
                                                <p class="text-[11px] text-slate-500 mt-0.5">Get a blank CSV template</p>
                                            </div>
                                        </a>
                                    </div>
                                </div>

                                <!-- Column 2: IMPORT (UPLOAD) -->
                                <div>
                                    <p class="text-[10px] font-bold tracking-wider text-slate-400 uppercase mb-2.5">IMPORT (UPLOAD)</p>
                                    <div class="space-y-2">
                                        <button type="button" onclick="closeCsvDropdown(); openUploadModal();" class="w-full text-left group flex items-start gap-3 p-2.5 rounded-xl bg-emerald-50/70 border border-emerald-100 hover:bg-emerald-100/70 transition cursor-pointer">
                                            <div class="w-9 h-9 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">
                                                <i class="fa-solid fa-file-arrow-up text-sm"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-xs font-bold text-emerald-950">Import Inventory CSV</p>
                                                <p class="text-[11px] text-emerald-700 mt-0.5">Upload and import items</p>
                                            </div>
                                        </button>
                                        <button type="button" onclick="closeCsvDropdown(); openInstructionsModal();" class="w-full text-left group flex items-start gap-3 p-2.5 rounded-xl hover:bg-slate-50 border border-transparent hover:border-slate-200 transition cursor-pointer">
                                            <div class="w-9 h-9 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center shrink-0 group-hover:bg-slate-200 transition">
                                                <i class="fa-solid fa-circle-info text-sm"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-xs font-bold text-slate-900 group-hover:text-emerald-700 transition">Import Instructions</p>
                                                <p class="text-[11px] text-slate-500 mt-0.5">View CSV guidelines</p>
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Archived Items Button -->
                    <a href="{{ route('admin.inventory.archived') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-slate-600 hover:text-slate-800 bg-white hover:bg-slate-50 border border-slate-200 rounded-xl transition shadow-2xs whitespace-nowrap">
                        <i class="fa-solid fa-box-archive text-slate-400"></i>
                        <span>Archived Items</span>
                    </a>

                    <!-- + Add Item Button -->
                    <a href="{{ route('admin.inventory.create') }}" onclick="event.preventDefault(); openCreateModal();" class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-700 hover:bg-brand-800 text-white font-semibold text-xs rounded-xl shadow-xs hover:shadow transition focus:outline-none focus:ring-2 focus:ring-brand-500 whitespace-nowrap cursor-pointer">
                        <i class="fa-solid fa-plus text-xs"></i>
                        <span>Add Item</span>
                    </a>
                </div>
            </div>

            <!-- Collapsible Filter Panel -->
            <div
                id="filterPanel"
                style="display: {{ $hasActiveFilters ? 'block' : 'none' }};"
                class="mt-4 pt-4 border-t border-slate-100"
            >
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                    <!-- Category Filter -->
                    <div>
                        <label for="filterCategory" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Category</label>
                        <select
                            id="filterCategory"
                            name="category"
                            onchange="this.form.submit()"
                            class="w-full py-1.5 px-3 bg-white border border-slate-200 rounded-xl text-xs font-medium text-slate-700 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition cursor-pointer"
                        >
                            <option value="all" {{ $currentCategory === 'all' ? 'selected' : '' }}>All Categories</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat }}" {{ $currentCategory === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div>
                        <label for="filterStatus" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Status</label>
                        <select
                            id="filterStatus"
                            name="status"
                            onchange="this.form.submit()"
                            class="w-full py-1.5 px-3 bg-white border border-slate-200 rounded-xl text-xs font-medium text-slate-700 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition cursor-pointer"
                        >
                            <option value="all" {{ $currentStatus === 'all' ? 'selected' : '' }}>All Statuses</option>
                            <option value="in_stock" {{ $currentStatus === 'in_stock' ? 'selected' : '' }}>In Stock</option>
                            <option value="low" {{ $currentStatus === 'low' ? 'selected' : '' }}>Low Stock</option>
                            <option value="shortage" {{ $currentStatus === 'shortage' ? 'selected' : '' }}>Shortage</option>
                        </select>
                    </div>

                    <!-- Perishable Filter -->
                    <div>
                        <label for="filterPerishable" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Perishable</label>
                        <select
                            id="filterPerishable"
                            name="perishable"
                            onchange="this.form.submit()"
                            class="w-full py-1.5 px-3 bg-white border border-slate-200 rounded-xl text-xs font-medium text-slate-700 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition cursor-pointer"
                        >
                            <option value="all" {{ $currentPerishable === 'all' ? 'selected' : '' }}>All Types</option>
                            <option value="yes" {{ $currentPerishable === 'yes' ? 'selected' : '' }}>Perishable</option>
                            <option value="no" {{ $currentPerishable === 'no' ? 'selected' : '' }}>Non-perishable</option>
                        </select>
                    </div>

                    <!-- Stock Level Filter -->
                    <div>
                        <label for="filterStockLevel" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Stock Level</label>
                        <select
                            id="filterStockLevel"
                            name="stock_level"
                            onchange="this.form.submit()"
                            class="w-full py-1.5 px-3 bg-white border border-slate-200 rounded-xl text-xs font-medium text-slate-700 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition cursor-pointer"
                        >
                            <option value="all" {{ ($currentStockLevel ?? 'all') === 'all' ? 'selected' : '' }}>All Stock Levels</option>
                            <option value="in_stock" {{ ($currentStockLevel ?? '') === 'in_stock' ? 'selected' : '' }}>Above Minimum</option>
                            <option value="low" {{ ($currentStockLevel ?? '') === 'low' ? 'selected' : '' }}>Low Stock</option>
                            <option value="shortage" {{ ($currentStockLevel ?? '') === 'shortage' ? 'selected' : '' }}>Shortage</option>
                        </select>
                    </div>

                    <!-- Sort -->
                    <div>
                        <label for="filterSort" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Sort</label>
                        <select
                            id="filterSort"
                            name="sort"
                            onchange="this.form.submit()"
                            class="w-full py-1.5 px-3 bg-white border border-slate-200 rounded-xl text-xs font-medium text-slate-700 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition cursor-pointer"
                        >
                            <option value="default" {{ ($currentSort ?? 'default') === 'default' ? 'selected' : '' }}>Default (Shortage Priority)</option>
                            <option value="name_asc" {{ ($currentSort ?? '') === 'name_asc' ? 'selected' : '' }}>Name (A - Z)</option>
                            <option value="name_desc" {{ ($currentSort ?? '') === 'name_desc' ? 'selected' : '' }}>Name (Z - A)</option>
                            <option value="stock_desc" {{ ($currentSort ?? '') === 'stock_desc' ? 'selected' : '' }}>Current Stock (High - Low)</option>
                            <option value="stock_asc" {{ ($currentSort ?? '') === 'stock_asc' ? 'selected' : '' }}>Current Stock (Low - High)</option>
                            <option value="category_asc" {{ ($currentSort ?? '') === 'category_asc' ? 'selected' : '' }}>Category (A - Z)</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3 flex items-center justify-between pt-3 border-t border-slate-100">
                    <span class="text-xs text-slate-400">Refine the table by selecting one or more parameters.</span>
                    <div class="flex items-center gap-2">
                        @if($hasActiveFilters || request('search'))
                            <a href="{{ route('admin.inventory.index') }}" class="px-3 py-1.5 text-xs font-semibold text-rose-600 hover:text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 rounded-lg transition">
                                Clear Filters
                            </a>
                        @endif
                        <button type="submit" class="px-3.5 py-1.5 bg-brand-700 hover:bg-brand-800 text-white font-semibold text-xs rounded-lg transition shadow-2xs">
                            Apply Filters
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <!-- Main Scannable Table Container -->
        <div class="overflow-x-auto">
            <table class="rf-table--stack w-full table-auto text-left min-w-[850px]">
                <thead class="bg-slate-50/80 border-b border-slate-100">
                    <tr>
                        <th class="px-5 py-3.5 text-[11px] font-bold text-slate-500 uppercase tracking-wider">ITEM</th>
                        <th class="px-5 py-3.5 text-[11px] font-bold text-slate-500 uppercase tracking-wider">CATEGORY</th>
                        <th class="px-5 py-3.5 text-[11px] font-bold text-slate-500 uppercase tracking-wider text-right" title="Physical stock currently recorded in inventory">ON HAND</th>
                        <th class="px-5 py-3.5 text-[11px] font-bold text-slate-500 uppercase tracking-wider text-right" title="Quantity needed by upcoming confirmed booking lines (not yet held or dispatched)">BOOKED DEMAND</th>
                        <th class="px-5 py-3.5 text-[11px] font-bold text-slate-500 uppercase tracking-wider text-right" title="Additional quantity required to fulfill booked event demand">TO PROCURE</th>
                        <th class="px-5 py-3.5 text-[11px] font-bold text-slate-500 uppercase tracking-wider text-right" title="Configured minimum stock threshold">MINIMUM</th>
                        <th class="px-5 py-3.5 text-[11px] font-bold text-slate-500 uppercase tracking-wider text-center" title="Stock left after booked demand compared with the minimum (the Dashboard uses on-hand stock)">STATUS</th>
                        <th class="px-5 py-3.5 text-[11px] font-bold text-slate-500 uppercase tracking-wider text-right">ACTION</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($inventoryItems as $item)
                        <tr class="hover:bg-slate-50/70 transition">
                            <!-- ITEM Column -->
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 flex-shrink-0 bg-slate-100 rounded-xl overflow-hidden flex items-center justify-center border border-slate-200">
                                        @if($item->image_path)
                                            <img src="{{ Storage::url($item->image_path) }}" alt="{{ $item->name }}" class="h-full w-full object-cover">
                                        @else
                                            <div class="w-full h-full bg-emerald-50 text-emerald-700 flex items-center justify-center font-bold text-xs select-none">
                                                {{ strtoupper(substr($item->name, 0, 2)) }}
                                            </div>
                                        @endif
                                    </div>
                                    <div class="min-w-0">
                                        <p class="break-words text-sm font-bold text-slate-900 [overflow-wrap:anywhere]" title="{{ $item->name }}">{{ $item->name }}</p>
                                        <p class="text-xs font-mono text-slate-500">{{ $item->item_code ?? ('INV-' . str_pad($item->id, 4, '0', STR_PAD_LEFT)) }}</p>
                                        <p class="text-xs text-slate-400 mt-0.5 flex items-center gap-1.5">
                                            <span>{{ $item->unit }}</span>
                                            <span>&middot;</span>
                                            <span class="{{ $item->is_perishable ? 'text-rose-500 font-medium' : 'text-slate-500' }}">
                                                {{ $item->is_perishable ? 'Perishable' : 'Non-perishable' }}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <!-- CATEGORY Column -->
                            <td data-label="Category" class="px-5 py-3.5">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-medium bg-slate-100 text-slate-700 border border-slate-200/60">
                                    {{ $item->category }}
                                </span>
                            </td>

                            <!-- CURRENT Column -->
                            <td data-label="On Hand" class="px-5 py-3.5 text-right font-semibold text-slate-700 text-sm">
                                {{ (float) $item->current_stock }}
                            </td>

                            <!-- BOOKED DEMAND Column -->
                            <td data-label="Booked Demand" class="px-5 py-3.5 text-right text-sm">
                                @if($item->reserved_stock > 0)
                                    <span class="text-amber-600 font-bold">{{ (float) $item->reserved_stock }}</span>
                                @else
                                    <span class="text-slate-400">0</span>
                                @endif
                            </td>

                            <!-- TO PROCURE Column -->
                            <td data-label="To Procure" class="px-5 py-3.5 text-right text-sm font-bold {{ $item->to_procure > 0 ? 'text-rose-600' : 'text-slate-900' }}">
                                {{ (float) $item->to_procure }}
                            </td>

                            <!-- MINIMUM Column -->
                            <td data-label="Minimum" class="px-5 py-3.5 text-right text-sm text-slate-500 font-medium">
                                {{ (float) $item->min_stock }}
                            </td>

                            <!-- STATUS Column -->
                            <td data-label="Status" class="px-5 py-3.5 text-center">
                                @if($item->warning_level === 'shortage')
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                        Shortage
                                    </span>
                                @elseif($item->warning_level === 'low')
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        Low Stock
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                        In Stock
                                    </span>
                                @endif
                            </td>

                            <!-- ACTION Column -->
                            <td class="inventory-item-action px-5 py-3.5 text-right">
                                <!-- View Button: Opens slide-over drawer -->
                                <button
                                    type="button"
                                    onclick="openItemDrawer({{ $item->id }})"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 hover:text-emerald-800 border border-emerald-200 text-xs font-semibold rounded-lg transition cursor-pointer"
                                    title="View details for {{ $item->name }}"
                                >
                                    <i class="fa-regular fa-eye text-xs"></i>
                                    <span>View</span>
                                </button>
                            </td>

                                <!-- Slide-over Drawer Template for Item -->
                                <template id="item-drawer-template-{{ $item->id }}">
                                    <div class="h-full flex flex-col justify-between">
                                        <!-- DRAWER HEADER -->
                                        <div class="sticky top-0 z-10 border-b border-slate-100 bg-white p-4 sm:p-6">
                                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                <div class="flex min-w-0 items-center gap-3.5">
                                                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 border border-emerald-100 flex items-center justify-center font-bold text-emerald-700 text-base shrink-0 overflow-hidden shadow-2xs">
                                                        @if($item->image_path)
                                                            <img src="{{ Storage::url($item->image_path) }}" alt="{{ $item->name }}" class="h-full w-full object-cover">
                                                        @else
                                                            {{ strtoupper(substr($item->name, 0, 2)) }}
                                                        @endif
                                                    </div>
                                                    <div class="min-w-0">
                                                        <h2 class="break-words text-lg font-bold text-slate-900" title="{{ $item->name }}">
                                                            {{ $item->name }}
                                                        </h2>
                                                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                                                            <span class="break-all font-mono text-xs text-slate-500">{{ $item->item_code ?? ('INV-' . str_pad($item->id, 4, '0', STR_PAD_LEFT)) }}</span>
                                                            <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.2 text-[10px] font-bold {{ $item->status === 'inactive' ? 'border-slate-200 bg-slate-100 text-slate-600' : 'border-emerald-200 bg-emerald-50 text-emerald-700' }}">
                                                                {{ ucfirst($item->status ?? 'active') }}
                                                            </span>
                                                            <span class="inline-flex items-center px-2 py-0.2 rounded-full text-[10px] font-medium {{ $item->is_perishable ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-slate-100 text-slate-600 border border-slate-200' }}">
                                                                {{ $item->is_perishable ? 'Perishable' : 'Non-perishable' }}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="flex shrink-0 items-center gap-2 self-end sm:self-auto">
                                                    <button type="button" onclick="closeItemDrawer()" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-xl transition" aria-label="Close drawer">
                                                        <i class="fa-solid fa-xmark text-lg"></i>
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Drawer Navigation Tabs -->
                                            <div class="flex items-center gap-2 mt-5 border-b border-slate-100 pb-px">
                                                <button
                                                    type="button"
                                                    onclick="switchDrawerTab('overview-{{ $item->id }}')"
                                                    id="tab-btn-overview-{{ $item->id }}"
                                                    class="drawer-tab-btn active px-3.5 py-2 text-xs font-bold border-b-2 border-emerald-600 text-emerald-700 transition"
                                                >
                                                    Overview
                                                </button>
                                                <button
                                                    type="button"
                                                    onclick="switchDrawerTab('history-{{ $item->id }}')"
                                                    id="tab-btn-history-{{ $item->id }}"
                                                    class="drawer-tab-btn px-3.5 py-2 text-xs font-semibold border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition"
                                                >
                                                    Stock History
                                                    <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] bg-slate-100 text-slate-600">{{ $item->inventoryTransactions->count() }}</span>
                                                </button>
                                                <button
                                                    type="button"
                                                    onclick="switchDrawerTab('packages-{{ $item->id }}')"
                                                    id="tab-btn-packages-{{ $item->id }}"
                                                    class="drawer-tab-btn px-3.5 py-2 text-xs font-semibold border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition"
                                                >
                                                    Package Usage
                                                    <span class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] bg-slate-100 text-slate-600">{{ $item->packages->count() }}</span>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- DRAWER BODY CONTENT -->
                                        <div class="flex-1 overflow-y-auto p-6 space-y-6">
                                            <!-- TAB 1: OVERVIEW -->
                                            <div id="drawer-tab-overview-{{ $item->id }}" class="drawer-tab-pane space-y-6">
                                                <!-- Stock Summary Grid -->
                                                <div>
                                                    <div class="flex items-center justify-between mb-3">
                                                        <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider">STOCK SUMMARY</h3>
                                                        <div>
                                                            @if($item->warning_level === 'shortage')
                                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                                                    Shortage
                                                                </span>
                                                            @elseif($item->warning_level === 'low')
                                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                                                    Low Stock
                                                                </span>
                                                            @else
                                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                                    In Stock
                                                                </span>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                                        <div class="bg-slate-50 border border-slate-100 rounded-xl p-3.5 text-center">
                                                            <p class="text-[10px] font-bold text-slate-400 uppercase">ON HAND</p>
                                                            <p class="text-xl font-extrabold text-slate-800 mt-1">{{ (float) $item->current_stock }}</p>
                                                            <p class="text-[10px] text-slate-500 mt-0.5">Physical stock</p>
                                                        </div>
                                                        <div class="bg-slate-50 border border-slate-100 rounded-xl p-3.5 text-center">
                                                            <p class="text-[10px] font-bold text-slate-400 uppercase">BOOKED DEMAND</p>
                                                            <p class="text-xl font-extrabold text-amber-600 mt-1">{{ (float) $item->reserved_stock }}</p>
                                                            <p class="text-[10px] text-slate-500 mt-0.5">Upcoming bookings</p>
                                                        </div>
                                                        <div class="bg-slate-50 border border-slate-100 rounded-xl p-3.5 text-center">
                                                            <p class="text-[10px] font-bold text-slate-400 uppercase">TO PROCURE</p>
                                                            <p class="text-xl font-extrabold {{ $item->to_procure > 0 ? 'text-rose-600' : 'text-emerald-700' }} mt-1">{{ (float) $item->to_procure }}</p>
                                                            <p class="text-[10px] text-slate-500 mt-0.5">Needed for events</p>
                                                        </div>
                                                        <div class="bg-slate-50 border border-slate-100 rounded-xl p-3.5 text-center">
                                                            <p class="text-[10px] font-bold text-slate-400 uppercase">MINIMUM</p>
                                                            <p class="text-xl font-extrabold text-slate-700 mt-1">{{ (float) $item->min_stock }}</p>
                                                            <p class="text-[10px] text-slate-500 mt-0.5">Alert limit</p>
                                                        </div>
                                                    </div>

                                                    <!-- Procurement Demand Coverage Banner -->
                                                    @if($item->to_procure > 0)
                                                        <div class="mt-3 p-3 bg-rose-50/80 rounded-xl border border-rose-200/80 text-xs text-rose-700 font-semibold flex items-center gap-2">
                                                            <i class="fa-solid fa-triangle-exclamation text-rose-500 shrink-0"></i>
                                                            <span>{{ (float) $item->to_procure }} {{ $item->unit }} still needed for booked event demand.</span>
                                                        </div>
                                                    @else
                                                        <div class="mt-3 p-3 bg-emerald-50/80 rounded-xl border border-emerald-200/80 text-xs text-emerald-800 font-semibold flex items-center gap-2">
                                                            <i class="fa-solid fa-circle-check text-emerald-600 shrink-0"></i>
                                                            <span>Booked event demand is covered.</span>
                                                        </div>
                                                    @endif
                                                </div>

                                                <!-- Stock Explanation Guide -->
                                                <div class="bg-slate-50/80 rounded-xl border border-slate-200/80 p-4 space-y-2 text-xs">
                                                    <p class="font-bold text-slate-700 flex items-center gap-1.5 uppercase tracking-wider text-[10px]">
                                                        <i class="fa-solid fa-circle-info text-emerald-600"></i> STOCK DEFINITIONS
                                                    </p>
                                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-slate-600 pt-1">
                                                        <div>
                                                            <span class="font-semibold text-slate-800">On Hand:</span> Physical quantity currently in inventory.
                                                        </div>
                                                        <div>
                                                            <span class="font-semibold text-slate-800">Booked Demand:</span> Quantity needed by upcoming confirmed booking lines (not yet held or dispatched).
                                                        </div>
                                                        <div>
                                                            <span class="font-semibold text-slate-800">To Procure:</span> Additional quantity required to fulfill booked event demand.
                                                        </div>
                                                        <div>
                                                            <span class="font-semibold text-slate-800">Minimum:</span> Configured minimum inventory threshold.
                                                        </div>
                                                    </div>
                                                    @if($item->net_available < 0)
                                                        <div class="mt-2 pt-2 border-t border-slate-200 text-slate-600 text-[11px] flex items-center justify-between">
                                                            <span>Net Available After Booked Demand:</span>
                                                            <span class="font-bold text-rose-600">{{ (float) $item->net_available }} {{ $item->unit }}</span>
                                                        </div>
                                                    @endif
                                                </div>

                                                <!-- Item Information -->
                                                <!-- Item Information -->
                                                <div>
                                                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">ITEM INFORMATION</h3>
                                                    <div class="bg-white rounded-xl border border-slate-100 divide-y divide-slate-100 text-xs">
                                                        <div class="py-2.5 px-3.5 flex justify-between">
                                                            <span class="text-slate-500 font-medium">Item Name</span>
                                                            <span class="font-bold text-slate-800">{{ $item->name }}</span>
                                                        </div>
                                                        <div class="py-2.5 px-3.5 flex justify-between">
                                                            <span class="text-slate-500 font-medium">Item Code</span>
                                                            <span class="font-mono font-semibold text-slate-700">{{ $item->item_code ?? '—' }}</span>
                                                        </div>
                                                        <div class="py-2.5 px-3.5 flex justify-between items-center">
                                                            <span class="text-slate-500 font-medium">Status</span>
                                                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-bold {{ $item->status === 'inactive' ? 'bg-slate-100 text-slate-600' : 'bg-emerald-50 text-emerald-700 border border-emerald-100' }}">
                                                                <span class="w-1.5 h-1.5 rounded-full {{ $item->status === 'inactive' ? 'bg-slate-400' : 'bg-emerald-500' }}"></span>
                                                                {{ ucfirst($item->status ?? 'active') }}
                                                            </span>
                                                        </div>
                                                        <div class="py-2.5 px-3.5 flex justify-between">
                                                            <span class="text-slate-500 font-medium">Category</span>
                                                            <span class="font-semibold text-slate-700">{{ $item->category ?? '—' }}</span>
                                                        </div>
                                                        <div class="py-2.5 px-3.5 flex justify-between">
                                                            <span class="text-slate-500 font-medium">Unit</span>
                                                            <span class="font-semibold text-slate-700">{{ $item->unit ?? '—' }}</span>
                                                        </div>
                                                        <div class="py-2.5 px-3.5 flex justify-between">
                                                            <span class="text-slate-500 font-medium">Unit Cost</span>
                                                            <span class="font-bold text-slate-900">₱{{ number_format((float) $item->unit_cost, 2) }}</span>
                                                        </div>
                                                        <div class="py-2.5 px-3.5 flex justify-between">
                                                            <span class="text-slate-500 font-medium">Item Type</span>
                                                            <span class="font-semibold {{ $item->is_perishable ? 'text-rose-600' : 'text-emerald-700' }}">
                                                                {{ $item->is_perishable ? 'Perishable (Consumable)' : 'Non-Perishable (Returnable)' }}
                                                            </span>
                                                        </div>
                                                        <div class="py-2.5 px-3.5 flex justify-between">
                                                            <span class="text-slate-500 font-medium">{{ $item->is_perishable ? 'Shelf Life' : 'Usable Life' }}</span>
                                                            <span class="font-semibold text-slate-700">
                                                                {{ $item->usable_life_value ? $item->usable_life_value . ' ' . $item->usable_life_unit : '—' }}
                                                            </span>
                                                        </div>
                                                        @if($item->latestStock?->usable_until)
                                                            <div class="py-2.5 px-3.5 flex justify-between">
                                                                <span class="text-slate-500 font-medium">Usable Until</span>
                                                                <span class="font-semibold {{ $item->latestStock->isExpired() ? 'text-rose-600' : 'text-slate-800' }}">
                                                                    {{ $item->latestStock->usable_until->format('M d, Y') }}
                                                                </span>
                                                            </div>
                                                        @endif
                                                        @if($item->supplier_name)
                                                            <div class="py-2.5 px-3.5 flex justify-between">
                                                                <span class="text-slate-500 font-medium">Supplier</span>
                                                                <span class="font-semibold text-slate-700">{{ $item->supplier_name }}</span>
                                                            </div>
                                                        @endif
                                                        @if($item->storage_location)
                                                            <div class="py-2.5 px-3.5 flex justify-between">
                                                                <span class="text-slate-500 font-medium">Location</span>
                                                                <span class="font-semibold text-slate-700">{{ $item->storage_location }}</span>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>

                                                <!-- Quick Actions -->
                                                <div>
                                                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">QUICK ACTIONS</h3>
                                                    <div class="grid grid-cols-2 gap-2.5">
                                                        <button
                                                            type="button"
                                                            onclick="openReceiveStockModal({{ $item->id }}, '{{ addslashes($item->name) }}', '{{ addslashes($item->item_code ?? ('INV-' . str_pad($item->id, 4, '0', STR_PAD_LEFT))) }}', '{{ addslashes($item->unit) }}', {{ (float) $item->current_stock }}, {{ (float) $item->reserved_stock }}, {{ (float) $item->to_procure }}, {{ (float) $item->unit_cost }})"
                                                            class="flex items-center justify-center gap-1.5 p-2.5 bg-brand-700 hover:bg-brand-800 border border-brand-700 rounded-xl text-xs font-semibold text-white shadow-2xs transition cursor-pointer"
                                                        >
                                                            <i class="fa-solid fa-boxes-packing"></i>
                                                            <span>Receive Stock</span>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onclick="openAdjustStockModal({{ $item->id }}, '{{ addslashes($item->name) }}', '{{ addslashes($item->item_code ?? ('INV-' . str_pad($item->id, 4, '0', STR_PAD_LEFT))) }}', '{{ addslashes($item->unit) }}', {{ (float) $item->current_stock }})"
                                                            class="flex items-center justify-center gap-1.5 p-2.5 bg-emerald-50/70 hover:bg-emerald-100/70 border border-emerald-200 rounded-xl text-xs font-semibold text-emerald-800 transition cursor-pointer"
                                                        >
                                                            <i class="fa-solid fa-sliders text-emerald-600"></i>
                                                            <span>Adjust Stock</span>
                                                        </button>
                                                        <a
                                                            href="{{ route('admin.inventory.edit', $item) }}"
                                                            class="inventory-edit-action flex items-center justify-center gap-1.5 p-2.5 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 transition"
                                                        >
                                                            <i class="fa-solid fa-pen-to-square"></i>
                                                            <span>Edit</span>
                                                        </a>
                                                        <form
                                                            action="{{ route('admin.inventory.archive', $item) }}"
                                                            method="POST"
                                                            data-confirm="Archive {{ $item->name }}?" data-confirm-message="This item will be removed from active inventory while its historical records are preserved." data-confirm-label="Archive item" data-confirm-tone="archive"
                                                        >
                                                            @csrf
                                                            <button type="submit" class="w-full flex items-center justify-center gap-1.5 p-2.5 bg-rose-50/60 hover:bg-rose-100/70 border border-rose-200 rounded-xl text-xs font-semibold text-rose-700 transition cursor-pointer">
                                                                <i class="fa-solid fa-box-archive text-rose-600"></i>
                                                                <span>Archive Item</span>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>

                                                <!-- Recent Activity Summary -->
                                                <div>
                                                    <div class="flex items-center justify-between mb-3">
                                                        <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider">RECENT ACTIVITY</h3>
                                                        <button
                                                            type="button"
                                                            onclick="switchDrawerTab('history-{{ $item->id }}')"
                                                            class="text-xs font-semibold text-emerald-600 hover:text-emerald-700 inline-flex items-center gap-1 cursor-pointer"
                                                        >
                                                            <span>View Stock History</span>
                                                            <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                                        </button>
                                                    </div>

                                                    @php
                                                        $recentTxs = $item->inventoryTransactions->take(3);
                                                    @endphp

                                                    @if($recentTxs->isNotEmpty())
                                                        <div class="space-y-2.5">
                                                            @foreach($recentTxs as $tx)
                                                                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 text-xs flex items-start justify-between gap-3">
                                                                    <div class="min-w-0">
                                                                        <div class="flex items-center gap-2">
                                                                            <span class="font-bold text-slate-800 capitalize">{{ $tx->transaction_type === 'procurement' ? 'Stock Received' : str_replace('_', ' ', $tx->transaction_type) }}</span>
                                                                            @if($tx->booking_id)
                                                                                <span class="text-[10px] font-mono bg-brand-50 text-brand-700 px-1.5 py-0.2 rounded border border-brand-100">
                                                                                    Booking #{{ $tx->booking_id }}
                                                                                </span>
                                                                            @endif
                                                                        </div>
                                                                        <p class="text-slate-500 text-[11px] mt-0.5 truncate">{{ $tx->reason ?? 'Stock received' }}</p>
                                                                        <p class="text-slate-400 text-[10px] mt-0.5">{{ $tx->created_at?->format('M d, Y h:i A') }} &middot; By {{ $tx->performedByUser?->name ?? 'System' }}</p>
                                                                    </div>
                                                                    <div class="shrink-0 text-right">
                                                                        <span class="font-extrabold text-sm {{ (float) $tx->quantity_change > 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                                                            {{ (float) $tx->quantity_change > 0 ? '+' : '' }}{{ (float) $tx->quantity_change }}
                                                                        </span>
                                                                        <span class="text-[10px] text-slate-400 block">{{ $item->unit }}</span>
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <div class="p-4 bg-slate-50 rounded-xl border border-dashed border-slate-200 text-center text-xs text-slate-400">
                                                            No recent transactions recorded for this item.
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>

                                            <!-- TAB 2: STOCK HISTORY -->
                                            <div id="drawer-tab-history-{{ $item->id }}" class="drawer-tab-pane hidden space-y-4">
                                                <div class="flex items-center justify-between">
                                                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider">TRANSACTION LEDGER</h3>
                                                    <span class="text-xs text-slate-400 font-medium">Sorted newest first</span>
                                                </div>

                                                @if($item->inventoryTransactions->isNotEmpty())
                                                    <div class="divide-y divide-slate-100 border border-slate-100 rounded-xl overflow-hidden bg-white shadow-2xs">
                                                        @foreach($item->inventoryTransactions as $tx)
                                                            <div class="p-3.5 hover:bg-slate-50/60 transition text-xs flex items-start justify-between gap-3">
                                                                <div class="min-w-0">
                                                                    <div class="flex items-center gap-2">
                                                                        <span class="font-bold text-slate-800 capitalize">{{ $tx->transaction_type === 'procurement' ? 'Stock Received' : str_replace('_', ' ', $tx->transaction_type) }}</span>
                                                                        @if($tx->booking_id)
                                                                            <span class="text-[10px] font-mono bg-brand-50 text-brand-700 px-1.5 py-0.2 rounded border border-brand-100">
                                                                                Booking #{{ $tx->booking_id }}
                                                                            </span>
                                                                        @endif
                                                                    </div>
                                                                    @if($tx->reason)
                                                                        <p class="text-slate-600 text-xs mt-1">{{ $tx->reason }}</p>
                                                                    @endif
                                                                    <div class="flex items-center gap-2 text-[10px] text-slate-400 mt-1">
                                                                        <span><i class="fa-regular fa-clock mr-1"></i>{{ $tx->created_at?->format('M d, Y h:i A') }}</span>
                                                                        <span>&middot;</span>
                                                                        <span><i class="fa-regular fa-user mr-1"></i>{{ $tx->performedByUser?->name ?? 'System' }}</span>
                                                                    </div>
                                                                </div>
                                                                <div class="shrink-0 text-right">
                                                                    <span class="font-extrabold text-sm {{ (float) $tx->quantity_change > 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                                                        {{ (float) $tx->quantity_change > 0 ? '+' : '' }}{{ (float) $tx->quantity_change }}
                                                                    </span>
                                                                    <span class="text-[10px] text-slate-400 block">{{ $item->unit }}</span>
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <div class="p-8 bg-slate-50 rounded-2xl border border-dashed border-slate-200 text-center">
                                                        <i class="fa-solid fa-clock-rotate-left text-2xl text-slate-300 mb-2"></i>
                                                        <p class="text-xs font-semibold text-slate-600">No stock history recorded</p>
                                                        <p class="text-[11px] text-slate-400 mt-0.5">Transactions will appear here when stock is adjusted, locked for bookings, or returned.</p>
                                                    </div>
                                                @endif
                                            </div>

                                            <!-- TAB 3: PACKAGE USAGE -->
                                            <div id="drawer-tab-packages-{{ $item->id }}" class="drawer-tab-pane hidden space-y-4">
                                                <div class="flex items-center justify-between">
                                                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider">PACKAGE BOM USAGE</h3>
                                                    <span class="text-xs text-slate-400 font-medium">BOM relationships</span>
                                                </div>

                                                @if($item->packages->isNotEmpty())
                                                    <div class="space-y-3">
                                                        @foreach($item->packages as $pkg)
                                                            <div class="p-4 bg-white border border-slate-200 rounded-xl shadow-2xs hover:border-emerald-300 transition flex items-start justify-between gap-3">
                                                                <div class="min-w-0">
                                                                    <p class="text-sm font-bold text-slate-900 truncate">{{ $pkg->title }}</p>
                                                                    <div class="flex items-center gap-2 text-xs text-slate-500 mt-1">
                                                                        <span class="font-mono text-[11px] bg-slate-100 px-2 py-0.5 rounded">{{ $pkg->package_code ?? ('PKG-' . $pkg->id) }}</span>
                                                                        <span>&middot;</span>
                                                                        <span>{{ $pkg->category ?? 'Standard' }}</span>
                                                                    </div>
                                                                </div>
                                                                <div class="text-right shrink-0">
                                                                    <span class="text-xs font-semibold text-slate-400 block">Required BOM:</span>
                                                                    <span class="text-sm font-extrabold text-emerald-700">
                                                                        {{ (float) $pkg->pivot->quantity }} {{ $item->unit }}
                                                                    </span>
                                                                    <div class="mt-2">
                                                                        <a href="{{ route('admin.packages.edit', $pkg) }}" class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600 hover:text-emerald-700">
                                                                            <span>View Package</span>
                                                                            <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <div class="p-8 bg-slate-50 rounded-2xl border border-dashed border-slate-200 text-center">
                                                        <i class="fa-solid fa-box-archive text-2xl text-slate-300 mb-2"></i>
                                                        <p class="text-xs font-semibold text-slate-600">No package usage</p>
                                                        <p class="text-[11px] text-slate-400 mt-0.5">This item is not currently linked to any package bill of materials (BOM).</p>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        <!-- DRAWER FOOTER -->
                                        <div class="p-4 border-t border-slate-100 bg-slate-50/70 flex items-center justify-between">
                                            <span class="text-xs text-slate-400 font-mono">ID #{{ $item->id }}</span>
                                            <button
                                                type="button"
                                                onclick="closeItemDrawer()"
                                                class="px-4 py-2 border border-slate-300 text-xs font-semibold rounded-xl text-slate-700 bg-white hover:bg-slate-50 transition shadow-2xs cursor-pointer"
                                            >
                                                Close
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="h-12 w-12 text-slate-200 mb-3">
                                        <i class="fa-solid fa-box-open text-4xl"></i>
                                    </div>
                                    <p class="text-slate-500 font-medium text-sm">No inventory items found.</p>
                                    @if(request('search') || $hasActiveFilters)
                                        <a href="{{ route('admin.inventory.index') }}" class="text-emerald-600 hover:text-emerald-700 mt-2 text-xs font-semibold">
                                            Clear all filters and search
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        <div class="px-6 py-4 border-t border-slate-100 bg-white flex flex-wrap items-center justify-between gap-3">
            @if($inventoryItems->hasPages())
                <div class="w-full sm:w-auto">
                    {{ $inventoryItems->links() }}
                </div>
            @else
                <p class="text-xs text-slate-500 leading-5">
                    Showing
                    @if($inventoryItems->count() > 0)
                        <span class="font-semibold text-slate-700">1</span>
                        to
                        <span class="font-semibold text-slate-700">{{ $inventoryItems->count() }}</span>
                    @else
                        <span class="font-semibold text-slate-700">0</span>
                    @endif
                    of
                    <span class="font-semibold text-slate-700">{{ $inventoryItems->total() }}</span>
                    items
                </p>
            @endif
        </div>
    </div>

    <!-- Slide-over Drawer Host Container -->
    <div
        id="itemDrawerContainer"
        class="fixed inset-0 z-50 overflow-hidden hidden"
        aria-labelledby="itemDrawerTitle"
        role="dialog"
        aria-modal="true"
    >
        <!-- Backdrop -->
        <div
            id="itemDrawerBackdrop"
            onclick="closeItemDrawer()"
            class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity opacity-0 duration-300"
        ></div>

        <!-- Sliding Panel -->
        <div class="fixed inset-y-0 right-0 max-w-full flex pl-10">
            <div
                id="itemDrawerPanel"
                class="w-screen max-w-xl bg-white shadow-2xl flex flex-col transform translate-x-full transition-transform duration-300 ease-in-out"
            >
                <div id="itemDrawerContent" class="h-full flex flex-col justify-between overflow-y-auto">
                    <!-- Populated dynamically from template -->
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Inventory CSV Modal -->
    <div id="uploadCsvModal" style="display:none;" class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="uploadCsvModalTitle">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity" aria-hidden="true" onclick="closeUploadModal()"></div>

        <!-- Modal Dialog -->
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6 transform transition-all border border-slate-200">
            <!-- Header -->
            <div class="flex items-center justify-between pb-4 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-emerald-50 flex items-center justify-center text-emerald-600">
                        <i class="fa-solid fa-file-arrow-up text-lg" aria-hidden="true"></i>
                    </div>
                    <div>
                        <h3 id="uploadCsvModalTitle" class="text-lg font-bold text-gray-900">Upload Inventory CSV</h3>
                        <p class="text-xs text-gray-500">Import new items or update existing records in bulk</p>
                    </div>
                </div>
                <button type="button" onclick="closeUploadModal()" class="rounded-lg p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-500 transition cursor-pointer" aria-label="Close modal">
                    <i class="fa-solid fa-xmark text-lg" aria-hidden="true"></i>
                </button>
            </div>

            <!-- Upload Form -->
            <form action="{{ route('admin.inventory.import') }}" method="POST" enctype="multipart/form-data" id="uploadCsvForm" class="mt-5 space-y-4">
                @csrf

                <!-- File Input -->
                <div>
                    <label for="csv_file" class="block text-sm font-semibold text-gray-700 mb-1">
                        Select CSV File <span class="text-red-500">*</span>
                    </label>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv" required
                           class="block w-full text-sm text-gray-600 file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 file:cursor-pointer border border-gray-300 rounded-lg p-1.5 bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition">
                </div>

                <!-- Format Guidance Box -->
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-4 space-y-2">
                    <div class="flex items-center gap-2 text-xs font-bold text-slate-700 uppercase tracking-wider">
                        <i class="fa-solid fa-circle-info text-emerald-600" aria-hidden="true"></i>
                        <span>CSV Columns</span>
                    </div>
                    <p class="text-xs font-mono text-slate-600 bg-white p-2 rounded border border-slate-200 break-all select-all">
                        name, category, is_perishable, current_stock, unit_cost, min_stock, unit
                    </p>
                    <ul class="text-xs text-slate-500 space-y-1 list-disc pl-4">
                        <li><strong>Safe Upsert:</strong> Matches on exact name. Existing items will be updated; new items created.</li>
                        <li><strong>Transactional:</strong> The import is atomic. If any row is invalid, all changes roll back.</li>
                        <li><strong>Duplicate protection:</strong> Duplicate item names inside the file will be rejected.</li>
                    </ul>
                </div>

                <!-- Template quick link -->
                <div class="flex items-center justify-between text-xs text-slate-500 pt-1">
                    <span>Need the template?</span>
                    <a href="{{ route('admin.inventory.template') }}" class="text-emerald-600 hover:text-emerald-700 font-semibold inline-flex items-center gap-1">
                        <i class="fa-solid fa-download" aria-hidden="true"></i> Download CSV Template
                    </a>
                </div>

                <!-- Buttons -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                    <button type="button" onclick="closeUploadModal()" class="px-4 py-2 border border-gray-300 text-sm font-semibold rounded-lg text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-300 transition cursor-pointer">
                        Cancel
                    </button>
                    <button type="submit" id="submitUploadBtn" class="px-5 py-2 bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-brand-500 transition inline-flex items-center gap-2 cursor-pointer">
                        <i class="fa-solid fa-upload" aria-hidden="true"></i> Upload CSV
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- CSV Import Instructions Modal -->
    <div id="importInstructionsModal" style="display:none;" class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="importInstructionsModalTitle">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity" aria-hidden="true" onclick="closeInstructionsModal()"></div>

        <!-- Modal Dialog -->
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-xl p-6 sm:p-7 transform transition-all border border-slate-200">
            <!-- Header -->
            <div class="flex items-center justify-between pb-4 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600 shrink-0">
                        <i class="fa-solid fa-circle-info text-lg" aria-hidden="true"></i>
                    </div>
                    <div>
                        <h3 id="importInstructionsModalTitle" class="text-lg font-bold text-gray-900">CSV Import Instructions</h3>
                        <p class="text-xs text-gray-500">Format specifications, column definitions, and upload guidelines</p>
                    </div>
                </div>
                <button type="button" onclick="closeInstructionsModal()" class="rounded-lg p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-500 transition cursor-pointer" aria-label="Close modal">
                    <i class="fa-solid fa-xmark text-lg" aria-hidden="true"></i>
                </button>
            </div>

            <!-- Content -->
            <div class="mt-5 space-y-4 text-sm text-gray-600 max-h-[70vh] overflow-y-auto pr-1">
                <!-- Header Format Box -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-1.5">Required CSV Header</label>
                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-200 font-mono text-xs text-emerald-800 break-all select-all font-semibold">
                        name,category,is_perishable,current_stock,unit_cost,min_stock,unit
                    </div>
                </div>

                <!-- Required Columns List -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Required Columns</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                        <div class="p-2.5 rounded-lg border border-gray-100 bg-gray-50/60">
                            <span class="font-bold text-gray-900 font-mono">name</span>
                            <p class="text-gray-500 mt-0.5">Item name (matched exactly for updates)</p>
                        </div>
                        <div class="p-2.5 rounded-lg border border-gray-100 bg-gray-50/60">
                            <span class="font-bold text-gray-900 font-mono">category</span>
                            <p class="text-gray-500 mt-0.5">Category name (e.g., Flowers, Decor)</p>
                        </div>
                        <div class="p-2.5 rounded-lg border border-gray-100 bg-gray-50/60">
                            <span class="font-bold text-gray-900 font-mono">is_perishable</span>
                            <p class="text-gray-500 mt-0.5">1 / yes (perishable) or 0 / no (non-perishable)</p>
                        </div>
                        <div class="p-2.5 rounded-lg border border-gray-100 bg-gray-50/60">
                            <span class="font-bold text-gray-900 font-mono">current_stock</span>
                            <p class="text-gray-500 mt-0.5">Current on-hand quantity (non-negative number)</p>
                        </div>
                        <div class="p-2.5 rounded-lg border border-gray-100 bg-gray-50/60">
                            <span class="font-bold text-gray-900 font-mono">unit_cost</span>
                            <p class="text-gray-500 mt-0.5">Cost per unit (non-negative currency value)</p>
                        </div>
                        <div class="p-2.5 rounded-lg border border-gray-100 bg-gray-50/60">
                            <span class="font-bold text-gray-900 font-mono">min_stock</span>
                            <p class="text-gray-500 mt-0.5">Low-stock alert threshold (non-negative)</p>
                        </div>
                        <div class="p-2.5 rounded-lg border border-gray-100 bg-gray-50/60 sm:col-span-2">
                            <span class="font-bold text-gray-900 font-mono">unit</span>
                            <p class="text-gray-500 mt-0.5">Standard unit of measure (e.g., pcs, stems, rolls, bundles, meters)</p>
                        </div>
                    </div>
                </div>

                <!-- Guidance Points -->
                <div class="p-3.5 bg-emerald-50/60 rounded-xl border border-emerald-100 text-xs text-gray-700 space-y-1.5">
                    <p class="font-bold text-emerald-900 flex items-center gap-1.5">
                        <i class="fa-solid fa-shield-halved text-emerald-600"></i> Import Rules & Guidance
                    </p>
                    <ul class="list-disc pl-4 space-y-1 text-gray-600">
                        <li><strong>Existing items are matched by name</strong> and updated with the new details.</li>
                        <li><strong>New items are created automatically</strong> if the name does not match any existing record.</li>
                        <li><strong>Current stock changes are recorded</strong> through the authoritative inventory adjustment ledger.</li>
                        <li><strong>Upload must use CSV format (.csv)</strong> with UTF-8 encoding.</li>
                        <li><strong>Atomic transactions:</strong> If any row contains invalid data, all rows are rolled back to protect system integrity.</li>
                    </ul>
                </div>
            </div>

            <!-- Footer / Action Buttons -->
            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-gray-100">
                <a href="{{ route('admin.inventory.template') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 rounded-xl transition">
                    <i class="fa-solid fa-download"></i> Download CSV Template
                </a>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="closeInstructionsModal()" class="px-4 py-2 border border-gray-300 text-xs font-semibold rounded-xl text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-300 transition cursor-pointer">
                        Close
                    </button>
                    <button type="button" onclick="closeInstructionsModal(); openUploadModal();" class="px-4 py-2 bg-brand-700 hover:bg-brand-800 text-white text-xs font-semibold rounded-xl shadow-xs focus:outline-none focus:ring-2 focus:ring-brand-500 transition inline-flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-upload"></i> Upload CSV Now
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Receive Stock Modal -->
    <div id="receiveStockModal" style="display:none;" class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="receiveStockModalTitle">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity" aria-hidden="true" onclick="closeReceiveStockModal()"></div>

        <!-- Modal Dialog -->
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6 sm:p-7 transform transition-all border border-slate-200">
            <!-- Header -->
            <div class="flex items-center justify-between pb-4 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600 shrink-0">
                        <i class="fa-solid fa-boxes-packing text-lg" aria-hidden="true"></i>
                    </div>
                    <div>
                        <h3 id="receiveStockModalTitle" class="text-lg font-bold text-gray-900">Receive Stock</h3>
                        <p class="text-xs text-gray-500">Record physically received and procured inventory</p>
                    </div>
                </div>
                <button type="button" onclick="closeReceiveStockModal()" class="rounded-lg p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-500 transition cursor-pointer" aria-label="Close modal">
                    <i class="fa-solid fa-xmark text-lg" aria-hidden="true"></i>
                </button>
            </div>

            <!-- Form -->
            <form id="receiveStockForm" method="POST" action="" class="mt-4 space-y-4">
                @csrf

                <!-- Selected Item Summary Box -->
                <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200 space-y-2.5 text-xs">
                    <div>
                        <p id="modalItemName" class="font-bold text-slate-900 text-sm">—</p>
                        <p class="text-slate-500 font-mono text-[11px] mt-0.5">
                            Code: <span id="modalItemCode">—</span> &middot; Unit: <span id="modalItemUnit" class="font-semibold text-slate-700">—</span>
                        </p>
                    </div>

                    <div class="grid grid-cols-3 gap-2 pt-2 border-t border-slate-200 text-center">
                        <div class="bg-white p-2 rounded-lg border border-slate-100">
                            <span class="block text-[10px] font-bold text-slate-400 uppercase">Current On Hand</span>
                            <span id="modalItemOnHand" class="font-extrabold text-slate-800 text-sm mt-0.5">0</span>
                        </div>
                        <div class="bg-white p-2 rounded-lg border border-slate-100">
                            <span class="block text-[10px] font-bold text-slate-400 uppercase">Booked Demand</span>
                            <span id="modalItemReserved" class="font-extrabold text-amber-600 text-sm mt-0.5">0</span>
                        </div>
                        <div class="bg-white p-2 rounded-lg border border-slate-100">
                            <span class="block text-[10px] font-bold text-slate-400 uppercase">To Procure</span>
                            <span id="modalItemToProcure" class="font-extrabold text-rose-600 text-sm mt-0.5">0</span>
                        </div>
                    </div>
                </div>

                <!-- Client-side Error Box -->
                <div id="modalClientError" class="hidden p-3 bg-rose-50 text-rose-700 border border-rose-200 rounded-xl text-xs font-semibold"></div>

                @if($errors->has('quantity') || $errors->has('unit_cost') || $errors->has('notes') || $errors->has('receive_stock'))
                    <div class="p-3 bg-rose-50 text-rose-700 border border-rose-200 rounded-xl text-xs space-y-1">
                        @foreach($errors->all() as $error)
                            <p class="flex items-center gap-1.5"><i class="fa-solid fa-circle-exclamation"></i> {{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <!-- Quantity Received Field -->
                <div>
                    <label for="quantityReceivedInput" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                        Quantity Received <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative rounded-xl shadow-2xs">
                        <input
                            type="number"
                            id="quantityReceivedInput"
                            name="quantity"
                            step="any"
                            min="0.01"
                            required
                            class="w-full py-2 px-3 bg-white border border-slate-200 rounded-xl text-sm font-semibold text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition"
                            placeholder="e.g. 50"
                        >
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <span id="modalInputUnit" class="text-xs font-semibold text-slate-400">units</span>
                        </div>
                    </div>
                    <p id="modalUnitHint" class="text-[11px] text-slate-500 mt-1">Enter physical quantity entering Raflora stock.</p>
                </div>

                <!-- Unit Cost Field (Optional) -->
                <div>
                    <label for="unitCostInput" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                        Unit Cost <span class="text-slate-400 font-normal text-[10px]">(Optional)</span>
                    </label>
                    <div class="relative rounded-xl shadow-2xs">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <span class="text-xs font-bold text-slate-400">₱</span>
                        </div>
                        <input
                            type="number"
                            id="unitCostInput"
                            name="unit_cost"
                            step="0.01"
                            min="0"
                            class="w-full py-2 pl-7 pr-3 bg-white border border-slate-200 rounded-xl text-sm font-semibold text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition"
                            placeholder="0.00"
                        >
                    </div>
                    <p class="text-[11px] text-slate-500 mt-1">Leaves existing cost unchanged if left blank.</p>
                </div>

                <!-- Reference / Notes Field -->
                <div>
                    <label for="referenceNotesInput" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                        Reference / Notes <span class="text-slate-400 font-normal text-[10px]">(Optional)</span>
                    </label>
                    <input
                        type="text"
                        id="referenceNotesInput"
                        name="notes"
                        maxlength="500"
                        class="w-full py-2 px-3 bg-white border border-slate-200 rounded-xl text-sm text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition"
                        placeholder="e.g., Receipt #10492 / Supplier delivery batch"
                    >
                    <p class="text-[11px] text-slate-500 mt-1">Traceable operational reference recorded in the inventory transaction ledger.</p>
                </div>

                <!-- Buttons -->
                <div class="flex items-center justify-end gap-3 pt-3 border-t border-gray-100">
                    <button
                        type="button"
                        onclick="closeReceiveStockModal()"
                        class="px-4 py-2 border border-gray-300 text-xs font-semibold rounded-xl text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-300 transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        id="submitReceiveStockBtn"
                        class="px-5 py-2 bg-brand-700 hover:bg-brand-800 text-white text-xs font-semibold rounded-xl shadow-2xs focus:outline-none focus:ring-2 focus:ring-brand-500 transition inline-flex items-center gap-2 cursor-pointer"
                    >
                        <i class="fa-solid fa-boxes-packing" aria-hidden="true"></i>
                        <span>Receive Stock</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Inventory Item Modal -->
    <div id="addInventoryItemModal" style="display:none;" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="addInventoryItemModalTitle">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity" aria-hidden="true" onclick="closeCreateModal()"></div>

        <!-- Modal Dialog -->
        <div class="relative bg-white rounded-3xl shadow-2xl w-full max-w-4xl p-5 sm:p-7 transform transition-all border border-slate-200 my-auto max-h-[92vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-rose-50 border border-rose-100 flex items-center justify-center text-rose-600 shrink-0">
                        <i class="fa-solid fa-boxes-stacked text-base" aria-hidden="true"></i>
                    </div>
                    <div>
                        <h3 id="addInventoryItemModalTitle" class="text-base sm:text-lg font-black text-slate-900 tracking-tight">Add Inventory Item</h3>
                        <p class="text-xs text-slate-500">Add a new item to your inventory. Fill in the details below.</p>
                    </div>
                </div>
                <button type="button" onclick="closeCreateModal()" class="rounded-xl p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition cursor-pointer" aria-label="Close modal">
                    <i class="fa-solid fa-xmark text-lg" aria-hidden="true"></i>
                </button>
            </div>

            <!-- Tab Navigation Bar -->
            <div class="border-b border-slate-100 py-2.5 shrink-0 overflow-x-auto">
                <nav class="flex space-x-2 text-xs font-semibold" aria-label="Modal Form Tabs">
                    <button type="button" onclick="switchCreateModalTab('tab-create-basic')" id="btn-modal-tab-create-basic" class="modal-tab-btn px-3 py-1.5 text-rose-700 bg-rose-50/70 border border-rose-200 rounded-xl font-bold flex items-center gap-1.5 shrink-0 transition">
                        <i class="fa-solid fa-file-lines text-xs"></i>
                        <span>Basic Information</span>
                    </button>
                    <button type="button" onclick="switchCreateModalTab('tab-create-stock')" id="btn-modal-tab-create-stock" class="modal-tab-btn px-3 py-1.5 text-slate-500 hover:text-slate-800 border border-transparent rounded-xl flex items-center gap-1.5 shrink-0 transition">
                        <i class="fa-solid fa-boxes-stacked text-xs"></i>
                        <span>Stock & Usable Life</span>
                    </button>
                    <button type="button" onclick="switchCreateModalTab('tab-create-details')" id="btn-modal-tab-create-details" class="modal-tab-btn px-3 py-1.5 text-slate-500 hover:text-slate-800 border border-transparent rounded-xl flex items-center gap-1.5 shrink-0 transition">
                        <i class="fa-solid fa-truck-ramp-box text-xs"></i>
                        <span>Supplier & Location</span>
                    </button>
                    <button type="button" onclick="switchCreateModalTab('tab-create-images')" id="btn-modal-tab-create-images" class="modal-tab-btn px-3 py-1.5 text-slate-500 hover:text-slate-800 border border-transparent rounded-xl flex items-center gap-1.5 shrink-0 transition">
                        <i class="fa-solid fa-image text-xs"></i>
                        <span>Images</span>
                    </button>
                    <button type="button" onclick="switchCreateModalTab('tab-create-status')" id="btn-modal-tab-create-status" class="modal-tab-btn px-3 py-1.5 text-slate-500 hover:text-slate-800 border border-transparent rounded-xl flex items-center gap-1.5 shrink-0 transition">
                        <i class="fa-solid fa-tags text-xs"></i>
                        <span>Status & Tags</span>
                    </button>
                </nav>
            </div>

            <!-- Form Body (Scrollable) -->
            <form action="{{ route('admin.inventory.store') }}" method="POST" enctype="multipart/form-data" id="addInventoryItemForm" class="overflow-y-auto flex-1 py-4 pr-1 space-y-5">
                @csrf

                <!-- TAB 1: BASIC INFORMATION -->
                <div id="tab-create-basic" class="create-modal-tab-pane space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <!-- Item Name -->
                        <div class="sm:col-span-2">
                            <label for="modal_create_name" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Item Name <span class="text-rose-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="modal_create_name"
                                name="name"
                                required
                                placeholder="e.g. Pink Rose (Fresh)"
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition shadow-2xs"
                            >
                            <label class="mt-2 inline-flex items-start gap-2 text-xs text-amber-800">
                                <input type="checkbox" name="confirm_distinct_catalog_item" value="1" class="mt-0.5 rounded border-amber-400 text-brand-700 focus:ring-brand-500">
                                <span>I reviewed the possible catalog matches and confirm this is a genuinely different item.</span>
                            </label>
                        </div>

                        <!-- Item Code (Auto-generated & Locked) -->
                        <div>
                            <label for="modal_create_item_code" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">
                                Item Code
                            </label>
                            <div class="relative">
                                <input
                                    type="text"
                                    id="modal_create_item_code"
                                    readonly
                                    disabled
                                    value="Auto-generated"
                                    class="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-semibold text-slate-500 cursor-not-allowed pr-8 shadow-2xs"
                                >
                                <i class="fa-solid fa-lock absolute right-3 top-2.5 text-slate-400 text-xs"></i>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-1">Authoritative code generated server-side.</p>
                        </div>

                        <!-- Category -->
                        <div>
                            <label for="modal_create_category" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Category <span class="text-rose-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="modal_create_category"
                                name="category"
                                required
                                list="modalCategoriesList"
                                placeholder="e.g. Fresh Flowers"
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition shadow-2xs"
                            >
                            <datalist id="modalCategoriesList">
                                @foreach($categories as $cat)
                                    <option value="{{ $cat }}">{{ $cat }}</option>
                                @endforeach
                                <option value="Fresh Flowers">Fresh Flowers</option>
                                <option value="Foliage">Foliage</option>
                                <option value="Decor">Decor</option>
                                <option value="Equipment">Equipment</option>
                                <option value="Packaging">Packaging</option>
                            </datalist>
                        </div>

                        <!-- Unit -->
                        <div>
                            <label for="modal_create_unit" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Unit <span class="text-rose-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="modal_create_unit"
                                name="unit"
                                value="pcs"
                                required
                                list="modalUnitsList"
                                placeholder="e.g. stem, pcs, bunch"
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition shadow-2xs"
                            >
                            <datalist id="modalUnitsList">
                                <option value="stem">stem</option>
                                <option value="pcs">pcs</option>
                                <option value="bunch">bunch</option>
                                <option value="block">block</option>
                                <option value="roll">roll</option>
                                <option value="set">set</option>
                                <option value="box">box</option>
                                <option value="tray">tray</option>
                                <option value="meter">meter</option>
                            </datalist>
                        </div>

                        <!-- Item Type: Perishable vs Non-Perishable Segmented Cards -->
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Item Type <span class="text-rose-500">*</span>
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="flex items-center gap-2 p-2 border rounded-xl cursor-pointer transition border-rose-400 bg-rose-50/60" id="modalLabelTypePerishable">
                                    <input
                                        type="radio"
                                        name="item_type"
                                        value="perishable"
                                        checked
                                        onchange="handleModalItemTypeChange('perishable')"
                                        class="text-rose-600 focus:ring-rose-500 text-xs"
                                    >
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-slate-800">Perishable</p>
                                        <p class="text-[10px] text-slate-500 leading-tight">Consumable</p>
                                    </div>
                                </label>

                                <label class="flex items-center gap-2 p-2 border rounded-xl cursor-pointer transition border-slate-200 hover:bg-slate-50" id="modalLabelTypeNonPerishable">
                                    <input
                                        type="radio"
                                        name="item_type"
                                        value="non_perishable"
                                        onchange="handleModalItemTypeChange('non_perishable')"
                                        class="text-emerald-600 focus:ring-brand-500 text-xs"
                                    >
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-slate-800">Non-Perishable</p>
                                        <p class="text-[10px] text-slate-500 leading-tight">Returnable</p>
                                    </div>
                                </label>
                            </div>
                            <p id="modalItemTypeHelp" class="text-[10px] text-slate-400 mt-1">Fresh floral materials not expected to return post-event.</p>
                        </div>

                        <!-- Description -->
                        <div class="sm:col-span-3">
                            <label for="modal_create_description" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Description <span class="text-slate-400 font-normal text-[10px]">(Optional)</span>
                            </label>
                            <textarea
                                id="modal_create_description"
                                name="description"
                                rows="2"
                                maxlength="1000"
                                placeholder="Describe item quality, color, origin, or usage notes..."
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition shadow-2xs"
                            ></textarea>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: STOCK & USABLE LIFE -->
                <div id="tab-create-stock" class="create-modal-tab-pane hidden space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <!-- Initial Quantity -->
                        <div>
                            <label for="modal_create_initial_quantity" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Initial Quantity <span class="text-rose-500">*</span>
                            </label>
                            <input
                                type="number"
                                step="any"
                                min="0"
                                id="modal_create_initial_quantity"
                                name="initial_quantity"
                                value="0"
                                required
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition shadow-2xs"
                            >
                            <p class="text-[10px] text-slate-400 mt-1">Starting physical count.</p>
                        </div>

                        <!-- Reorder Level -->
                        <div>
                            <label for="modal_create_reorder_level" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Reorder Level (Min Stock) <span class="text-rose-500">*</span>
                            </label>
                            <input
                                type="number"
                                step="any"
                                min="0"
                                id="modal_create_reorder_level"
                                name="reorder_level"
                                value="10"
                                required
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition shadow-2xs"
                            >
                            <p class="text-[10px] text-slate-400 mt-1">Threshold for low-stock alerts.</p>
                        </div>

                        <!-- Unit Cost -->
                        <div>
                            <label for="modal_create_unit_cost" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Unit Cost (₱) <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <span class="absolute left-3 top-2 text-xs font-bold text-slate-400">₱</span>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    id="modal_create_unit_cost"
                                    name="unit_cost"
                                    value="0.00"
                                    required
                                    class="w-full pl-7 pr-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition shadow-2xs"
                                >
                            </div>
                            <p class="text-[10px] text-slate-400 mt-1">Cost per unit of measure.</p>
                        </div>

                        <!-- Received / Acquired Date -->
                        <div>
                            <label for="modal_create_received_date" id="modalLabelReceivedDate" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Received Date <span class="text-rose-500">*</span>
                            </label>
                            <input
                                type="date"
                                id="modal_create_received_date"
                                name="received_date"
                                value="{{ date('Y-m-d') }}"
                                required
                                onchange="calculateModalUsableUntil()"
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 transition shadow-2xs"
                            >
                            <p id="modalHintReceivedDate" class="text-[10px] text-slate-400 mt-1">Date materials entered custody.</p>
                        </div>

                        <!-- Shelf Life / Usable Life Value + Unit -->
                        <div>
                            <label for="modal_create_usable_life_value" id="modalLabelUsableLife" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Shelf Life <span class="text-rose-500">*</span>
                            </label>
                            <div class="flex items-center gap-2">
                                <input
                                    type="number"
                                    min="1"
                                    id="modal_create_usable_life_value"
                                    name="usable_life_value"
                                    value="7"
                                    required
                                    oninput="calculateModalUsableUntil()"
                                    class="w-20 px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:ring-2 focus:ring-brand-500 shadow-2xs"
                                >
                                <select
                                    id="modal_create_usable_life_unit"
                                    name="usable_life_unit"
                                    required
                                    onchange="calculateModalUsableUntil()"
                                    class="flex-1 px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:ring-2 focus:ring-brand-500 shadow-2xs"
                                >
                                    <option value="days" selected>days</option>
                                    <option value="weeks">weeks</option>
                                    <option value="months">months</option>
                                    <option value="years">years</option>
                                </select>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-1">Duration stock remains serviceable.</p>
                        </div>

                        <!-- Usable Until (Calculated Live Preview) -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label for="modal_create_usable_until" class="block text-xs font-bold uppercase tracking-wider text-slate-500">
                                    Usable Until
                                </label>
                                <span class="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-100">
                                    Auto-calculated
                                </span>
                            </div>
                            <div class="relative">
                                <input
                                    type="text"
                                    id="modal_create_usable_until"
                                    readonly
                                    disabled
                                    value="{{ date('Y-m-d', strtotime('+7 days')) }}"
                                    class="w-full px-3.5 py-2 bg-emerald-50/50 border border-emerald-200 rounded-xl text-xs font-mono font-bold text-emerald-900 cursor-not-allowed shadow-2xs pr-8"
                                >
                                <i class="fa-solid fa-calculator absolute right-3 top-2.5 text-emerald-600 text-xs"></i>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-1">Authoritative calculation: Date + Duration.</p>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: SUPPLIER & LOCATION (ADDITIONAL DETAILS) -->
                <div id="tab-create-details" class="create-modal-tab-pane hidden space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="modal_create_supplier_name" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Supplier Name <span class="text-slate-400 font-normal text-[10px]">(Optional)</span>
                            </label>
                            <input
                                type="text"
                                id="modal_create_supplier_name"
                                name="supplier_name"
                                placeholder="e.g. Blooming Fields PH"
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-800 focus:ring-2 focus:ring-brand-500 shadow-2xs"
                            >
                        </div>
                        <div>
                            <label for="modal_create_supplier_contact_person" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Contact Person <span class="text-slate-400 font-normal text-[10px]">(Optional)</span>
                            </label>
                            <input
                                type="text"
                                id="modal_create_supplier_contact_person"
                                name="supplier_contact_person"
                                placeholder="e.g. Ana Reyes"
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 shadow-2xs"
                            >
                        </div>
                        <div>
                            <label for="modal_create_supplier_contact_number" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Contact Number <span class="text-slate-400 font-normal text-[10px]">(Optional)</span>
                            </label>
                            <input
                                type="text"
                                id="modal_create_supplier_contact_number"
                                name="supplier_contact_number"
                                placeholder="e.g. 0917 123 4567"
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 shadow-2xs"
                            >
                        </div>
                        <div class="sm:col-span-3">
                            <label for="modal_create_storage_location" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Storage Location <span class="text-slate-400 font-normal text-[10px]">(Optional)</span>
                            </label>
                            <input
                                type="text"
                                id="modal_create_storage_location"
                                name="storage_location"
                                placeholder="e.g. Cold Storage Room A, Shelf 2"
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 shadow-2xs"
                            >
                        </div>

                        <!-- Seasonal Substitutes -->
                        <div class="sm:col-span-3 pt-2 border-t border-slate-100">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Seasonal Substitutes <span class="text-slate-400 font-normal text-[10px]">(Optional)</span>
                            </label>
                            <p class="text-[11px] text-slate-500 mb-2">Configure fallback materials for seasonal availability and shortage mitigation.</p>
                            <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between text-xs">
                                <span class="text-slate-600">Need to configure comprehensive substitute mapping with package BOM?</span>
                                <a href="{{ route('admin.inventory.create') }}" class="text-xs font-bold text-emerald-600 hover:text-emerald-700 inline-flex items-center gap-1">
                                    <span>Open Dedicated Form</span>
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 4: IMAGES -->
                <div id="tab-create-images" class="create-modal-tab-pane hidden space-y-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Item Image <span class="text-slate-400 font-normal text-[10px]">(Optional)</span>
                        </label>
                        <input
                            type="file"
                            name="image"
                            id="modal_create_image"
                            accept="image/*"
                            onchange="previewModalSelectedImage(this)"
                            class="block w-full text-xs text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 file:cursor-pointer border border-slate-200 rounded-xl p-2 bg-slate-50"
                        >
                        <p class="text-[10px] text-slate-400 mt-1">PNG, JPG, or WEBP up to 5MB.</p>
                    </div>

                    <div id="modalImagePreviewContainer" class="hidden pt-2">
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Image Preview</p>
                        <div class="relative w-40 h-40 rounded-2xl overflow-hidden border border-slate-200 bg-slate-50 shadow-2xs">
                            <img id="modalImagePreviewImg" src="" alt="Selected Preview" class="w-full h-full object-cover">
                        </div>
                    </div>
                </div>

                <!-- TAB 5: STATUS & TAGS -->
                <div id="tab-create-status" class="create-modal-tab-pane hidden space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Status -->
                        <div>
                            <label for="modal_create_status" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Catalog Status <span class="text-rose-500">*</span>
                            </label>
                            <select
                                id="modal_create_status"
                                name="status"
                                required
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:ring-2 focus:ring-brand-500 shadow-2xs"
                            >
                                <option value="active" selected>Active — Available for new event planning</option>
                                <option value="inactive">Inactive — Discontinued / Locked from planning</option>
                            </select>
                            <p class="text-[10px] text-slate-400 mt-1">Catalog status is distinct from individual stock usability.</p>
                        </div>

                        <!-- Tags -->
                        <div>
                            <label for="modal_create_tags" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                                Search Tags <span class="text-slate-400 font-normal text-[10px]">(Optional)</span>
                            </label>
                            <input
                                type="text"
                                id="modal_create_tags"
                                name="tags"
                                placeholder="e.g. Wedding, Bouquet, Premium, Centerpiece"
                                class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 shadow-2xs"
                            >
                            <p class="text-[10px] text-slate-400 mt-1">Comma-separated tags for filtering.</p>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Footer Actions -->
            <div class="flex items-center justify-between pt-4 border-t border-slate-100 shrink-0">
                <button
                    type="button"
                    onclick="closeCreateModal()"
                    class="px-4 py-2 border border-slate-300 text-xs font-semibold rounded-xl text-slate-700 bg-white hover:bg-slate-50 transition cursor-pointer"
                >
                    Cancel
                </button>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        id="btnModalPrev"
                        onclick="prevCreateModalTab()"
                        class="hidden px-3.5 py-2 border border-slate-200 text-xs font-semibold rounded-xl text-slate-700 bg-white hover:bg-slate-50 transition cursor-pointer"
                    >
                        <i class="fa-solid fa-chevron-left text-[10px] mr-1"></i>
                        <span>Previous</span>
                    </button>
                    <button
                        type="button"
                        id="btnModalNext"
                        onclick="nextCreateModalTab()"
                        class="px-5 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-xl shadow-xs transition inline-flex items-center gap-1.5 cursor-pointer"
                    >
                        <span>Next</span>
                        <i class="fa-solid fa-chevron-right text-[10px]"></i>
                    </button>
                    <button
                        type="button"
                        id="btnModalSave"
                        onclick="document.getElementById('addInventoryItemForm').submit();"
                        class="hidden px-5 py-2 bg-brand-700 hover:bg-brand-800 text-white text-xs font-bold rounded-xl shadow-xs transition inline-flex items-center gap-1.5 cursor-pointer"
                    >
                        <i class="fa-solid fa-check text-xs"></i>
                        <span>Save Item</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Adjust Stock Count Modal (Index Table & Drawer) -->
    <div id="adjustStockModalIndex" style="display:none;" class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="adjustStockModalIndexTitle">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity" aria-hidden="true" onclick="closeAdjustStockModal()"></div>

        <!-- Modal Dialog -->
        <div class="relative bg-white rounded-3xl shadow-2xl w-full max-w-md p-6 transform transition-all border border-slate-200">
            <!-- Header -->
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    <div>
                        <h3 id="adjustStockModalIndexTitle" class="text-base font-bold text-slate-900">Adjust Physical Stock</h3>
                        <p id="adjustModalItemSubtitle" class="text-xs text-slate-500 font-medium">—</p>
                    </div>
                </div>
                <button type="button" onclick="closeAdjustStockModal()" class="w-7 h-7 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 flex items-center justify-center transition cursor-pointer">
                    <i class="fa-solid fa-xmark text-xs"></i>
                </button>
            </div>

            <!-- Form -->
            <form id="adjustStockIndexForm" action="" method="POST" class="pt-4 space-y-4">
                @csrf
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 flex items-center justify-between text-xs">
                    <span class="text-slate-500 font-medium uppercase tracking-wider text-[11px]">Current On Hand</span>
                    <span id="adjustModalCurrentStock" class="font-black text-slate-800 text-sm">0</span>
                </div>

                <div>
                    <label for="adjust_index_new_stock" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                        New Count / Quantity <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="number"
                        step="any"
                        min="0"
                        id="adjust_index_new_stock"
                        name="new_stock"
                        required
                        placeholder="e.g. 100"
                        class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:ring-2 focus:ring-brand-500 shadow-2xs"
                    >
                </div>

                <div>
                    <label for="adjust_index_reason" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                        Reason / Notes <span class="text-rose-500">*</span>
                    </label>
                    <textarea
                        id="adjust_index_reason"
                        name="reason"
                        rows="2"
                        required
                        maxlength="500"
                        placeholder="e.g. Physical inventory cycle count correction"
                        class="w-full px-3.5 py-2 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 shadow-2xs"
                    ></textarea>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" onclick="closeAdjustStockModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition cursor-pointer">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-brand-700 hover:bg-brand-800 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer">
                        Confirm Adjustment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- UI Scripts -->
    <script>
        // Collapsible Filter Panel Toggle
        function toggleFilterPanel() {
            const panel = document.getElementById('filterPanel');
            const btn = document.getElementById('filterToggleBtn');
            const text = document.getElementById('filterToggleText');
            const chevron = document.getElementById('filterToggleChevron');
            if (!panel) return;

            const isHidden = panel.style.display === 'none' || panel.classList.contains('hidden');
            if (isHidden) {
                panel.style.display = 'block';
                panel.classList.remove('hidden');
                if (text) text.textContent = 'Hide Filters';
                if (chevron) chevron.classList.add('rotate-180');
                if (btn) btn.setAttribute('aria-expanded', 'true');
            } else {
                panel.style.display = 'none';
                panel.classList.add('hidden');
                if (text) text.textContent = 'Show Filters';
                if (chevron) chevron.classList.remove('rotate-180');
                if (btn) btn.setAttribute('aria-expanded', 'false');
            }
        }

        // Slide-over Drawer
        function openItemDrawer(itemId) {
            const template = document.getElementById('item-drawer-template-' + itemId);
            const container = document.getElementById('itemDrawerContainer');
            const backdrop = document.getElementById('itemDrawerBackdrop');
            const panel = document.getElementById('itemDrawerPanel');
            const content = document.getElementById('itemDrawerContent');

            if (!template || !container || !panel || !content) return;

            content.innerHTML = template.innerHTML;
            container.classList.remove('hidden');

            requestAnimationFrame(() => {
                backdrop.classList.remove('opacity-0');
                backdrop.classList.add('opacity-100');
                panel.classList.remove('translate-x-full');
                panel.classList.add('translate-x-0');
            });

            document.body.style.overflow = 'hidden';
        }

        function closeItemDrawer() {
            const container = document.getElementById('itemDrawerContainer');
            const backdrop = document.getElementById('itemDrawerBackdrop');
            const panel = document.getElementById('itemDrawerPanel');

            if (!container || !panel) return;

            backdrop.classList.remove('opacity-100');
            backdrop.classList.add('opacity-0');
            panel.classList.remove('translate-x-0');
            panel.classList.add('translate-x-full');

            setTimeout(() => {
                container.classList.add('hidden');
                document.body.style.overflow = '';
            }, 300);
        }

        // Drawer Tabs Switcher
        function switchDrawerTab(tabTarget) {
            const content = document.getElementById('itemDrawerContent');
            if (!content) return;

            const targetPane = content.querySelector('#drawer-tab-' + tabTarget);
            if (!targetPane) return;

            // Hide all panes
            content.querySelectorAll('.drawer-tab-pane').forEach(p => p.classList.add('hidden'));
            targetPane.classList.remove('hidden');

            // Deactivate all tab buttons
            content.querySelectorAll('.drawer-tab-btn').forEach(btn => {
                btn.classList.remove('border-emerald-600', 'text-emerald-700', 'active');
                btn.classList.add('border-transparent', 'text-slate-500');
            });

            // Activate target button
            const activeBtn = content.querySelector('#tab-btn-' + tabTarget);
            if (activeBtn) {
                activeBtn.classList.remove('border-transparent', 'text-slate-500');
                activeBtn.classList.add('border-emerald-600', 'text-emerald-700', 'active');
            }
        }

        // CSV Tools Popover Menu
        function toggleCsvDropdown() {
            const menu = document.getElementById('csvToolsMenu');
            const btn = document.getElementById('csvToolsButton');
            if (!menu || !btn) return;

            const isExpanded = btn.getAttribute('aria-expanded') === 'true';
            if (isExpanded) {
                closeCsvDropdown();
            } else {
                openCsvDropdown();
            }
        }

        function openCsvDropdown() {
            const menu = document.getElementById('csvToolsMenu');
            const btn = document.getElementById('csvToolsButton');
            const chevron = document.getElementById('csvToolsChevron');
            if (!menu || !btn) return;

            menu.style.display = 'block';
            btn.setAttribute('aria-expanded', 'true');
            if (chevron) chevron.classList.add('rotate-180');
        }

        function closeCsvDropdown() {
            const menu = document.getElementById('csvToolsMenu');
            const btn = document.getElementById('csvToolsButton');
            const chevron = document.getElementById('csvToolsChevron');
            if (!menu || !btn) return;

            menu.style.display = 'none';
            btn.setAttribute('aria-expanded', 'false');
            if (chevron) chevron.classList.remove('rotate-180');
        }

        function openUploadModal() {
            closeCsvDropdown();
            const modal = document.getElementById('uploadCsvModal');
            if (modal) {
                modal.style.display = 'flex';
                const fileInput = document.getElementById('csv_file');
                if (fileInput) fileInput.focus();
            }
        }

        function closeUploadModal() {
            const modal = document.getElementById('uploadCsvModal');
            if (modal) modal.style.display = 'none';
        }

        function openInstructionsModal() {
            closeCsvDropdown();
            const modal = document.getElementById('importInstructionsModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeInstructionsModal() {
            const modal = document.getElementById('importInstructionsModal');
            if (modal) modal.style.display = 'none';
        }

        // Receive Stock Modal Handlers
        function openReceiveStockModal(itemId, itemName, itemCode, unit, onHand, reserved, toProcure, unitCost) {
            const modal = document.getElementById('receiveStockModal');
            const form = document.getElementById('receiveStockForm');
            if (!modal || !form) return;

            form.action = '/admin/inventory/' + itemId + '/receive-stock';
            document.getElementById('modalItemName').textContent = itemName;
            document.getElementById('modalItemCode').textContent = itemCode;
            document.getElementById('modalItemUnit').textContent = unit;
            document.getElementById('modalItemOnHand').textContent = onHand;
            document.getElementById('modalItemReserved').textContent = reserved;
            document.getElementById('modalItemToProcure').textContent = toProcure;
            document.getElementById('modalInputUnit').textContent = unit;

            const discreteUnits = ['pcs', 'piece', 'pieces', 'stem', 'stems', 'block', 'blocks', 'bunch', 'bunches', 'unit', 'units', 'set', 'sets', 'box', 'boxes', 'roll', 'rolls', 'tray', 'trays', 'vase', 'vases', 'pot', 'pots'];
            const isDiscrete = discreteUnits.includes((unit || '').toLowerCase().trim());
            const qtyInput = document.getElementById('quantityReceivedInput');
            const unitHint = document.getElementById('modalUnitHint');
            if (qtyInput) {
                qtyInput.value = '';
                qtyInput.step = isDiscrete ? '1' : 'any';
                qtyInput.placeholder = isDiscrete ? 'e.g. 50 (whole ' + unit + ')' : 'e.g. 50.00';
            }
            if (unitHint) {
                unitHint.textContent = isDiscrete ? 'Must be a whole number for ' + unit + '.' : 'Enter physical quantity received in ' + unit + '.';
            }

            const costInput = document.getElementById('unitCostInput');
            if (costInput) {
                costInput.value = (unitCost && unitCost > 0) ? Number(unitCost).toFixed(2) : '';
            }

            const notesInput = document.getElementById('referenceNotesInput');
            if (notesInput) {
                notesInput.value = '';
            }

            const errorBox = document.getElementById('modalClientError');
            if (errorBox) {
                errorBox.classList.add('hidden');
                errorBox.textContent = '';
            }

            const btn = document.getElementById('submitReceiveStockBtn');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-boxes-packing" aria-hidden="true"></i> <span>Receive Stock</span>';
            }

            modal.style.display = 'flex';
            if (qtyInput) qtyInput.focus();
        }

        function closeReceiveStockModal() {
            const modal = document.getElementById('receiveStockModal');
            if (modal) modal.style.display = 'none';
        }

        document.getElementById('receiveStockForm')?.addEventListener('submit', function (e) {
            const qtyInput = document.getElementById('quantityReceivedInput');
            const unitText = document.getElementById('modalItemUnit')?.textContent || '';
            const errorBox = document.getElementById('modalClientError');
            const val = parseFloat(qtyInput?.value);

            if (isNaN(val) || val <= 0) {
                e.preventDefault();
                if (errorBox) {
                    errorBox.textContent = 'Quantity received must be greater than 0.';
                    errorBox.classList.remove('hidden');
                }
                return false;
            }

            const discreteUnits = ['pcs', 'piece', 'pieces', 'stem', 'stems', 'block', 'blocks', 'bunch', 'bunches', 'unit', 'units', 'set', 'sets', 'box', 'boxes', 'roll', 'rolls', 'tray', 'trays', 'vase', 'vases', 'pot', 'pots'];
            if (discreteUnits.includes(unitText.toLowerCase().trim()) && !Number.isInteger(val)) {
                e.preventDefault();
                if (errorBox) {
                    errorBox.textContent = 'Quantity received must be a whole number for ' + unitText + '.';
                    errorBox.classList.remove('hidden');
                }
                return false;
            }

            if (errorBox) {
                errorBox.classList.add('hidden');
            }
            const btn = document.getElementById('submitReceiveStockBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Receiving...';
            }
        });

        // Add Inventory Item Modal Handlers
        const createModalTabs = ['tab-create-basic', 'tab-create-stock', 'tab-create-details', 'tab-create-images', 'tab-create-status'];
        let currentCreateModalTabIndex = 0;

        function openCreateModal() {
            const modal = document.getElementById('addInventoryItemModal');
            if (!modal) return;
            modal.style.display = 'flex';
            switchCreateModalTab('tab-create-basic');
            calculateModalUsableUntil();
            const nameInput = document.getElementById('modal_create_name');
            if (nameInput) nameInput.focus();
        }

        function closeCreateModal() {
            const modal = document.getElementById('addInventoryItemModal');
            if (modal) modal.style.display = 'none';
        }

        function switchCreateModalTab(tabId) {
            document.querySelectorAll('.create-modal-tab-pane').forEach(el => el.classList.add('hidden'));
            const target = document.getElementById(tabId);
            if (target) target.classList.remove('hidden');

            document.querySelectorAll('.modal-tab-btn').forEach(btn => {
                btn.className = 'modal-tab-btn px-3 py-1.5 text-slate-500 hover:text-slate-800 border border-transparent rounded-xl flex items-center gap-1.5 shrink-0 transition';
            });
            const activeBtn = document.getElementById('btn-modal-' + tabId);
            if (activeBtn) {
                activeBtn.className = 'modal-tab-btn px-3 py-1.5 text-rose-700 bg-rose-50/70 border border-rose-200 rounded-xl font-bold flex items-center gap-1.5 shrink-0 transition';
            }

            currentCreateModalTabIndex = createModalTabs.indexOf(tabId);
            if (currentCreateModalTabIndex === -1) currentCreateModalTabIndex = 0;

            const prevBtn = document.getElementById('btnModalPrev');
            const nextBtn = document.getElementById('btnModalNext');
            const saveBtn = document.getElementById('btnModalSave');

            if (prevBtn) {
                if (currentCreateModalTabIndex === 0) {
                    prevBtn.classList.add('hidden');
                } else {
                    prevBtn.classList.remove('hidden');
                }
            }

            if (nextBtn && saveBtn) {
                if (currentCreateModalTabIndex === createModalTabs.length - 1) {
                    nextBtn.classList.add('hidden');
                    saveBtn.classList.remove('hidden');
                } else {
                    nextBtn.classList.remove('hidden');
                    saveBtn.classList.add('hidden');
                }
            }
        }

        function nextCreateModalTab() {
            if (currentCreateModalTabIndex < createModalTabs.length - 1) {
                switchCreateModalTab(createModalTabs[currentCreateModalTabIndex + 1]);
            }
        }

        function prevCreateModalTab() {
            if (currentCreateModalTabIndex > 0) {
                switchCreateModalTab(createModalTabs[currentCreateModalTabIndex - 1]);
            }
        }

        function handleModalItemTypeChange(type) {
            const isPerishable = type === 'perishable';
            const pLabel = document.getElementById('modalLabelTypePerishable');
            const npLabel = document.getElementById('modalLabelTypeNonPerishable');
            const helpText = document.getElementById('modalItemTypeHelp');
            const dateLabel = document.getElementById('modalLabelReceivedDate');
            const dateHint = document.getElementById('modalHintReceivedDate');
            const lifeLabel = document.getElementById('modalLabelUsableLife');

            if (isPerishable) {
                if (pLabel) pLabel.className = 'flex items-center gap-2 p-2 border rounded-xl cursor-pointer transition border-rose-400 bg-rose-50/60';
                if (npLabel) npLabel.className = 'flex items-center gap-2 p-2 border rounded-xl cursor-pointer transition border-slate-200 hover:bg-slate-50';
                if (helpText) helpText.textContent = 'Fresh floral materials not expected to return post-event.';
                if (dateLabel) dateLabel.innerHTML = 'Received Date <span class="text-rose-500">*</span>';
                if (dateHint) dateHint.textContent = 'Date materials entered custody.';
                if (lifeLabel) lifeLabel.innerHTML = 'Shelf Life <span class="text-rose-500">*</span>';
            } else {
                if (pLabel) pLabel.className = 'flex items-center gap-2 p-2 border rounded-xl cursor-pointer transition border-slate-200 hover:bg-slate-50';
                if (npLabel) npLabel.className = 'flex items-center gap-2 p-2 border rounded-xl cursor-pointer transition border-emerald-400 bg-emerald-50/60';
                if (helpText) helpText.textContent = 'Reusable / Returnable assets expected to return post-event.';
                if (dateLabel) dateLabel.innerHTML = 'Acquired Date <span class="text-rose-500">*</span>';
                if (dateHint) dateHint.textContent = 'Date asset was procured / placed in service.';
                if (lifeLabel) lifeLabel.innerHTML = 'Usable Life <span class="text-rose-500">*</span>';
            }
            calculateModalUsableUntil();
        }

        function calculateModalUsableUntil() {
            const dateInput = document.getElementById('modal_create_received_date');
            const valInput = document.getElementById('modal_create_usable_life_value');
            const unitInput = document.getElementById('modal_create_usable_life_unit');
            const outInput = document.getElementById('modal_create_usable_until');

            if (!dateInput || !valInput || !unitInput || !outInput) return;

            const dateStr = dateInput.value;
            const val = parseInt(valInput.value, 10);
            const unit = unitInput.value;

            if (!dateStr || isNaN(val) || val <= 0) return;

            const d = new Date(dateStr + 'T00:00:00');
            if (isNaN(d.getTime())) return;

            if (unit === 'days') d.setDate(d.getDate() + val);
            else if (unit === 'weeks') d.setDate(d.getDate() + (val * 7));
            else if (unit === 'months') d.setMonth(d.getMonth() + val);
            else if (unit === 'years') d.setFullYear(d.getFullYear() + val);

            const yyyy = d.getFullYear();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            outInput.value = `${yyyy}-${mm}-${dd}`;
        }

        function filterModalSubstitutes(query) {
            const q = query.toLowerCase().trim();
            document.querySelectorAll('.modal-substitute-item-row').forEach(row => {
                const name = row.getAttribute('data-name') || '';
                const cat = row.getAttribute('data-category') || '';
                if (!q || name.includes(q) || cat.includes(q)) {
                    row.classList.remove('hidden');
                } else {
                    row.classList.add('hidden');
                }
            });
        }

        function previewModalSelectedImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.getElementById('modalImagePreviewImg');
                    const container = document.getElementById('modalImagePreviewContainer');
                    if (img && container) {
                        img.src = e.target.result;
                        container.classList.remove('hidden');
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Adjust Stock Modal Handlers
        function openAdjustStockModal(itemId, itemName, itemCode, itemUnit, currentStock) {
            const modal = document.getElementById('adjustStockModalIndex');
            const form = document.getElementById('adjustStockIndexForm');
            if (!modal || !form) return;

            form.action = '/admin/inventory/' + itemId + '/adjust-stock';
            const subtitle = document.getElementById('adjustModalItemSubtitle');
            if (subtitle) subtitle.textContent = `${itemName} (${itemCode})`;

            const stockDisplay = document.getElementById('adjustModalCurrentStock');
            if (stockDisplay) stockDisplay.textContent = `${currentStock} ${itemUnit}`;

            const input = document.getElementById('adjust_index_new_stock');
            if (input) {
                input.value = '';
                input.placeholder = currentStock;
            }

            const reason = document.getElementById('adjust_index_reason');
            if (reason) reason.value = '';

            modal.style.display = 'flex';
            if (input) input.focus();
        }

        function closeAdjustStockModal() {
            const modal = document.getElementById('adjustStockModalIndex');
            if (modal) modal.style.display = 'none';
        }

        // Click outside listener for CSV Tools popover
        document.addEventListener('click', function (e) {
            const container = document.getElementById('csvToolsContainer');
            if (container && !container.contains(e.target)) {
                closeCsvDropdown();
            }
        });

        // Keyboard navigation (Escape key closes modals and drawers)
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                const createModal = document.getElementById('addInventoryItemModal');
                const adjustModal = document.getElementById('adjustStockModalIndex');
                const receiveModal = document.getElementById('receiveStockModal');
                const instructionsModal = document.getElementById('importInstructionsModal');
                const uploadModal = document.getElementById('uploadCsvModal');
                const csvMenu = document.getElementById('csvToolsMenu');
                const csvBtn = document.getElementById('csvToolsButton');
                const drawerContainer = document.getElementById('itemDrawerContainer');

                if (createModal && createModal.style.display === 'flex') {
                    closeCreateModal();
                } else if (adjustModal && adjustModal.style.display === 'flex') {
                    closeAdjustStockModal();
                } else if (receiveModal && receiveModal.style.display === 'flex') {
                    closeReceiveStockModal();
                } else if (instructionsModal && instructionsModal.style.display === 'flex') {
                    closeInstructionsModal();
                } else if (uploadModal && uploadModal.style.display === 'flex') {
                    closeUploadModal();
                } else if (csvMenu && csvMenu.style.display === 'block') {
                    closeCsvDropdown();
                    if (csvBtn) csvBtn.focus();
                } else if (drawerContainer && !drawerContainer.classList.contains('hidden')) {
                    closeItemDrawer();
                }
            }
        });
    </script>
</x-admin-layout>
