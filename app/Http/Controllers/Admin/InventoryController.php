<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\AdminAlert;
use App\Models\InventoryTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    /**
     * Display the inventory management dashboard with calculated shortages.
     */
    public function index(Request $request): View
    {
        // Get the requested category filter
        $category = $request->query('category', 'all');
        $status = $request->query('status', 'all');
        $stockLevel = $request->query('stock_level', 'all');
        $perishable = $request->query('perishable', 'all');
        $sort = $request->query('sort', 'default');
        $search = $request->query('search');

        // Fetch all inventory items with substitutes, packages, and transactions
        $query = InventoryItem::with([
            'substitutes',
            'packages',
            'inventoryTransactions' => function ($q) {
                $q->with(['booking', 'performedByUser'])->latest();
            }
        ])->withBookedDemand(); // "Reserved" here is booked demand (InventoryItem::scopeWithBookedDemand)
        
        if ($category !== 'all') {
            $query->where('category', $category);
        }
        
        if ($perishable !== 'all') {
            $query->where('is_perishable', $perishable === 'yes');
        }
        
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('item_code', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
        }
        
        // Stock status filtering: handle both stock_level and status query parameters
        $effectiveStock = $stockLevel !== 'all' ? $stockLevel : ($status !== 'all' && in_array($status, ['in_stock', 'low', 'shortage']) ? $status : null);

        if ($effectiveStock || ($status !== 'all')) {
            $query->groupBy('inventory_items.id');
        }

        if ($effectiveStock === 'in_stock') {
            $query->havingRaw('(current_stock - COALESCE(reserved_stock, 0)) > min_stock');
        } elseif ($effectiveStock === 'low') {
            $query->havingRaw('(current_stock - COALESCE(reserved_stock, 0)) <= min_stock AND (current_stock - COALESCE(reserved_stock, 0)) >= 0');
        } elseif ($effectiveStock === 'shortage') {
            $query->havingRaw('(current_stock - COALESCE(reserved_stock, 0)) < 0');
        }

        // Apply sort
        if ($sort === 'name_asc') {
            $query->orderBy('name', 'asc');
        } elseif ($sort === 'name_desc') {
            $query->orderBy('name', 'desc');
        } elseif ($sort === 'stock_desc') {
            $query->orderBy('current_stock', 'desc');
        } elseif ($sort === 'stock_asc') {
            $query->orderBy('current_stock', 'asc');
        } elseif ($sort === 'category_asc') {
            $query->orderBy('category', 'asc')->orderBy('name', 'asc');
        } else {
            // Default smart shortage-priority sort
            $query->orderByRaw('CASE WHEN (current_stock - COALESCE(reserved_stock, 0)) < 0 THEN 2 WHEN (current_stock - COALESCE(reserved_stock, 0)) <= min_stock THEN 1 ELSE 0 END DESC')
                  ->orderByRaw('CASE WHEN (current_stock - COALESCE(reserved_stock, 0)) <= min_stock THEN min_stock - (current_stock - COALESCE(reserved_stock, 0)) ELSE 0 END DESC')
                  ->orderBy('name');
        }
              
        // Use subquery count to correctly handle HAVING clauses on aliases with pagination
        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage() ?: 1;
        $perPage = 10;
        $total = \Illuminate\Support\Facades\DB::query()->fromSub(clone $query, 'sub')->count();
        $items = $query->forPage($page, $perPage)->get();
        $inventoryItems = new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(), 'query' => $request->query()]
        );

        // Get unique categories for the filter dropdown
        $categories = InventoryItem::select('category')->distinct()->whereNotNull('category')->pluck('category');

        // Compute system-wide stats for cards (unfiltered)
        $allStatsItems = InventoryItem::withBookedDemand()->get();

        $totalItemsCount = $allStatsItems->count();
        $inStockCount = 0;
        $lowStockCount = 0;
        $shortageCount = 0;

        foreach ($allStatsItems as $statsItem) {
            $reserved = (float) ($statsItem->reserved_stock ?? 0);
            $net = $statsItem->current_stock - $reserved;
            if ($net < 0) {
                $shortageCount++;
            } elseif ($net <= $statsItem->min_stock) {
                $lowStockCount++;
            } else {
                $inStockCount++;
            }
        }

        foreach ($inventoryItems as $item) {
            $item->reserved_stock = (float) ($item->reserved_stock ?? 0);
            $item->net_available = $item->current_stock - $item->reserved_stock;
            $item->to_procure = max(0.0, $item->reserved_stock - (float) $item->current_stock);
            
            // Determine warning status for UI display
            if ($item->net_available < 0) {
                $item->warning_level = 'shortage'; // Red
            } elseif ($item->net_available <= $item->min_stock) {
                $item->warning_level = 'low'; // Yellow
            } else {
                $item->warning_level = 'ok'; // Green
            }
        }

        return view('admin.inventory.index', [
            'inventoryItems' => $inventoryItems,
            'categories' => $categories,
            'currentCategory' => $category,
            'currentStatus' => $status,
            'currentStockLevel' => $stockLevel,
            'currentPerishable' => $perishable,
            'currentSort' => $sort,
            'totalItemsCount' => $totalItemsCount,
            'inStockCount' => $inStockCount,
            'lowStockCount' => $lowStockCount,
            'shortageCount' => $shortageCount,
        ]);
    }

    /**
     * Show the form for creating a new inventory item.
     */
    public function create(): View
    {
        $inventoryItems = InventoryItem::orderBy('name')->get();
        $inventoryCategories = InventoryItem::select('category')->distinct()->whereNotNull('category')->pluck('category');
        return view('admin.inventory.create', [
            'inventoryItems' => $inventoryItems,
            'inventoryCategories' => $inventoryCategories,
        ]);
    }

    /**
     * Store a newly created inventory item.
     */
    public function store(Request $request)
    {
        abort_unless(Auth::check() && Auth::user()->role === 'admin', 403, 'Unauthorized.');

        $isPerishable = $request->input('item_type') === 'perishable' 
            || $request->boolean('is_perishable') 
            || $request->input('item_type') === '1';

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:255',
            'unit' => 'required|string|max:50',
            'unit_cost' => 'required|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            'reorder_level' => 'nullable|numeric|min:0',
            'current_stock' => 'nullable|numeric|min:0',
            'initial_quantity' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive',
            'description' => 'nullable|string|max:1000',
            'received_date' => 'required|date',
            'usable_life_value' => 'required|integer|min:1',
            'usable_life_unit' => 'required|in:days,weeks,months,years',
            'supplier_name' => 'nullable|string|max:255',
            'supplier_contact_person' => 'nullable|string|max:255',
            'supplier_contact_number' => 'nullable|string|max:50',
            'storage_location' => 'nullable|string|max:255',
            'tags' => 'nullable|string|max:500',
            'confirm_distinct_catalog_item' => 'nullable|boolean',
            'substitute_ids' => 'nullable|array',
            'substitute_ids.*' => 'exists:inventory_items,id',
            'image' => 'nullable|image|max:5120',
        ]);

        if ($duplicate = $this->duplicateNameMessage($validated['name'])) {
            return back()->withInput()->withErrors(['name' => $duplicate]);
        }
        if (($possibleMatches = InventoryItem::catalogSimilarityCandidates($validated['name']))->isNotEmpty()
            && !$request->boolean('confirm_distinct_catalog_item')) {
            return back()->withInput()->withErrors([
                'name' => 'Possible catalog matches: ' . $possibleMatches->pluck('name')->implode(', ')
                    . '. Review these items, then confirm below only if this is a genuinely different item.',
            ]);
        }

        $initialQuantity = (float) ($validated['initial_quantity'] ?? $validated['current_stock'] ?? 0);
        $minStock = (float) ($validated['reorder_level'] ?? $validated['min_stock'] ?? 0);

        // Discrete units whole number check
        $discreteUnits = [
            'pcs', 'piece', 'pieces', 'stem', 'stems', 'block', 'blocks',
            'bunch', 'bunches', 'unit', 'units', 'set', 'sets', 'box', 'boxes',
            'roll', 'rolls', 'tray', 'trays', 'vase', 'vases', 'pot', 'pots'
        ];
        $unitLower = strtolower(trim($validated['unit']));
        if (in_array($unitLower, $discreteUnits, true)) {
            if (floor($initialQuantity) != $initialQuantity) {
                return back()->withErrors([
                    'initial_quantity' => "Initial quantity for '{$validated['unit']}' must be a whole number."
                ])->withInput();
            }
        }

        $refDate = Carbon::parse($validated['received_date']);
        $lifeVal = (int) $validated['usable_life_value'];
        $lifeUnit = strtolower($validated['usable_life_unit']);
        $usableUntil = match($lifeUnit) {
            'days' => $refDate->copy()->addDays($lifeVal),
            'weeks' => $refDate->copy()->addWeeks($lifeVal),
            'months' => $refDate->copy()->addMonths($lifeVal),
            'years' => $refDate->copy()->addYears($lifeVal),
        };

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('inventory-images', 'public');
        }

        $item = DB::transaction(function () use ($validated, $isPerishable, $initialQuantity, $minStock, $refDate, $lifeVal, $lifeUnit, $usableUntil, $imagePath) {
            // Authoritative server-side unique item_code generation
            $catClean = preg_replace('/[^A-Za-z]/', '', $validated['category'] ?? '');
            $prefix = strtoupper(substr($catClean ?: 'INV', 0, 3));
            if (strlen($prefix) < 2) {
                $prefix = 'INV';
            }
            $nextId = (InventoryItem::max('id') ?? 0) + 1;
            $code = $prefix . '-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
            $seq = 1;
            while (InventoryItem::where('item_code', $code)->exists()) {
                $code = $prefix . '-' . str_pad($nextId + ($seq++), 4, '0', STR_PAD_LEFT);
            }

            $item = InventoryItem::create([
                'name' => $validated['name'],
                'item_code' => $code,
                'category' => $validated['category'],
                'unit' => $validated['unit'],
                'is_perishable' => $isPerishable,
                'current_stock' => $initialQuantity,
                'unit_cost' => $validated['unit_cost'],
                'min_stock' => $minStock,
                'status' => $validated['status'] ?? 'active',
                'description' => $validated['description'] ?? null,
                'usable_life_value' => $lifeVal,
                'usable_life_unit' => $lifeUnit,
                'supplier_name' => $validated['supplier_name'] ?? null,
                'supplier_contact_person' => $validated['supplier_contact_person'] ?? null,
                'supplier_contact_number' => $validated['supplier_contact_number'] ?? null,
                'storage_location' => $validated['storage_location'] ?? null,
                'tags' => $validated['tags'] ?? null,
                'image_path' => $imagePath,
            ]);

            if ($imagePath) {
                \App\Models\InventoryItemImage::create([
                    'inventory_item_id' => $item->id,
                    'image_path' => $imagePath,
                    'is_primary' => true,
                ]);
            }

            if ($initialQuantity > 0) {
                $stock = \App\Models\InventoryStock::create([
                    'inventory_item_id' => $item->id,
                    'received_date' => $refDate->toDateString(),
                    'quantity_received' => $initialQuantity,
                    'quantity_remaining' => $initialQuantity,
                    'usable_life_value' => $lifeVal,
                    'usable_life_unit' => $lifeUnit,
                    'usable_until' => $usableUntil->toDateString(),
                    'unit_cost' => $item->unit_cost,
                    'created_by' => Auth::id(),
                ]);

                \App\Models\InventoryTransaction::create([
                    'inventory_item_id' => $item->id,
                    'inventory_stock_id' => $stock->id,
                    'booking_id' => null,
                    'quantity_change' => $initialQuantity,
                    'transaction_type' => 'procurement',
                    'reason' => 'Initial stock on creation',
                    'performed_by' => Auth::id(),
                ]);
            }

            if (!empty($validated['substitute_ids'])) {
                $item->substitutes()->sync($validated['substitute_ids']);
            }

            AuditLog::record(
                Auth::id(),
                'inventory_item_created',
                "Created inventory item '{$item->name}' ({$item->item_code}) with initial stock {$initialQuantity} {$item->unit}.",
                'inventory',
                [
                    'item_id' => $item->id,
                    'item_code' => $item->item_code,
                    'name' => $item->name,
                    'is_perishable' => $item->is_perishable,
                    'initial_stock' => $initialQuantity,
                    'usable_until' => $usableUntil->toDateString(),
                ]
            );

            return $item;
        });

        return redirect()->route('admin.inventory.index')->with('success', 'Inventory item added successfully.');
    }

    /**
     * Show the form for editing the specified inventory item.
     */
    public function edit(InventoryItem $inventoryItem): View
    {
        $inventoryItems = InventoryItem::where('id', '!=', $inventoryItem->id)->orderBy('name')->get();
        $inventoryCategories = InventoryItem::select('category')->distinct()->whereNotNull('category')->pluck('category');
        
        return view('admin.inventory.edit', [
            'inventoryItem' => $inventoryItem,
            'inventoryItems' => $inventoryItems,
            'inventoryCategories' => $inventoryCategories,
        ]);
    }

    /**
     * Update metadata for the specified inventory item.
     * Note: current_stock is NEVER overwritten via normal edit.
     */
    public function update(Request $request, InventoryItem $inventoryItem)
    {
        abort_unless(Auth::check() && Auth::user()->role === 'admin', 403, 'Unauthorized.');

        $isPerishable = $request->has('item_type')
            ? ($request->input('item_type') === 'perishable')
            : ($request->has('is_perishable') ? $request->boolean('is_perishable') : $inventoryItem->is_perishable);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:255',
            'unit' => 'required|string|max:50',
            'unit_cost' => 'required|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            'reorder_level' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive',
            'description' => 'nullable|string|max:1000',
            'usable_life_value' => 'nullable|integer|min:1',
            'usable_life_unit' => 'nullable|in:days,weeks,months,years',
            'supplier_name' => 'nullable|string|max:255',
            'supplier_contact_person' => 'nullable|string|max:255',
            'supplier_contact_number' => 'nullable|string|max:50',
            'storage_location' => 'nullable|string|max:255',
            'tags' => 'nullable|string|max:500',
            'confirm_distinct_catalog_item' => 'nullable|boolean',
            'substitute_ids' => 'nullable|array',
            'substitute_ids.*' => 'exists:inventory_items,id',
            'image' => 'nullable|image|max:5120',
        ]);

        // Renaming must not create a duplicate (an unchanged name is always allowed).
        if (InventoryItem::nameKey($validated['name']) !== InventoryItem::nameKey($inventoryItem->name)
            && ($duplicate = $this->duplicateNameMessage($validated['name'], $inventoryItem->id))) {
            return back()->withInput()->withErrors(['name' => $duplicate]);
        }
        if (InventoryItem::nameKey($validated['name']) !== InventoryItem::nameKey($inventoryItem->name)
            && ($possibleMatches = InventoryItem::catalogSimilarityCandidates($validated['name'], $inventoryItem->id))->isNotEmpty()
            && !$request->boolean('confirm_distinct_catalog_item')) {
            return back()->withInput()->withErrors([
                'name' => 'Possible catalog matches: ' . $possibleMatches->pluck('name')->implode(', ')
                    . '. Review these items, then confirm below only if this is a genuinely different item.',
            ]);
        }

        $minStock = (float) ($validated['reorder_level'] ?? $validated['min_stock'] ?? $inventoryItem->min_stock);

        $imagePath = $inventoryItem->image_path;
        if ($request->hasFile('image')) {
            if ($inventoryItem->image_path && \Storage::disk('public')->exists($inventoryItem->image_path)) {
                \Storage::disk('public')->delete($inventoryItem->image_path);
            }
            $imagePath = $request->file('image')->store('inventory-images', 'public');
            
            \App\Models\InventoryItemImage::create([
                'inventory_item_id' => $inventoryItem->id,
                'image_path' => $imagePath,
                'is_primary' => true,
            ]);
        }

        DB::transaction(function () use ($inventoryItem, $validated, $isPerishable, $minStock, $imagePath) {
            // CRITICAL: current_stock and item_code are NEVER overwritten during normal edit
            $inventoryItem->update([
                'name' => $validated['name'],
                'category' => $validated['category'],
                'unit' => $validated['unit'],
                'is_perishable' => $isPerishable,
                'unit_cost' => $validated['unit_cost'],
                'min_stock' => $minStock,
                'status' => $validated['status'] ?? $inventoryItem->status ?? 'active',
                'description' => $validated['description'] ?? null,
                'usable_life_value' => $validated['usable_life_value'] ?? $inventoryItem->usable_life_value,
                'usable_life_unit' => $validated['usable_life_unit'] ?? $inventoryItem->usable_life_unit,
                'supplier_name' => $validated['supplier_name'] ?? null,
                'supplier_contact_person' => $validated['supplier_contact_person'] ?? null,
                'supplier_contact_number' => $validated['supplier_contact_number'] ?? null,
                'storage_location' => $validated['storage_location'] ?? null,
                'tags' => $validated['tags'] ?? null,
                'image_path' => $imagePath,
            ]);

            $inventoryItem->substitutes()->sync($validated['substitute_ids'] ?? []);

            AuditLog::record(
                Auth::id(),
                'inventory_item_updated',
                "Updated metadata for inventory item '{$inventoryItem->name}' ({$inventoryItem->item_code}).",
                'inventory',
                [
                    'item_id' => $inventoryItem->id,
                    'item_code' => $inventoryItem->item_code,
                    'name' => $inventoryItem->name,
                    'status' => $inventoryItem->status,
                    'current_stock' => $inventoryItem->current_stock, // Preserved!
                ]
            );
        });

        return redirect()->route('admin.inventory.index')->with('success', 'Inventory item updated successfully.');
    }

    /**
     * Explicit stock adjustment action.
     */
    public function adjustStock(Request $request, InventoryItem $inventoryItem)
    {
        abort_unless(Auth::check() && Auth::user()->role === 'admin', 403, 'Unauthorized.');

        $validated = $request->validate([
            'new_stock' => 'required|numeric|min:0',
            'reason' => 'required|string|max:500',
        ]);

        $newStock = (float) $validated['new_stock'];

        // Enforce discrete unit whole number rule
        $discreteUnits = [
            'pcs', 'piece', 'pieces', 'stem', 'stems', 'block', 'blocks',
            'bunch', 'bunches', 'unit', 'units', 'set', 'sets', 'box', 'boxes',
            'roll', 'rolls', 'tray', 'trays', 'vase', 'vases', 'pot', 'pots'
        ];
        $unitLower = strtolower(trim($inventoryItem->unit ?? 'pcs'));
        if (in_array($unitLower, $discreteUnits, true)) {
            if (floor($newStock) != $newStock) {
                return back()->withErrors([
                    'new_stock' => "Stock quantity for '{$inventoryItem->unit}' must be a whole number."
                ])->withInput();
            }
        }

        DB::transaction(function () use ($inventoryItem, $newStock, $validated) {
            $locked = InventoryItem::where('id', $inventoryItem->id)->lockForUpdate()->firstOrFail();
            $oldStock = (float) $locked->current_stock;
            $diff = $newStock - $oldStock;

            if ($diff != 0) {
                $locked->current_stock = $newStock;
                $locked->save();

                InventoryTransaction::create([
                    'inventory_item_id' => $locked->id,
                    'booking_id' => null,
                    'quantity_change' => $diff,
                    'transaction_type' => 'adjustment',
                    'reason' => $validated['reason'],
                    'performed_by' => Auth::id(),
                ]);

                AuditLog::record(
                    Auth::id(),
                    'inventory_stock_adjusted',
                    "Stock for '{$locked->name}' adjusted from {$oldStock} to {$newStock} {$locked->unit}. Reason: {$validated['reason']}",
                    'inventory',
                    [
                        'item_id' => $locked->id,
                        'previous_stock' => $oldStock,
                        'new_stock' => $newStock,
                        'difference' => $diff,
                        'reason' => $validated['reason'],
                    ]
                );
            }
        });

        return redirect()->route('admin.inventory.index')->with('success', 'Stock adjusted successfully.');
    }

    /**
     * Record physical stock received / procured for an inventory item.
     */
    public function receiveStock(Request $request, InventoryItem $inventoryItem)
    {
        abort_unless(Auth::check() && Auth::user()->role === 'admin', 403, 'Unauthorized.');

        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $quantity = (float) $validated['quantity'];

        if ($quantity <= 0) {
            return back()->withErrors(['quantity' => 'The quantity received must be greater than 0.'])->withInput();
        }

        // Enforce discrete unit whole number rule
        $discreteUnits = [
            'pcs', 'piece', 'pieces', 'stem', 'stems', 'block', 'blocks',
            'bunch', 'bunches', 'unit', 'units', 'set', 'sets', 'box', 'boxes',
            'roll', 'rolls', 'tray', 'trays', 'vase', 'vases', 'pot', 'pots'
        ];
        $unitLower = strtolower(trim($inventoryItem->unit ?? 'pcs'));
        if (in_array($unitLower, $discreteUnits, true)) {
            if (floor($quantity) != $quantity) {
                return back()->withErrors([
                    'quantity' => "The quantity received must be a whole number for unit '{$inventoryItem->unit}'."
                ])->withInput();
            }
        }

        $unitCost = isset($validated['unit_cost']) && $validated['unit_cost'] !== null && $validated['unit_cost'] !== ''
            ? (float) $validated['unit_cost']
            : null;
        $notes = !empty($validated['notes']) ? trim($validated['notes']) : null;

        try {
            DB::transaction(function () use ($inventoryItem, $quantity, $unitCost, $notes) {
                $lockedItem = InventoryItem::query()->lockForUpdate()->findOrFail($inventoryItem->id);
                $adminId = Auth::id() ?? auth()->id();

                $oldStock = (float) $lockedItem->current_stock;
                $newStock = $oldStock + $quantity;

                $lifeVal = (int) ($lockedItem->usable_life_value ?? ($lockedItem->is_perishable ? 7 : 3));
                $lifeUnit = strtolower($lockedItem->usable_life_unit ?? ($lockedItem->is_perishable ? 'days' : 'years'));
                $usableUntil = match($lifeUnit) {
                    'days' => Carbon::today()->addDays($lifeVal),
                    'weeks' => Carbon::today()->addWeeks($lifeVal),
                    'months' => Carbon::today()->addMonths($lifeVal),
                    'years' => Carbon::today()->addYears($lifeVal),
                    default => Carbon::today()->addDays(7),
                };

                $stockBatch = \App\Models\InventoryStock::create([
                    'inventory_item_id' => $lockedItem->id,
                    'received_date' => Carbon::today()->toDateString(),
                    'quantity_received' => $quantity,
                    'quantity_remaining' => $quantity,
                    'usable_life_value' => $lifeVal,
                    'usable_life_unit' => $lifeUnit,
                    'usable_until' => $usableUntil->toDateString(),
                    'unit_cost' => $unitCost ?? $lockedItem->unit_cost,
                    'created_by' => $adminId,
                ]);

                // 1. Traceable inventory transaction
                InventoryTransaction::create([
                    'inventory_item_id' => $lockedItem->id,
                    'inventory_stock_id' => $stockBatch->id,
                    'booking_id' => null,
                    'quantity_change' => $quantity,
                    'transaction_type' => 'procurement',
                    'reason' => $notes ?: 'Procurement receipt',
                    'performed_by' => $adminId,
                ]);

                // 2. Increase on-hand stock (and update unit cost if specified and positive)
                $updateData = ['current_stock' => $newStock];
                if ($unitCost !== null && $unitCost > 0) {
                    $updateData['unit_cost'] = $unitCost;
                }
                $lockedItem->update($updateData);

                // 3. Traceable audit log
                $refText = $notes ? " (Reference: {$notes})" : '';
                AuditLog::record(
                    $adminId,
                    'inventory_stock_received',
                    "Received {$quantity} {$lockedItem->unit} of {$lockedItem->name}{$refText}",
                    'inventory',
                    [
                        'inventory_item_id' => $lockedItem->id,
                        'item_name' => $lockedItem->name,
                        'quantity_received' => $quantity,
                        'unit' => $lockedItem->unit,
                        'unit_cost' => $unitCost,
                        'reference' => $notes,
                        'old_stock' => $oldStock,
                        'new_stock' => $newStock,
                    ]
                );
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Receive stock transaction failed: ' . $e->getMessage());

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to receive stock: ' . $e->getMessage(),
                ], 500);
            }

            return back()->withErrors(['receive_stock' => 'Failed to record stock receipt. Please try again.'])->withInput();
        }

        if ($request->expectsJson() || $request->ajax()) {
            $freshItem = $inventoryItem->fresh();
            return response()->json([
                'success' => true,
                'message' => 'Stock received successfully.',
                'current_stock' => (float) $freshItem->current_stock,
                'reserved_stock' => (float) $freshItem->reserved_stock,
                'to_procure' => (float) $freshItem->to_procure,
            ]);
        }

        return redirect()->route('admin.inventory.index')->with('success', 'Stock received successfully.');
    }

    public function archive(InventoryItem $inventoryItem)
    {
        // Check if there are active bookings using this item to prevent breaking historical data
        $activeBookingsCount = \App\Models\Booking::whereDate('event_date', '>=', Carbon::today())
            ->whereNotIn('status', ['cancelled', 'completed', 'declined'])
            ->whereHas('inventoryItems', function($query) use ($inventoryItem) {
                $query->where('inventory_items.id', $inventoryItem->id);
            })->count();

        if ($activeBookingsCount > 0) {
            return redirect()->route('admin.inventory.index')->with('error', 'Cannot archive item: it is reserved for upcoming active bookings.');
        }

        $inventoryItem->delete();

        return redirect()->route('admin.inventory.index')->with('success', 'Inventory item archived successfully.');
    }

    public function archived(): View
    {
        $inventoryItems = InventoryItem::onlyTrashed()->orderBy('deleted_at', 'desc')->get();
        return view('admin.inventory.archived', [
            'inventoryItems' => $inventoryItems,
        ]);
    }

    public function restore($id)
    {
        $item = InventoryItem::withTrashed()->findOrFail($id);
        $item->restore();
        return redirect()->route('admin.inventory.archived')->with('success', 'Inventory item restored successfully.');
    }

    /**
     * Resolve inventory shortage by restocking an item directly from notifications.
     */
    public function resolveShortage(Request $request, InventoryItem $inventoryItem)
    {
        $validated = $request->validate([
            'additional_stock' => ['required', 'numeric', 'min:0'],
            'alert_id' => ['nullable', 'integer', 'exists:admin_alerts,id'],
        ]);

        $stockToAdd = (float) $validated['additional_stock'];
        $wasRestocked = false;
        $isDuplicate = false;

        if ($stockToAdd > 0) {
            try {
                DB::transaction(function () use ($inventoryItem, $stockToAdd, $validated, &$wasRestocked, &$isDuplicate) {
                    $lockedItem = InventoryItem::query()->lockForUpdate()->findOrFail($inventoryItem->id);

                    $performedBy = Auth::id() ?? auth()->id();

                    $isDuplicate = InventoryTransaction::where('inventory_item_id', $lockedItem->id)
                        ->whereNull('booking_id')
                        ->where('transaction_type', 'procurement')
                        ->where('quantity_change', $stockToAdd)
                        ->where('performed_by', $performedBy)
                        ->where('created_at', '>=', now()->subSeconds(10))
                        ->exists();

                    if ($isDuplicate) {
                        return;
                    }

                    $lockedItem->increment('current_stock', $stockToAdd);

                    InventoryTransaction::create([
                        'inventory_item_id' => $lockedItem->id,
                        'booking_id' => null,
                        'quantity_change' => $stockToAdd,
                        'transaction_type' => 'procurement',
                        'reason' => 'Admin inline restock',
                        'performed_by' => $performedBy,
                    ]);

                    if (!empty($validated['alert_id'])) {
                        // A restock never closes a request that waits for an approve/reject decision.
                        AdminAlert::where('id', $validated['alert_id'])
                            ->where('is_read', false)
                            ->whereNotIn('type', AdminAlert::DECISION_TYPES)
                            ->update(['is_read' => true]);
                    }

                    $wasRestocked = true;
                });
            } catch (\Throwable $e) {
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to restock inventory: ' . $e->getMessage(),
                    ], 500);
                }

                return redirect()->back()->with('error', 'Failed to restock inventory: ' . $e->getMessage());
            }
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $isDuplicate ? 'Shortage resolution already processed.' : 'Inventory restocked successfully.',
                'current_stock' => (float) $inventoryItem->fresh()->current_stock,
                'is_duplicate' => $isDuplicate,
            ]);
        }

        return redirect()->route('admin.notifications')->with('success', 'Inventory restocked successfully.');
    }

    /**
     * Download the standard CSV template for inventory imports.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="inventory_template.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['name', 'category', 'is_perishable', 'current_stock', 'unit_cost', 'min_stock', 'unit']);
            fputcsv($handle, ['Red Roses', 'Flowers', 1, 200, '15.00', 50, 'stems']);
            fputcsv($handle, ['White Roses', 'Flowers', 1, 150, '18.00', 50, 'stems']);
            fputcsv($handle, ['Glass Vases (Tall)', 'Props', 0, 30, '250.00', 5, 'pcs']);
            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Export the current inventory items as a CSV file matching the import format.
     */
    public function exportCsv(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="inventory_export_' . now()->format('Y-m-d') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $items = InventoryItem::orderBy('name')->get();

        return response()->stream(function () use ($items) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['name', 'category', 'is_perishable', 'current_stock', 'unit_cost', 'min_stock', 'unit']);

            foreach ($items as $item) {
                $name = (string) $item->name;
                if (preg_match('/^[=\+\-@]/', $name)) {
                    $name = "'" . $name;
                }

                $category = (string) $item->category;
                if (preg_match('/^[=\+\-@]/', $category)) {
                    $category = "'" . $category;
                }

                $unit = (string) $item->unit;
                if (preg_match('/^[=\+\-@]/', $unit)) {
                    $unit = "'" . $unit;
                }

                fputcsv($handle, [
                    $name,
                    $category,
                    $item->is_perishable ? 1 : 0,
                    (float) $item->current_stock,
                    number_format((float) $item->unit_cost, 2, '.', ''),
                    (float) $item->min_stock,
                    $unit,
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Import inventory items from an uploaded CSV file with transactional upsert.
     */
    /**
     * Why $name would duplicate an existing catalog item, or null. Names are compared ignoring
     * case, spacing and punctuation, and singular/plural (InventoryItem::catalogMatches).
     */
    protected function duplicateNameMessage(string $name, ?int $ignoreId = null): ?string
    {
        ['exact' => $exact, 'similar' => $similar] = InventoryItem::catalogMatches($name, $ignoreId);

        if ($exact) {
            return $exact->trashed()
                ? "\"{$exact->name}\" is in Archived Items. Restore it instead of adding it again."
                : "\"{$exact->name}\"" . ($exact->item_code ? " ({$exact->item_code})" : '') . ' is already in inventory. Open it and use Receive Stock to add stock instead of adding it again.';
        }

        return $similar
            ? "A similar item \"{$similar->name}\"" . ($similar->trashed() ? ' (archived)' : '') . ' is already in inventory. Use it, or give this item a clearly different name.'
            : null;
    }
    public function importCsv(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file',
        ]);

        $file = $request->file('csv_file');

        if (!$file->isValid()) {
            return redirect()->route('admin.inventory.index')
                ->with('error', 'Inventory CSV import failed. No changes were made.')
                ->withErrors(['csv_file' => 'Uploaded file is invalid or corrupted.']);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension !== 'csv') {
            return redirect()->route('admin.inventory.index')
                ->with('error', 'Inventory CSV import failed. No changes were made.')
                ->withErrors(['csv_file' => 'The uploaded file must be a CSV file (.csv).']);
        }

        $filePath = $file->getRealPath();
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return redirect()->route('admin.inventory.index')
                ->with('error', 'Inventory CSV import failed. No changes were made.')
                ->withErrors(['csv_file' => 'Could not read the uploaded CSV file.']);
        }

        // Read header
        $rawHeader = fgetcsv($handle);
        if ($rawHeader === false || empty($rawHeader)) {
            fclose($handle);
            return redirect()->route('admin.inventory.index')
                ->with('error', 'Inventory CSV import failed. No changes were made.')
                ->withErrors(['csv_file' => 'The uploaded CSV file is empty.']);
        }

        // Strip UTF-8 BOM if present
        if (isset($rawHeader[0])) {
            $rawHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rawHeader[0]);
        }

        $header = array_map(fn($col) => trim(strtolower((string) $col)), $rawHeader);
        $expectedHeader = ['name', 'category', 'is_perishable', 'current_stock', 'unit_cost', 'min_stock', 'unit'];

        if ($header !== $expectedHeader) {
            fclose($handle);
            return redirect()->route('admin.inventory.index')
                ->with('error', 'Inventory CSV import failed. No changes were made.')
                ->withErrors(['csv_file' => 'Invalid CSV header. Expected: ' . implode(',', $expectedHeader)]);
        }

        $rows = [];
        $seenNames = [];
        $rowNumber = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (empty($data) || (count($data) === 1 && $data[0] === null)) {
                continue;
            }
            if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }

            if (count($data) !== 7) {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: Row has " . count($data) . " columns, expected 7."]);
            }

            // name
            $name = trim((string) $data[0]);
            if (str_starts_with($name, "'") && strlen($name) > 1 && in_array($name[1], ['=', '+', '-', '@'], true)) {
                $name = substr($name, 1);
            }

            if ($name === '') {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: name is required."]);
            }
            if (mb_strlen($name) > 255) {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: name cannot exceed 255 characters."]);
            }

            // Reject duplicate names within file
            $nameKey = mb_strtolower($name);
            if (isset($seenNames[$nameKey])) {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: Duplicate item name '{$name}' found in the CSV file (first seen on row {$seenNames[$nameKey]})."]);
            }
            $seenNames[$nameKey] = $rowNumber;

            // category
            $category = trim((string) $data[1]);
            if (str_starts_with($category, "'") && strlen($category) > 1 && in_array($category[1], ['=', '+', '-', '@'], true)) {
                $category = substr($category, 1);
            }
            if ($category === '') {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: category is required."]);
            }
            if (mb_strlen($category) > 255) {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: category cannot exceed 255 characters."]);
            }

            // is_perishable
            $perishableRaw = strtolower(trim((string) $data[2]));
            if (in_array($perishableRaw, ['1', 'true'], true)) {
                $isPerishable = true;
            } elseif (in_array($perishableRaw, ['0', 'false'], true)) {
                $isPerishable = false;
            } else {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: is_perishable must be 1, 0, true, or false."]);
            }

            // current_stock
            $currentStockRaw = trim((string) $data[3]);
            if ($currentStockRaw === '') {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: current_stock is required."]);
            }
            if (!is_numeric($currentStockRaw)) {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: current_stock must be a number."]);
            }
            if ((float) $currentStockRaw < 0) {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: current_stock cannot be negative."]);
            }
            $currentStock = (float) $currentStockRaw;

            // unit_cost
            $unitCostRaw = trim((string) $data[4]);
            if ($unitCostRaw === '') {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: unit_cost is required."]);
            }
            if (!is_numeric($unitCostRaw)) {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: unit_cost must be a number."]);
            }
            if ((float) $unitCostRaw < 0) {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: unit_cost cannot be negative."]);
            }
            $unitCost = (float) $unitCostRaw;

            // min_stock
            $minStockRaw = trim((string) $data[5]);
            if ($minStockRaw === '') {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: min_stock is required."]);
            }
            if (!is_numeric($minStockRaw)) {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: min_stock must be a number."]);
            }
            if ((float) $minStockRaw < 0) {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: min_stock cannot be negative."]);
            }
            $minStock = (float) $minStockRaw;

            // unit
            $unit = trim((string) $data[6]);
            if (str_starts_with($unit, "'") && strlen($unit) > 1 && in_array($unit[1], ['=', '+', '-', '@'], true)) {
                $unit = substr($unit, 1);
            }
            if ($unit === '') {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: unit is required."]);
            }
            if (mb_strlen($unit) > 50) {
                fclose($handle);
                return redirect()->route('admin.inventory.index')
                    ->with('error', 'Inventory CSV import failed. No changes were made.')
                    ->withErrors(['csv_file' => "Import failed on row {$rowNumber}: unit cannot exceed 50 characters."]);
            }

            $rows[] = [
                'name' => $name,
                'category' => $category,
                'is_perishable' => $isPerishable,
                'current_stock' => $currentStock,
                'unit_cost' => $unitCost,
                'min_stock' => $minStock,
                'unit' => $unit,
            ];
        }

        fclose($handle);

        if (empty($rows)) {
            return redirect()->route('admin.inventory.index')
                ->with('error', 'Inventory CSV import failed. No changes were made.')
                ->withErrors(['csv_file' => 'The uploaded CSV file contains no data rows.']);
        }

        $createdCount = 0;
        $updatedCount = 0;

        try {
            DB::transaction(function () use ($rows, &$createdCount, &$updatedCount) {
                $adminId = Auth::id() ?? auth()->id();

                foreach ($rows as $row) {
                    // Same item when the name matches ignoring case, spacing and punctuation.
                    ['exact' => $item, 'similar' => $similar] = InventoryItem::catalogMatches($row['name'], unit: $row['unit']);
                    if (!$item && $similar) {
                        throw new \DomainException("\"{$row['name']}\" is too similar to the existing item \"{$similar->name}\". Use the existing name to update it, or a clearly different name.");
                    }
                    $possibleMatches = InventoryItem::catalogSimilarityCandidates($row['name']);
                    if (!$item && $possibleMatches->isNotEmpty()) {
                        throw new \DomainException("\"{$row['name']}\" may duplicate: " . $possibleMatches->pluck('name')->implode(', ') . '. Review the catalog and resolve this row before importing; no changes were made.');
                    }

                    if ($item) {
                        if ($item->trashed()) {
                            $item->restore();
                        }

                        $oldStock = (float) $item->current_stock;
                        $item->update([
                            'category' => $row['category'],
                            'is_perishable' => $row['is_perishable'],
                            'current_stock' => $row['current_stock'],
                            'unit_cost' => $row['unit_cost'],
                            'min_stock' => $row['min_stock'],
                            'unit' => $row['unit'],
                        ]);
                        $newStock = (float) $item->current_stock;

                        if ($oldStock !== $newStock) {
                            InventoryTransaction::create([
                                'inventory_item_id' => $item->id,
                                'booking_id' => null,
                                'quantity_change' => $newStock - $oldStock,
                                'transaction_type' => 'adjustment',
                                'reason' => 'Stock update from CSV import',
                                'performed_by' => $adminId,
                            ]);
                        }

                        $updatedCount++;
                    } else {
                        $newItem = InventoryItem::create([
                            'name' => $row['name'],
                            'category' => $row['category'],
                            'is_perishable' => $row['is_perishable'],
                            'current_stock' => $row['current_stock'],
                            'unit_cost' => $row['unit_cost'],
                            'min_stock' => $row['min_stock'],
                            'unit' => $row['unit'],
                        ]);

                        $prefix = strtoupper(substr($newItem->category ?? 'INV', 0, 3));
                        $code = $prefix . '-' . str_pad($newItem->id, 4, '0', STR_PAD_LEFT);
                        $i = 1;
                        while (InventoryItem::where('item_code', $code)->where('id', '!=', $newItem->id)->exists()) {
                            $code = $prefix . '-' . str_pad($newItem->id, 4, '0', STR_PAD_LEFT) . '-' . $i++;
                        }
                        $newItem->update(['item_code' => $code]);

                        if ((float) $newItem->current_stock != 0) {
                            InventoryTransaction::create([
                                'inventory_item_id' => $newItem->id,
                                'booking_id' => null,
                                'quantity_change' => (float) $newItem->current_stock,
                                'transaction_type' => 'adjustment',
                                'reason' => 'Initial stock on creation',
                                'performed_by' => $adminId,
                            ]);
                        }

                        $createdCount++;
                    }
                }

                AuditLog::record(
                    $adminId,
                    'inventory_csv_imported',
                    "Inventory CSV imported: {$createdCount} items created, {$updatedCount} items updated.",
                    'inventory',
                    ['created' => $createdCount, 'updated' => $updatedCount]
                );
            });
        } catch (\DomainException $e) {
            return redirect()->route('admin.inventory.index')
                ->with('error', 'Inventory CSV import failed. No changes were made.')
                ->withErrors(['csv_file' => $e->getMessage()]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Inventory CSV import transaction failed: ' . $e->getMessage());
            return redirect()->route('admin.inventory.index')
                ->with('error', 'Inventory CSV import failed. No changes were made.')
                ->withErrors(['csv_file' => 'Import failed due to a database error.']);
        }

        return redirect()->route('admin.inventory.index')
            ->with('success', "Inventory CSV imported successfully. {$createdCount} items created, {$updatedCount} items updated.");
    }
}
