<x-admin-layout title="Add Inventory Item" description="Create a new item in your inventory catalog.">
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.inventory.index') }}" class="w-10 h-10 rounded-xl flex items-center justify-center bg-white border border-slate-200 text-slate-500 hover:text-emerald-700 hover:bg-emerald-50 transition shadow-2xs">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Add Inventory Item</h1>
                <p class="text-xs text-slate-500">Fill in the item specifications, stock threshold, and usable life durations.</p>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div class="mb-6 p-4 bg-rose-50 border border-rose-200 rounded-2xl flex items-start gap-3">
            <i class="fa-solid fa-circle-exclamation text-rose-600 mt-0.5 shrink-0"></i>
            <div class="text-xs text-rose-800">
                <p class="font-bold">Please correct the following errors:</p>
                <ul class="list-disc list-inside mt-1 space-y-0.5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden max-w-5xl">
        <form action="{{ route('admin.inventory.store') }}" method="POST" enctype="multipart/form-data" id="inventoryCreateForm" class="p-6 sm:p-8 space-y-8">
            @csrf

            <!-- TABS NAVIGATION -->
            <div class="border-b border-slate-100 pb-3">
                <nav class="flex space-x-2 sm:space-x-4 overflow-x-auto text-xs font-semibold" aria-label="Tabs">
                    <button type="button" onclick="switchFormTab('tab-basic')" id="btn-tab-basic" class="tab-btn px-3 py-2 text-rose-700 border-b-2 border-rose-600 font-bold flex items-center gap-2 shrink-0">
                        <i class="fa-solid fa-file-lines"></i>
                        <span>Basic Information</span>
                    </button>
                    <button type="button" onclick="switchFormTab('tab-stock')" id="btn-tab-stock" class="tab-btn px-3 py-2 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-2 shrink-0">
                        <i class="fa-solid fa-boxes-stacked"></i>
                        <span>Stock & Usable Life</span>
                    </button>
                    <button type="button" onclick="switchFormTab('tab-supplier')" id="btn-tab-supplier" class="tab-btn px-3 py-2 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-2 shrink-0">
                        <i class="fa-solid fa-truck-ramp-box"></i>
                        <span>Supplier & Location</span>
                    </button>
                    <button type="button" onclick="switchFormTab('tab-status')" id="btn-tab-status" class="tab-btn px-3 py-2 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-2 shrink-0">
                        <i class="fa-solid fa-tags"></i>
                        <span>Status & Tags</span>
                    </button>
                    <button type="button" onclick="switchFormTab('tab-images')" id="btn-tab-images" class="tab-btn px-3 py-2 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-2 shrink-0">
                        <i class="fa-solid fa-image"></i>
                        <span>Images</span>
                    </button>
                </nav>
            </div>

            <!-- TAB 1: BASIC INFORMATION -->
            <div id="tab-basic" class="tab-pane space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-slate-900 mb-1">Basic Information</h3>
                    <p class="text-xs text-slate-500">Essential identifiers, classification, and physical item type.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <!-- Item Name -->
                    <div class="md:col-span-2">
                        <label for="create_name" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Item Name <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="create_name"
                            name="name"
                            value="{{ old('name') }}"
                            required
                            placeholder="e.g. Pink Rose (Fresh)"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                        @error('name') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                        <label class="mt-2 inline-flex items-start gap-2 text-xs text-amber-800">
                            <input type="checkbox" name="confirm_distinct_catalog_item" value="1" @checked(old('confirm_distinct_catalog_item')) class="mt-0.5 rounded border-amber-400 text-brand-700 focus:ring-brand-500">
                            <span>I reviewed the possible catalog matches and confirm this is a genuinely different item.</span>
                        </label>
                    </div>

                    <!-- Item Code (Auto-generated & Locked) -->
                    <div>
                        <label for="create_item_code" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">
                            Item Code <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input
                                type="text"
                                id="create_item_code"
                                readonly
                                disabled
                                value="Auto-generated upon save"
                                class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-semibold text-slate-500 shadow-2xs cursor-not-allowed pr-8"
                            >
                            <i class="fa-solid fa-lock absolute right-3 top-3 text-slate-400 text-xs"></i>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1">Unique server-generated code (e.g. FR-0001).</p>
                    </div>

                    <!-- Category -->
                    <div>
                        <label for="create_category" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Category <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="create_category"
                            name="category"
                            value="{{ old('category') }}"
                            required
                            list="categoriesList"
                            placeholder="e.g. Fresh Flowers, Decor"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                        <datalist id="categoriesList">
                            @foreach($inventoryCategories as $cat)
                                <option value="{{ $cat }}">{{ $cat }}</option>
                            @endforeach
                            <option value="Fresh Flowers">Fresh Flowers</option>
                            <option value="Foliage">Foliage</option>
                            <option value="Decor">Decor</option>
                            <option value="Equipment">Equipment</option>
                            <option value="Packaging">Packaging</option>
                        </datalist>
                        @error('category') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Unit -->
                    <div>
                        <label for="create_unit" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Unit <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="create_unit"
                            name="unit"
                            value="{{ old('unit', 'pcs') }}"
                            required
                            list="unitsList"
                            placeholder="e.g. Stem, Piece, Bunch, Roll"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                        <datalist id="unitsList">
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
                        @error('unit') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Item Type: Perishable vs Non-Perishable -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Item Type <span class="text-rose-500">*</span>
                        </label>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex items-center gap-2 p-2.5 border rounded-xl cursor-pointer transition {{ old('item_type', 'perishable') === 'perishable' ? 'border-rose-400 bg-rose-50/50' : 'border-slate-200 hover:bg-slate-50' }}" id="label-type-perishable">
                                <input
                                    type="radio"
                                    name="item_type"
                                    value="perishable"
                                    onchange="handleItemTypeChange('perishable')"
                                    class="text-rose-600 focus:ring-rose-500"
                                    {{ old('item_type', 'perishable') === 'perishable' ? 'checked' : '' }}
                                >
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-slate-800">Perishable</p>
                                    <p class="text-[10px] text-slate-500 leading-tight">Consumable floral</p>
                                </div>
                            </label>

                            <label class="flex items-center gap-2 p-2.5 border rounded-xl cursor-pointer transition {{ old('item_type') === 'non_perishable' ? 'border-emerald-400 bg-emerald-50/50' : 'border-slate-200 hover:bg-slate-50' }}" id="label-type-non-perishable">
                                <input
                                    type="radio"
                                    name="item_type"
                                    value="non_perishable"
                                    onchange="handleItemTypeChange('non_perishable')"
                                    class="text-emerald-600 focus:ring-brand-500"
                                    {{ old('item_type') === 'non_perishable' ? 'checked' : '' }}
                                >
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-slate-800">Non-Perishable</p>
                                    <p class="text-[10px] text-slate-500 leading-tight">Returnable asset</p>
                                </div>
                            </label>
                        </div>
                        <p id="itemTypeHelp" class="text-[11px] text-slate-400 mt-1">Consumable materials are not expected to return through post-event return tracking.</p>
                    </div>

                    <!-- Description -->
                    <div class="md:col-span-3">
                        <div class="flex items-center justify-between mb-1">
                            <label for="create_description" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                Description <span class="text-slate-400 font-normal">(Optional)</span>
                            </label>
                            <span class="text-[10px] text-slate-400" id="descCharCount">0/500</span>
                        </div>
                        <textarea
                            id="create_description"
                            name="description"
                            rows="3"
                            maxlength="500"
                            oninput="document.getElementById('descCharCount').textContent = this.value.length + '/500'"
                            placeholder="Add item characteristics, color shade, dimensions, or handling instructions..."
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >{{ old('description') }}</textarea>
                    </div>
                </div>
            </div>

            <!-- TAB 2: STOCK & USABLE LIFE -->
            <div id="tab-stock" class="tab-pane hidden space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-slate-900 mb-1">Stock & Usable Life</h3>
                    <p class="text-xs text-slate-500">Initial quantities, reorder thresholds, and shelf life / serviceable life duration.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <!-- Initial Quantity -->
                    <div>
                        <label for="create_initial_quantity" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Initial Quantity <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="number"
                            step="any"
                            min="0"
                            id="create_initial_quantity"
                            name="initial_quantity"
                            value="{{ old('initial_quantity', 0) }}"
                            required
                            placeholder="0"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                        <p class="text-[11px] text-slate-400 mt-1">Starting physical stock quantity.</p>
                        @error('initial_quantity') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Reorder Level (min_stock) -->
                    <div>
                        <label for="create_min_stock" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Reorder Level (Minimum) <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="number"
                            step="any"
                            min="0"
                            id="create_min_stock"
                            name="min_stock"
                            value="{{ old('min_stock', 5) }}"
                            required
                            placeholder="0"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                        <p class="text-[11px] text-slate-400 mt-1">Triggers low-stock warning threshold.</p>
                        @error('min_stock') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Unit Cost -->
                    <div>
                        <label for="create_unit_cost" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Unit Cost (₱) <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            id="create_unit_cost"
                            name="unit_cost"
                            value="{{ old('unit_cost', 0) }}"
                            required
                            placeholder="0.00"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                        <p class="text-[11px] text-slate-400 mt-1">Cost per single unit.</p>
                        @error('unit_cost') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Reference / Received / Acquired Date -->
                    <div>
                        <label for="create_received_date" id="label_received_date" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Received Date <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="date"
                            id="create_received_date"
                            name="received_date"
                            value="{{ old('received_date', date('Y-m-d')) }}"
                            required
                            onchange="calculateUsableUntil()"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                        <p class="text-[11px] text-slate-400 mt-1" id="help_received_date">Procurement date for this initial stock batch.</p>
                        @error('received_date') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Usable Life Value + Unit -->
                    <div>
                        <label for="create_usable_life_value" id="label_usable_life" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Shelf Life <span class="text-rose-500">*</span>
                        </label>
                        <div class="flex gap-2">
                            <input
                                type="number"
                                min="1"
                                id="create_usable_life_value"
                                name="usable_life_value"
                                value="{{ old('usable_life_value', 5) }}"
                                required
                                oninput="calculateUsableUntil()"
                                class="w-1/2 px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                            >
                            <select
                                id="create_usable_life_unit"
                                name="usable_life_unit"
                                onchange="calculateUsableUntil()"
                                class="w-1/2 px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                            >
                                <option value="days" {{ old('usable_life_unit', 'days') === 'days' ? 'selected' : '' }}>Days</option>
                                <option value="weeks" {{ old('usable_life_unit') === 'weeks' ? 'selected' : '' }}>Weeks</option>
                                <option value="months" {{ old('usable_life_unit') === 'months' ? 'selected' : '' }}>Months</option>
                                <option value="years" {{ old('usable_life_unit') === 'years' ? 'selected' : '' }}>Years</option>
                            </select>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1" id="help_usable_life">Duration material remains fresh and usable.</p>
                        @error('usable_life_value') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Calculated Usable Until (Read-only) -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">
                            Usable Until <span class="text-emerald-700 font-semibold">(Calculated)</span>
                        </label>
                        <div class="relative">
                            <input
                                type="text"
                                id="preview_usable_until"
                                readonly
                                disabled
                                value="Calculating..."
                                class="w-full px-3.5 py-2.5 bg-emerald-50/60 border border-emerald-200 rounded-xl text-xs font-bold text-emerald-900 shadow-2xs cursor-not-allowed"
                            >
                            <i class="fa-regular fa-calendar-check absolute right-3 top-3 text-emerald-600 text-xs"></i>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1">Authoritatively computed by the server.</p>
                    </div>
                </div>
            </div>

            <!-- TAB 3: SUPPLIER & LOCATION -->
            <div id="tab-supplier" class="tab-pane hidden space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-slate-900 mb-1">Supplier & Storage Location</h3>
                    <p class="text-xs text-slate-500">Procurement source and warehouse location information (All optional).</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label for="create_supplier_name" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Supplier Name <span class="text-slate-400 font-normal">(Optional)</span>
                        </label>
                        <input
                            type="text"
                            id="create_supplier_name"
                            name="supplier_name"
                            value="{{ old('supplier_name') }}"
                            placeholder="e.g. Blooming Fields PH"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                    </div>

                    <div>
                        <label for="create_contact_person" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Contact Person <span class="text-slate-400 font-normal">(Optional)</span>
                        </label>
                        <input
                            type="text"
                            id="create_contact_person"
                            name="supplier_contact_person"
                            value="{{ old('supplier_contact_person') }}"
                            placeholder="e.g. Ana Reyes"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                    </div>

                    <div>
                        <label for="create_contact_number" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Contact Number <span class="text-slate-400 font-normal">(Optional)</span>
                        </label>
                        <input
                            type="text"
                            id="create_contact_number"
                            name="supplier_contact_number"
                            value="{{ old('supplier_contact_number') }}"
                            placeholder="e.g. 0917 123 4567"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                    </div>

                    <div>
                        <label for="create_storage_location" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Storage Location <span class="text-slate-400 font-normal">(Optional)</span>
                        </label>
                        <input
                            type="text"
                            id="create_storage_location"
                            name="storage_location"
                            value="{{ old('storage_location') }}"
                            placeholder="e.g. Cold Room Shelf B-2, Warehouse Rack 4"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                    </div>
                </div>
            </div>

            <!-- TAB 4: STATUS, TAGS & SUBSTITUTES -->
            <div id="tab-status" class="tab-pane hidden space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-slate-900 mb-1">Status, Tags & Seasonal Substitutes</h3>
                    <p class="text-xs text-slate-500">Configure catalog status and link alternative materials.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <!-- Status -->
                    <div>
                        <label for="create_status" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Item Status <span class="text-rose-500">*</span>
                        </label>
                        <select
                            id="create_status"
                            name="status"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                            <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>● Active — Available for planning</option>
                            <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>○ Inactive — Discontinued / Hidden</option>
                        </select>
                        <p class="text-[11px] text-slate-400 mt-1">Item status controls catalog availability, independent of batch expiration.</p>
                    </div>

                    <!-- Tags -->
                    <div>
                        <label for="create_tags" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Tags <span class="text-slate-400 font-normal">(Optional, comma separated)</span>
                        </label>
                        <input
                            type="text"
                            id="create_tags"
                            name="tags"
                            value="{{ old('tags') }}"
                            placeholder="e.g. Wedding, Bouquet, Premium, Centerpiece"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                    </div>

                    <!-- Seasonal Substitutes -->
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Seasonal Substitutes <span class="text-slate-400 font-normal">(Optional)</span>
                        </label>
                        <p class="text-[11px] text-slate-500 mb-2">Search and select items that can substitute this material during shortages.</p>
                        
                        <!-- Search filter for substitutes -->
                        <div class="mb-2">
                            <input
                                type="text"
                                id="substituteSearchInput"
                                oninput="filterSubstitutes(this.value)"
                                placeholder="Search items by name or category..."
                                class="w-full px-3 py-1.5 border border-slate-200 rounded-lg text-xs font-medium text-slate-700 focus:ring-2 focus:ring-brand-500"
                            >
                        </div>

                        <div class="border border-slate-200 rounded-xl p-3 max-h-48 overflow-y-auto space-y-1.5 bg-slate-50/50" id="substituteListContainer">
                            @php
                                $selectedSubs = (array) old('substitute_ids', []);
                            @endphp
                            @forelse($inventoryItems as $subItem)
                                <label class="substitute-item-row flex items-center justify-between p-2 rounded-lg bg-white border border-slate-100 hover:border-emerald-200 transition cursor-pointer text-xs" data-name="{{ strtolower($subItem->name) }}" data-category="{{ strtolower($subItem->category ?? '') }}">
                                    <div class="flex items-center gap-2.5">
                                        <input
                                            type="checkbox"
                                            name="substitute_ids[]"
                                            value="{{ $subItem->id }}"
                                            {{ in_array($subItem->id, $selectedSubs) ? 'checked' : '' }}
                                            class="rounded text-emerald-600 focus:ring-brand-500"
                                        >
                                        <span class="font-bold text-slate-800">{{ $subItem->name }}</span>
                                        <span class="text-[10px] text-slate-400 font-mono">({{ $subItem->item_code ?? 'INV-' . $subItem->id }})</span>
                                    </div>
                                    <span class="text-[10px] font-semibold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full">{{ $subItem->category }}</span>
                                </label>
                            @empty
                                <p class="text-xs text-slate-400 py-2 text-center">No other inventory items available to link.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 5: IMAGES -->
            <div id="tab-images" class="tab-pane hidden space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-slate-900 mb-1">Item Images</h3>
                    <p class="text-xs text-slate-500">Upload primary visual representation (Optional, max 5MB).</p>
                </div>

                <div>
                    <div class="flex justify-center px-6 pt-5 pb-6 border-2 border-slate-200 border-dashed rounded-2xl bg-slate-50/50 hover:bg-slate-100/50 transition relative">
                        <div class="space-y-2 text-center">
                            <i class="fa-solid fa-cloud-arrow-up text-3xl text-slate-400 mb-1"></i>
                            <div class="flex text-xs text-slate-600 justify-center">
                                <label for="create_image" class="relative cursor-pointer bg-white rounded-lg font-bold text-emerald-600 hover:text-emerald-700 px-3 py-1.5 border border-slate-200 shadow-2xs transition">
                                    <span>Browse Image</span>
                                    <input id="create_image" name="image" type="file" onchange="previewSelectedImage(this)" class="sr-only" accept="image/png, image/jpeg, image/webp">
                                </label>
                            </div>
                            <p class="text-[11px] text-slate-400">PNG, JPG, or WEBP up to 5MB</p>
                        </div>
                    </div>
                    
                    <div id="imagePreviewContainer" class="mt-4 hidden">
                        <p class="text-xs font-bold text-slate-600 mb-1">Selected Preview:</p>
                        <div class="relative inline-block border border-slate-200 rounded-xl overflow-hidden shadow-xs">
                            <img id="imagePreviewImg" src="" alt="Preview" class="h-28 w-28 object-cover">
                            <button type="button" onclick="clearSelectedImage()" class="absolute top-1 right-1 w-6 h-6 rounded-full bg-rose-600 text-white flex items-center justify-center text-xs shadow hover:bg-rose-700">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER ACTIONS -->
            <div class="pt-6 border-t border-slate-100 flex items-center justify-between">
                <a href="{{ route('admin.inventory.index') }}" class="px-5 py-2.5 border border-slate-200 hover:bg-slate-50 text-slate-700 font-semibold text-xs rounded-xl transition shadow-2xs">
                    Cancel
                </a>
                <div class="flex items-center gap-3">
                    <button type="submit" class="px-6 py-2.5 bg-rose-700 hover:bg-rose-800 text-white font-bold text-xs rounded-xl shadow-xs hover:shadow transition focus:outline-none focus:ring-2 focus:ring-rose-500 cursor-pointer">
                        <i class="fa-solid fa-check mr-1.5"></i> Save Item
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        function switchFormTab(tabId) {
            document.querySelectorAll('.tab-pane').forEach(el => el.classList.add('hidden'));
            const target = document.getElementById(tabId);
            if (target) target.classList.remove('hidden');

            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.className = 'tab-btn px-3 py-2 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-2 shrink-0';
            });
            const activeBtn = document.getElementById('btn-' + tabId);
            if (activeBtn) {
                activeBtn.className = 'tab-btn px-3 py-2 text-rose-700 border-b-2 border-rose-600 font-bold flex items-center gap-2 shrink-0';
            }
        }

        function handleItemTypeChange(type) {
            const isPerishable = type === 'perishable';
            const labelReceived = document.getElementById('label_received_date');
            const helpReceived = document.getElementById('help_received_date');
            const labelUsable = document.getElementById('label_usable_life');
            const helpUsable = document.getElementById('help_usable_life');
            const itemTypeHelp = document.getElementById('itemTypeHelp');
            const lifeValInput = document.getElementById('create_usable_life_value');
            const lifeUnitSelect = document.getElementById('create_usable_life_unit');

            const pLabel = document.getElementById('label-type-perishable');
            const npLabel = document.getElementById('label-type-non-perishable');

            if (isPerishable) {
                pLabel.className = 'flex items-center gap-2 p-2.5 border rounded-xl cursor-pointer transition border-rose-400 bg-rose-50/50';
                npLabel.className = 'flex items-center gap-2 p-2.5 border rounded-xl cursor-pointer transition border-slate-200 hover:bg-slate-50';
                labelReceived.innerHTML = 'Received Date <span class="text-rose-500">*</span>';
                helpReceived.textContent = 'Procurement date for this fresh floral material.';
                labelUsable.innerHTML = 'Shelf Life <span class="text-rose-500">*</span>';
                helpUsable.textContent = 'Usable life before material wilts/spoils.';
                itemTypeHelp.textContent = 'Consumable materials are not expected to return through post-event return tracking.';
                if (lifeUnitSelect.value === 'years') {
                    lifeUnitSelect.value = 'days';
                    lifeValInput.value = 5;
                }
            } else {
                pLabel.className = 'flex items-center gap-2 p-2.5 border rounded-xl cursor-pointer transition border-slate-200 hover:bg-slate-50';
                npLabel.className = 'flex items-center gap-2 p-2.5 border rounded-xl cursor-pointer transition border-emerald-400 bg-emerald-50/50';
                labelReceived.innerHTML = 'Acquired Date <span class="text-rose-500">*</span>';
                helpReceived.textContent = 'Purchase or acquisition date for this asset.';
                labelUsable.innerHTML = 'Usable Life <span class="text-rose-500">*</span>';
                helpUsable.textContent = 'Serviceable life before asset requires retirement/replacement.';
                itemTypeHelp.textContent = 'Reusable assets are eligible for post-event return tracking and recovery.';
                if (lifeUnitSelect.value === 'days') {
                    lifeUnitSelect.value = 'years';
                    lifeValInput.value = 3;
                }
            }
            calculateUsableUntil();
        }

        function calculateUsableUntil() {
            const dateInput = document.getElementById('create_received_date');
            const valInput = document.getElementById('create_usable_life_value');
            const unitSelect = document.getElementById('create_usable_life_unit');
            const preview = document.getElementById('preview_usable_until');

            if (!dateInput || !valInput || !unitSelect || !preview) return;

            const dateVal = dateInput.value;
            const amount = parseInt(valInput.value, 10);
            const unit = unitSelect.value;

            if (!dateVal || isNaN(amount) || amount <= 0) {
                preview.value = 'Please specify date and usable life';
                return;
            }

            const dt = new Date(dateVal);
            if (isNaN(dt.getTime())) {
                preview.value = 'Invalid date';
                return;
            }

            if (unit === 'days') {
                dt.setDate(dt.getDate() + amount);
            } else if (unit === 'weeks') {
                dt.setDate(dt.getDate() + (amount * 7));
            } else if (unit === 'months') {
                dt.setMonth(dt.getMonth() + amount);
            } else if (unit === 'years') {
                dt.setFullYear(dt.getFullYear() + amount);
            }

            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            preview.value = dt.toLocaleDateString(undefined, options) + ' (' + dt.toISOString().slice(0, 10) + ')';
        }

        function filterSubstitutes(query) {
            const q = query.toLowerCase().trim();
            document.querySelectorAll('.substitute-item-row').forEach(row => {
                const name = row.getAttribute('data-name') || '';
                const cat = row.getAttribute('data-category') || '';
                if (!q || name.includes(q) || cat.includes(q)) {
                    row.classList.remove('hidden');
                } else {
                    row.classList.add('hidden');
                }
            });
        }

        function previewSelectedImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('imagePreviewImg').src = e.target.result;
                    document.getElementById('imagePreviewContainer').classList.remove('hidden');
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function clearSelectedImage() {
            const input = document.getElementById('create_image');
            if (input) input.value = '';
            document.getElementById('imagePreviewContainer').classList.add('hidden');
        }

        document.addEventListener('DOMContentLoaded', () => {
            calculateUsableUntil();
        });
    </script>
</x-admin-layout>
