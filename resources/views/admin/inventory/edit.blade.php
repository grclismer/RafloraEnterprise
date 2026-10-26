<x-admin-layout title="Edit Inventory Item" description="Update details and metadata for {{ $inventoryItem->name }}.">
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.inventory.index') }}" class="w-10 h-10 rounded-xl flex items-center justify-center bg-white border border-slate-200 text-slate-500 hover:text-emerald-700 hover:bg-emerald-50 transition shadow-2xs">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Edit Inventory Item</h1>
                <p class="text-xs text-slate-500">Update metadata and catalog configuration for <span class="font-bold text-slate-800">{{ $inventoryItem->name }}</span> ({{ $inventoryItem->item_code }}).</p>
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

    <!-- CURRENT ON-HAND STOCK SAFEGUARD CARD -->
    <div class="bg-gradient-to-r from-slate-900 to-slate-800 text-white rounded-2xl p-5 mb-6 shadow-sm border border-slate-700 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 max-w-5xl">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-white/10 flex items-center justify-center text-emerald-400 text-xl shrink-0">
                <i class="fa-solid fa-cubes-stacked"></i>
            </div>
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-300">Authoritative Physical Stock</p>
                <p class="text-2xl font-black text-white">
                    {{ (float) $inventoryItem->current_stock }} <span class="text-sm font-semibold text-slate-300">{{ $inventoryItem->unit }}</span>
                </p>
                <p class="text-[11px] text-slate-400 mt-0.5">Physical stock cannot be overwritten directly during metadata editing to protect the ledger.</p>
            </div>
        </div>
        <div class="flex items-center gap-2.5 shrink-0 self-end sm:self-center">
            <button
                type="button"
                onclick="openAdjustModal()"
                class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-brand-700 hover:bg-brand-800 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer"
            >
                <i class="fa-solid fa-sliders text-xs"></i>
                <span>Adjust Stock</span>
            </button>
            <a
                href="{{ route('admin.inventory.index') }}"
                class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white/10 hover:bg-white/20 text-white font-semibold text-xs rounded-xl transition"
            >
                <i class="fa-solid fa-clock-rotate-left text-xs"></i>
                <span>View Inventory History</span>
            </a>
        </div>
    </div>

    <!-- MAIN METADATA EDIT FORM -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden max-w-5xl">
        <form action="{{ route('admin.inventory.update', $inventoryItem) }}" method="POST" enctype="multipart/form-data" id="inventoryEditForm" class="p-6 sm:p-8 space-y-8">
            @csrf
            @method('PUT')

            <!-- TABS NAVIGATION -->
            <div class="border-b border-slate-100 pb-3">
                <nav class="flex space-x-2 sm:space-x-4 overflow-x-auto text-xs font-semibold" aria-label="Tabs">
                    <button type="button" onclick="switchFormTab('tab-basic')" id="btn-tab-basic" class="tab-btn px-3 py-2 text-rose-700 border-b-2 border-rose-600 font-bold flex items-center gap-2 shrink-0">
                        <i class="fa-solid fa-file-lines"></i>
                        <span>Basic Information</span>
                    </button>
                    <button type="button" onclick="switchFormTab('tab-stock')" id="btn-tab-stock" class="tab-btn px-3 py-2 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-2 shrink-0">
                        <i class="fa-solid fa-boxes-stacked"></i>
                        <span>Pricing & Usable Life</span>
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
                    <p class="text-xs text-slate-500">Identifiers, classification, and physical item type.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <!-- Item Name -->
                    <div class="md:col-span-2">
                        <label for="edit_name" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Item Name <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="edit_name"
                            name="name"
                            value="{{ old('name', $inventoryItem->name) }}"
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

                    <!-- Item Code (Locked) -->
                    <div>
                        <label for="edit_item_code" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">
                            Item Code <span class="text-slate-400">(Read-Only)</span>
                        </label>
                        <div class="relative">
                            <input
                                type="text"
                                id="edit_item_code"
                                readonly
                                disabled
                                value="{{ $inventoryItem->item_code ?? 'INV-' . str_pad($inventoryItem->id, 4, '0', STR_PAD_LEFT) }}"
                                class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-semibold text-slate-600 shadow-2xs cursor-not-allowed pr-8"
                            >
                            <i class="fa-solid fa-lock absolute right-3 top-3 text-slate-400 text-xs"></i>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1">Authoritative unique identifier.</p>
                    </div>

                    <!-- Category -->
                    <div>
                        <label for="edit_category" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Category <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="edit_category"
                            name="category"
                            value="{{ old('category', $inventoryItem->category) }}"
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
                        <label for="edit_unit" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Unit <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="edit_unit"
                            name="unit"
                            value="{{ old('unit', $inventoryItem->unit) }}"
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
                            @php
                                $isPerish = old('item_type') ? (old('item_type') === 'perishable') : (bool) $inventoryItem->is_perishable;
                            @endphp
                            <label class="flex items-center gap-2 p-2.5 border rounded-xl cursor-pointer transition {{ $isPerish ? 'border-rose-400 bg-rose-50/50' : 'border-slate-200 hover:bg-slate-50' }}" id="label-type-perishable">
                                <input
                                    type="radio"
                                    name="item_type"
                                    value="perishable"
                                    onchange="handleItemTypeChange('perishable')"
                                    class="text-rose-600 focus:ring-rose-500"
                                    {{ $isPerish ? 'checked' : '' }}
                                >
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-slate-800">Perishable</p>
                                    <p class="text-[10px] text-slate-500 leading-tight">Consumable floral</p>
                                </div>
                            </label>

                            <label class="flex items-center gap-2 p-2.5 border rounded-xl cursor-pointer transition {{ !$isPerish ? 'border-emerald-400 bg-emerald-50/50' : 'border-slate-200 hover:bg-slate-50' }}" id="label-type-non-perishable">
                                <input
                                    type="radio"
                                    name="item_type"
                                    value="non_perishable"
                                    onchange="handleItemTypeChange('non_perishable')"
                                    class="text-emerald-600 focus:ring-brand-500"
                                    {{ !$isPerish ? 'checked' : '' }}
                                >
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-slate-800">Non-Perishable</p>
                                    <p class="text-[10px] text-slate-500 leading-tight">Returnable asset</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="md:col-span-3">
                        <div class="flex items-center justify-between mb-1">
                            <label for="edit_description" class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                Description <span class="text-slate-400 font-normal">(Optional)</span>
                            </label>
                            <span class="text-[10px] text-slate-400" id="descCharCount">{{ strlen($inventoryItem->description ?? '') }}/500</span>
                        </div>
                        <textarea
                            id="edit_description"
                            name="description"
                            rows="3"
                            maxlength="500"
                            oninput="document.getElementById('descCharCount').textContent = this.value.length + '/500'"
                            placeholder="Add item characteristics, color shade, dimensions, or handling instructions..."
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >{{ old('description', $inventoryItem->description) }}</textarea>
                    </div>
                </div>
            </div>

            <!-- TAB 2: PRICING & USABLE LIFE -->
            <div id="tab-stock" class="tab-pane hidden space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-slate-900 mb-1">Pricing & Usable Life Configuration</h3>
                    <p class="text-xs text-slate-500">Configure reorder thresholds, procurement unit cost, and standard usable life durations.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <!-- Reorder Level (min_stock) -->
                    <div>
                        <label for="edit_min_stock" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Reorder Level (Minimum) <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="number"
                            step="any"
                            min="0"
                            id="edit_min_stock"
                            name="min_stock"
                            value="{{ old('min_stock', (float) $inventoryItem->min_stock) }}"
                            required
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                        <p class="text-[11px] text-slate-400 mt-1">Triggers low-stock warning threshold.</p>
                        @error('min_stock') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Unit Cost -->
                    <div>
                        <label for="edit_unit_cost" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Unit Cost (₱) <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            id="edit_unit_cost"
                            name="unit_cost"
                            value="{{ old('unit_cost', (float) $inventoryItem->unit_cost) }}"
                            required
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                        <p class="text-[11px] text-slate-400 mt-1">Cost per single unit.</p>
                        @error('unit_cost') <p class="text-rose-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Usable Life Value + Unit -->
                    <div>
                        <label for="edit_usable_life_value" id="label_usable_life" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            {{ $isPerish ? 'Shelf Life' : 'Usable Life' }}
                        </label>
                        <div class="flex gap-2">
                            <input
                                type="number"
                                min="1"
                                id="edit_usable_life_value"
                                name="usable_life_value"
                                value="{{ old('usable_life_value', $inventoryItem->usable_life_value ?? ($isPerish ? 7 : 3)) }}"
                                class="w-1/2 px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                            >
                            <select
                                id="edit_usable_life_unit"
                                name="usable_life_unit"
                                class="w-1/2 px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                            >
                                @php
                                    $curUnit = old('usable_life_unit', $inventoryItem->usable_life_unit ?? ($isPerish ? 'days' : 'years'));
                                @endphp
                                <option value="days" {{ $curUnit === 'days' ? 'selected' : '' }}>Days</option>
                                <option value="weeks" {{ $curUnit === 'weeks' ? 'selected' : '' }}>Weeks</option>
                                <option value="months" {{ $curUnit === 'months' ? 'selected' : '' }}>Months</option>
                                <option value="years" {{ $curUnit === 'years' ? 'selected' : '' }}>Years</option>
                            </select>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1">Default usable duration applied to future procurement receipts.</p>
                    </div>
                </div>
            </div>

            <!-- TAB 3: SUPPLIER & LOCATION -->
            <div id="tab-supplier" class="tab-pane hidden space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-slate-900 mb-1">Supplier & Storage Location</h3>
                    <p class="text-xs text-slate-500">Procurement source and warehouse storage reference.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label for="edit_supplier_name" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Supplier Name <span class="text-slate-400 font-normal">(Optional)</span>
                        </label>
                        <input
                            type="text"
                            id="edit_supplier_name"
                            name="supplier_name"
                            value="{{ old('supplier_name', $inventoryItem->supplier_name) }}"
                            placeholder="e.g. Blooming Fields PH"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                    </div>

                    <div>
                        <label for="edit_contact_person" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Contact Person <span class="text-slate-400 font-normal">(Optional)</span>
                        </label>
                        <input
                            type="text"
                            id="edit_contact_person"
                            name="supplier_contact_person"
                            value="{{ old('supplier_contact_person', $inventoryItem->supplier_contact_person) }}"
                            placeholder="e.g. Ana Reyes"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                    </div>

                    <div>
                        <label for="edit_contact_number" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Contact Number <span class="text-slate-400 font-normal">(Optional)</span>
                        </label>
                        <input
                            type="text"
                            id="edit_contact_number"
                            name="supplier_contact_number"
                            value="{{ old('supplier_contact_number', $inventoryItem->supplier_contact_number) }}"
                            placeholder="e.g. 0917 123 4567"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                    </div>

                    <div>
                        <label for="edit_storage_location" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Storage Location <span class="text-slate-400 font-normal">(Optional)</span>
                        </label>
                        <input
                            type="text"
                            id="edit_storage_location"
                            name="storage_location"
                            value="{{ old('storage_location', $inventoryItem->storage_location) }}"
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
                        <label for="edit_status" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Item Status <span class="text-rose-500">*</span>
                        </label>
                        <select
                            id="edit_status"
                            name="status"
                            class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500 focus:border-brand-600 shadow-2xs transition"
                        >
                            @php
                                $statusVal = old('status', $inventoryItem->status ?? 'active');
                            @endphp
                            <option value="active" {{ $statusVal === 'active' ? 'selected' : '' }}>● Active — Available for planning</option>
                            <option value="inactive" {{ $statusVal === 'inactive' ? 'selected' : '' }}>○ Inactive — Discontinued / Hidden</option>
                        </select>
                        <p class="text-[11px] text-slate-400 mt-1">Inactive items are archived from future planning without losing historical records.</p>
                    </div>

                    <!-- Tags -->
                    <div>
                        <label for="edit_tags" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                            Tags <span class="text-slate-400 font-normal">(Optional, comma separated)</span>
                        </label>
                        <input
                            type="text"
                            id="edit_tags"
                            name="tags"
                            value="{{ old('tags', $inventoryItem->tags) }}"
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
                                oninput="filterSubstitutes(this.value)"
                                placeholder="Search items by name or category..."
                                class="w-full px-3 py-1.5 border border-slate-200 rounded-lg text-xs font-medium text-slate-700 focus:ring-2 focus:ring-brand-500"
                            >
                        </div>

                        <div class="border border-slate-200 rounded-xl p-3 max-h-48 overflow-y-auto space-y-1.5 bg-slate-50/50">
                            @php
                                $currentSubs = $inventoryItem->substitutes->pluck('id')->toArray();
                                $selectedSubs = (array) old('substitute_ids', $currentSubs);
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
                    <p class="text-xs text-slate-500">Current photo and upload replacement (Optional, max 5MB).</p>
                </div>

                <div class="flex items-start gap-6">
                    <div class="h-28 w-28 rounded-2xl bg-slate-100 border border-slate-200 flex items-center justify-center overflow-hidden shrink-0 shadow-2xs">
                        @if($inventoryItem->image_path)
                            <img src="{{ Storage::url($inventoryItem->image_path) }}" alt="{{ $inventoryItem->name }}" class="h-full w-full object-cover">
                        @else
                            <i class="fa-solid fa-image text-slate-300 text-3xl"></i>
                        @endif
                    </div>

                    <div class="flex-1">
                        <div class="flex justify-center px-6 pt-5 pb-6 border-2 border-slate-200 border-dashed rounded-2xl bg-slate-50/50 hover:bg-slate-100/50 transition relative">
                            <div class="space-y-2 text-center">
                                <i class="fa-solid fa-cloud-arrow-up text-3xl text-slate-400 mb-1"></i>
                                <div class="flex text-xs text-slate-600 justify-center">
                                    <label for="edit_image" class="relative cursor-pointer bg-white rounded-lg font-bold text-emerald-600 hover:text-emerald-700 px-3 py-1.5 border border-slate-200 shadow-2xs transition">
                                        <span>Replace Photo</span>
                                        <input id="edit_image" name="image" type="file" onchange="previewSelectedImage(this)" class="sr-only" accept="image/png, image/jpeg, image/webp">
                                    </label>
                                </div>
                                <p class="text-[11px] text-slate-400">Leave blank to retain current image. PNG, JPG or WEBP up to 5MB.</p>
                            </div>
                        </div>

                        <div id="imagePreviewContainer" class="mt-3 hidden">
                            <p class="text-xs font-bold text-slate-600 mb-1">New Preview:</p>
                            <img id="imagePreviewImg" src="" alt="New Preview" class="h-20 w-20 rounded-xl object-cover border border-slate-200">
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
                        <i class="fa-solid fa-check mr-1.5"></i> Save Changes
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- ADJUST STOCK MODAL -->
    <div id="adjustStockModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4" role="dialog" aria-modal="true">
        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl border border-slate-100 overflow-hidden transform transition-all">
            <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm font-bold">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">Adjust Stock</h3>
                        <p class="text-[11px] text-slate-400">{{ $inventoryItem->name }}</p>
                    </div>
                </div>
                <button type="button" onclick="closeAdjustModal()" class="w-7 h-7 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 flex items-center justify-center transition">
                    <i class="fa-solid fa-xmark text-xs"></i>
                </button>
            </div>

            <form action="{{ route('admin.inventory.adjust-stock', $inventoryItem) }}" method="POST" class="p-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Current On Hand</label>
                    <p class="text-lg font-black text-slate-800">{{ (float) $inventoryItem->current_stock }} {{ $inventoryItem->unit }}</p>
                </div>

                <div>
                    <label for="new_stock_input" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                        New Count / Quantity <span class="text-rose-500">*</span>
                    </label>
                    <input
                        type="number"
                        step="any"
                        min="0"
                        id="new_stock_input"
                        name="new_stock"
                        required
                        placeholder="{{ (float) $inventoryItem->current_stock }}"
                        class="w-full px-3.5 py-2.5 border border-slate-200 rounded-xl text-xs font-bold text-slate-800 focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div>
                    <label for="adjust_reason_input" class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
                        Reason / Notes <span class="text-rose-500">*</span>
                    </label>
                    <textarea
                        id="adjust_reason_input"
                        name="reason"
                        rows="2"
                        required
                        placeholder="e.g. Physical inventory cycle count correction"
                        class="w-full px-3.5 py-2 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:ring-2 focus:ring-brand-500"
                    ></textarea>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" onclick="closeAdjustModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-brand-700 hover:bg-brand-800 text-white font-bold text-xs rounded-xl shadow-xs transition">
                        Confirm Adjustment
                    </button>
                </div>
            </form>
        </div>
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
            const labelUsable = document.getElementById('label_usable_life');
            const pLabel = document.getElementById('label-type-perishable');
            const npLabel = document.getElementById('label-type-non-perishable');

            if (isPerishable) {
                pLabel.className = 'flex items-center gap-2 p-2.5 border rounded-xl cursor-pointer transition border-rose-400 bg-rose-50/50';
                npLabel.className = 'flex items-center gap-2 p-2.5 border rounded-xl cursor-pointer transition border-slate-200 hover:bg-slate-50';
                labelUsable.textContent = 'Shelf Life';
            } else {
                pLabel.className = 'flex items-center gap-2 p-2.5 border rounded-xl cursor-pointer transition border-slate-200 hover:bg-slate-50';
                npLabel.className = 'flex items-center gap-2 p-2.5 border rounded-xl cursor-pointer transition border-emerald-400 bg-emerald-50/50';
                labelUsable.textContent = 'Usable Life';
            }
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

        function openAdjustModal() {
            document.getElementById('adjustStockModal').classList.remove('hidden');
        }

        function closeAdjustModal() {
            document.getElementById('adjustStockModal').classList.add('hidden');
        }
    </script>
</x-admin-layout>
