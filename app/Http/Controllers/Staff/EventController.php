<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\AssetReturn;
use App\Models\Booking;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\ReturnItem;
use App\Models\StaffChecklistItem;
use App\Models\AdminAlert;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EventController extends Controller
{
    public const DEFAULT_CHECKLIST = \App\Services\StaffWorkspaceService::DEFAULT_CHECKLIST;

    public function show(Request $request, Booking $booking, \App\Services\StaffWorkspaceService $workspace): View
    {
        $query = Booking::with(['client', 'bookingItems.inventoryItem']);

        if ($request->user()->role === 'staff') {
            $query->where('staff_id', $request->user()->id);
        }

        $assignedBooking = $query->whereKey($booking->id)->firstOrFail();
        $workspace->ensureChecklist($assignedBooking);
        // Viewing never opens the return record; recording a return does (submitReturn()).
        $assignedBooking->load(['staffChecklistItems', 'returns.returnItems.inventoryItem']);
        
        $dispatchedQuantities = $this->dispatchedQuantities($assignedBooking);
        $bookingMessages = \App\Models\BookingMessage::where('booking_id', $assignedBooking->id)->whereIn('visibility', ['shared', 'admin_staff'])->orderBy('created_at', 'asc')->get();

        // The thread is on screen now, so it no longer counts toward this staff member's unread messages.
        if ($request->user()->role === 'staff') {
            $workspace->markThreadRead($request->user(), $assignedBooking);
        }

        return view('staff.event-show', [
            'booking' => $assignedBooking,
            'returnRecord' => $assignedBooking->returns()->latest()->first(),
            'dispatchedQuantities' => $dispatchedQuantities,
            'bookingMessages' => $bookingMessages,
            'aiMaterials' => $assignedBooking->aiAnalyses()->latest('analyzed_at')->first()?->suggested_materials ?? [],
            'canDispatch' => $workspace->canDispatch($assignedBooking),
            'dispatchLockMessage' => $workspace->dispatchLockMessage($assignedBooking),
        ]);
    }

    public function updateChecklist(Request $request, Booking $booking, StaffChecklistItem $checklist): RedirectResponse
    {
        $this->authorizedBooking($request, $booking);
        $checklist = StaffChecklistItem::where('booking_id', $booking->id)->whereKey($checklist->id)->firstOrFail();

        $validated = $request->validate([
            'is_completed' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $isCompleted = (bool) $validated['is_completed'];
        $checklist->update([
            'is_completed' => $isCompleted,
            'notes' => $validated['notes'] ?? null,
            'completed_by' => $isCompleted ? $request->user()->id : null,
            'completed_at' => $isCompleted ? now() : null,
        ]);

        return back()->with('success', 'Checklist item updated.');
    }

    public function updatePhysicalStock(Request $request, Booking $booking, InventoryItem $inventoryItem): RedirectResponse
    {
        $this->authorizedBooking($request, $booking);
        $bookingItem = $booking->bookingItems()
            ->where('inventory_item_id', $inventoryItem->id)
            ->whereNotNull('confirmed_at')
            ->firstOrFail();

        $validated = $request->validate([
            'observed_stock' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        $observedStock = (float) $validated['observed_stock'];
        $oldStock = (float) $inventoryItem->current_stock;
        $stockDelta = $observedStock - $oldStock;

        DB::transaction(function () use ($request, $booking, $inventoryItem, $bookingItem, $observedStock, $oldStock, $stockDelta): void {
            // Staff records observed physical count without mutating global current_stock (Decision A)
            AdminAlert::updateOrCreate(
                [
                    'type' => 'physical_count_variance',
                    'booking_id' => $booking->id,
                    'inventory_item_id' => $inventoryItem->id,
                    'is_read' => false,
                ],
                [
                    'title' => 'Physical Stock Count Variance: ' . ($bookingItem->item_name ?? $inventoryItem->name),
                    'message' => "Staff {$request->user()->name} recorded an observed physical count of {$observedStock} for {$inventoryItem->name} (system stock: {$oldStock}, variance: " . ($stockDelta >= 0 ? "+{$stockDelta}" : "{$stockDelta}") . ") on Booking #{$booking->id}. Awaiting Admin review and adjustment approval.",
                ]
            );

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'physical_count_observed',
                'module' => 'inventory',
                'details' => "Staff {$request->user()->name} recorded observed count of {$observedStock} (system: {$oldStock}, variance: {$stockDelta}) for item '{$inventoryItem->name}' on Booking #{$booking->id}. Submitted for Admin approval.",
                'entity_type' => InventoryItem::class,
                'entity_id' => $inventoryItem->id,
            ]);
        });

        return back()->with('success', 'Observed physical stock count of ' . $observedStock . ' recorded and submitted for Admin review. Global inventory remains unchanged until Admin approval.');
    }

    public function submitReturn(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizedBooking($request, $booking);
        $returnRecord = $this->ensureReturnRecord($booking);

        if (!$returnRecord) {
            return back()->with('error', 'This event has no confirmed non-perishable materials available for return recording.');
        }

        if ($this->returnAuditIsClosed($booking, $returnRecord)) {
            return back()->with('error', 'This return audit has been completed by Raflora and can no longer be changed.');
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.quantity_returned' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_good' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_damaged' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_lost' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $dispatchedQuantities = $this->dispatchedQuantities($booking);
        $updates = [];
        foreach ($validated['items'] as $returnItemId => $data) {
            $returnItem = ReturnItem::where('return_id', $returnRecord->id)->whereKey($returnItemId)->firstOrFail();
            $dispatchedQuantity = (float) ($dispatchedQuantities[$returnItem->inventory_item_id] ?? 0);

            $hasSplit = array_key_exists('quantity_good', $data)
                || array_key_exists('quantity_damaged', $data)
                || array_key_exists('quantity_lost', $data);

            if ($hasSplit) {
                $qGood = max(0.0, (float) ($data['quantity_good'] ?? 0));
                $qDamaged = max(0.0, (float) ($data['quantity_damaged'] ?? 0));
                $qLost = max(0.0, (float) ($data['quantity_lost'] ?? 0));
                $accounted = $qGood + $qDamaged + $qLost;

                if ($accounted > $dispatchedQuantity) {
                    return back()->withErrors([
                        'items.' . $returnItemId . '.quantity_returned' => 'Returned quantity cannot exceed the dispatched quantity.',
                    ])->withInput();
                }

                $returnedQuantity = $qGood + $qDamaged;
            } else {
                $returnedQuantity = (float) ($data['quantity_returned'] ?? 0);
                if ($returnedQuantity > $dispatchedQuantity) {
                    return back()->withErrors([
                        'items.' . $returnItemId . '.quantity_returned' => 'Returned quantity cannot exceed the dispatched quantity.',
                    ])->withInput();
                }
                $qGood = $returnedQuantity;
                $qDamaged = 0.0;
                $qLost = 0.0;
            }

            $updates[] = [$returnItem, $returnedQuantity, $qGood, $qDamaged, $qLost, $data['notes'] ?? null];
        }

        DB::transaction(function () use ($updates, $returnRecord, $validated, $request, $booking): void {
            foreach ($updates as [$returnItem, $returnedQuantity, $qGood, $qDamaged, $qLost, $notes]) {
                $returnItem->update([
                    'quantity_returned' => $returnedQuantity,
                    'quantity_good' => $qGood,
                    'quantity_damaged' => $qDamaged,
                    'quantity_lost' => $qLost,
                    'notes' => $notes,
                ]);
            }

            $returnRecord->update([
                'status' => 'Partially Returned',
                'assigned_staff_id' => $returnRecord->assigned_staff_id ?? $request->user()->id,
                'notes' => $validated['notes'] ?? $returnRecord->notes,
                'return_date' => now(),
            ]);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'return_quantities_recorded',
                'module' => 'return_tracking',
                'details' => "Physical return quantities recorded by staff {$request->user()->name} for return {$returnRecord->reference} (booking #{$booking->id})",
                'entity_type' => AssetReturn::class,
                'entity_id' => $returnRecord->id,
            ]);
        });

        return back()->with('success', 'Material return recorded for Admin review.');
    }

    public function recordCondition(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizedBooking($request, $booking);
        $returnRecord = $booking->returns()->latest()->first();

        if (!$returnRecord) {
            return back()->with('error', 'Record the material return before recording condition observations.');
        }

        if ($this->returnAuditIsClosed($booking, $returnRecord)) {
            return back()->with('error', 'This return audit has been completed by Raflora and can no longer be changed.');
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.condition' => ['required', 'in:good,damaged,lost,mixed'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
            'items.*.evidence' => ['nullable', 'array'],
            'items.*.evidence.*' => ['image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        foreach ($validated['items'] as $returnItemId => $data) {
            $returnItem = ReturnItem::where('return_id', $returnRecord->id)->whereKey($returnItemId)->firstOrFail();
            $isDamaged = ($data['condition'] === 'damaged' || (float) $returnItem->quantity_damaged > 0);
            if ($isDamaged && (float) $returnItem->quantity_returned <= 0 && (float) $returnItem->quantity_damaged <= 0) {
                return back()->withErrors([
                    'items.' . $returnItemId . '.condition' => 'A damaged observation requires a returned quantity.',
                ])->withInput();
            }
            if ($isDamaged && empty($data['evidence']) && $returnItem->evidences()->count() === 0) {
                return back()->withErrors([
                    'items.' . $returnItemId . '.evidence' => 'Photographic evidence is required for damaged items.',
                ])->withInput();
            }
        }

        DB::transaction(function () use ($validated, $returnRecord, $request, $booking): void {
            $hasDamageOrLost = false;
            foreach ($validated['items'] as $returnItemId => $data) {
                $returnItem = ReturnItem::where('return_id', $returnRecord->id)->whereKey($returnItemId)->first();
                $returnItem->update([
                    'condition' => $data['condition'],
                    'notes' => $data['notes'] ?? null,
                ]);

                if (in_array($data['condition'], ['damaged', 'lost', 'mixed'], true)) {
                    $hasDamageOrLost = true;
                }

                if (!empty($data['evidence'])) {
                    foreach ($request->file('items.' . $returnItemId . '.evidence') as $file) {
                        $path = $file->store('returns/evidence/' . $returnRecord->booking_id, 'local');
                        $returnItem->evidences()->create([
                            'file_path' => $path,
                            'file_name' => $file->getClientOriginalName(),
                            'mime_type' => $file->getClientMimeType(),
                            'size' => $file->getSize(),
                            'uploaded_by' => $request->user()->id,
                        ]);
                    }
                }
            }

            $returnRecord->update([
                'inspected_by' => $returnRecord->inspected_by ?? $request->user()->id,
                'approval_status' => $hasDamageOrLost ? 'pending' : $returnRecord->approval_status,
            ]);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'return_inspection_submitted',
                'module' => 'return_tracking',
                'details' => "Material condition inspection submitted by {$request->user()->name} for return {$returnRecord->reference} (booking #{$booking->id})",
                'entity_type' => AssetReturn::class,
                'entity_id' => $returnRecord->id,
            ]);
        });

        return back()->with('success', 'Condition observations recorded for Admin review.');
    }

    /**
     * A completed return audit (or a completed booking) has already been reconciled into
     * inventory by Admin and must not be reopened from the Staff workspace.
     */
    private function returnAuditIsClosed(Booking $booking, AssetReturn $returnRecord): bool
    {
        return $booking->status === 'completed' || $returnRecord->status === 'Completed';
    }

    private function authorizedBooking(Request $request, Booking $booking): Booking
    {
        if ($request->user()->role === 'staff' && (int) $booking->staff_id !== (int) $request->user()->id) {
            abort(404);
        }

        return $booking;
    }

    private function ensureReturnRecord(Booking $booking): ?AssetReturn
    {
        if (!in_array($booking->status, ['event_completed', 'pending_return', 'pending_resolution', 'completed'], true)) {
            return $booking->returns()->latest()->first();
        }

        return app(\App\Services\ReturnRecordService::class)->openFor($booking)?->load('returnItems.inventoryItem');
    }

    private function dispatchedQuantities(Booking $booking): array
    {
        return app(\App\Services\ReturnRecordService::class)->dispatchedQuantities($booking);
    }



    public function dispatchItems(Request $request, Booking $booking, \App\Services\InventoryDispatchService $dispatchService)
    {
        $this->authorizedBooking($request, $booking);

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'items.*.quantity' => 'required|numeric|min:0',
            'reason' => 'required|string|max:255',
        ]);

        try {
            $dispatchService->dispatchItems($booking, $validated['items'], \Illuminate\Support\Facades\Auth::id(), $validated['reason']);
            return back()->with('success', 'Items dispatched successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Dispatch failed: ' . $e->getMessage());
        }
    }

    public function reply(Request $request, Booking $booking, \App\Services\BookingAttachmentService $attachmentService): RedirectResponse
    {
        $this->authorizedBooking($request, $booking);

        $rules = array_merge(
            [
                'message' => ['required', 'string', 'max:2000'],
                'visibility' => ['required', 'string', 'in:shared,admin_staff'],
            ],
            $attachmentService->getValidationRules()
        );
        $validated = $request->validate($rules);
        
        $messageData = [
            'booking_id' => $booking->id,
            'sender_type' => 'staff',
            'sender_id' => $request->user()->id,
            'message' => $validated['message'],
            'visibility' => $validated['visibility'],
        ];
        $attachment = $request->file('attachment');
        $category = $request->input('attachment_category');
        
        $attachmentService->storeMessage($booking, $messageData, $attachment, $category);
        
        return back()->with('success', 'Message sent successfully.');
    }
}