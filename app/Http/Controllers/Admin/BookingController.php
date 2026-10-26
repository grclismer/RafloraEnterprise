<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAlert;
use App\Models\Booking;
use App\Models\AssetReturn;
use App\Models\ClientNotification;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Payment;
use App\Models\ReturnItem;
use App\Models\BookingItem;
use App\Models\Presentation;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use App\Mail\BookingStatusChanged;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function index(Request $request): View
    {
        // The booking page's "Back to Bookings" returns to this exact list (search, filters, page).
        $request->session()->put('admin.bookings.list_url', $request->fullUrl());

        $validStatuses = [
            'all',
            'approved',
            'pending',
            'quotation_sent',
            'admin_approved',
            'payment_pending',
            'payment_submitted',
            'fully_paid',
            'downpayment_received',
            'confirmed',
            'event_in_progress',
            'event_completed',
            'pending_return',
            'pending_resolution',
            'completed',
            'declined',
            'cancelled',
            'change_requested',
            'cancellation_requested',
        ];

        $rawStatus = (string) $request->query('status', 'all');
        $statusFilter = in_array($rawStatus, $validStatuses, true) ? $rawStatus : 'all';

        $searchTerm = trim((string) $request->query('search', ''));
        $searchBookingNumber = $this->bookingNumberFromSearch($searchTerm);

        $eventTypeFilter = $request->query('event_type', 'all');
        $eventDateFrom = $request->query('event_date_from');
        $eventDateTo = $request->query('event_date_to');

        $availableEventTypes = Booking::select('event_type')
            ->whereNotNull('event_type')
            ->where('event_type', '!=', '')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type');

        $query = Booking::with(['client', 'payments', 'returns.returnItems', 'bookingItems', 'inventoryTransactions.inventoryItem', 'staffChecklistItems', 'activeQuotation']);

        // Status Filter
        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        // Event Type Filter
        if ($eventTypeFilter !== 'all' && !empty($eventTypeFilter)) {
            $query->where('event_type', $eventTypeFilter);
        }

        // Event Date Filter
        if (!empty($eventDateFrom)) {
            $query->whereDate('event_date', '>=', $eventDateFrom);
        }
        if (!empty($eventDateTo)) {
            $query->whereDate('event_date', '<=', $eventDateTo);
        }

        // Search Filter
        if ($searchTerm !== '') {
            $query->where(function ($q) use ($searchTerm, $searchBookingNumber) {
                if ($searchBookingNumber !== null) {
                    $q->orWhere('id', $searchBookingNumber);
                }
                $q->orWhere('event_type', 'like', "%{$searchTerm}%")
                  ->orWhere('venue', 'like', "%{$searchTerm}%")
                  ->orWhere('guest_name', 'like', "%{$searchTerm}%")
                  ->orWhere('guest_email', 'like', "%{$searchTerm}%")
                  ->orWhereHas('client', function ($cq) use ($searchTerm) {
                      $cq->where('full_name', 'like', "%{$searchTerm}%")
                         ->orWhere('email', 'like', "%{$searchTerm}%");
                  });
            });
        }

        $stageMap = [
            'request' => function($q) { /* Handled separately as it uses TemporaryGuestBooking */ },
            'review' => function($q) { $q->whereIn('status', ['pending', 'change_requested', 'cancellation_requested']); },
            'quotation' => function($q) { $q->whereIn('status', ['quotation_sent']); },
            'approval' => function($q) { $q->whereIn('status', ['approved', 'admin_approved']); },
            'payment' => function($q) { 
                $q->where(function ($query) {
                    $query->whereIn('status', ['payment_submitted', 'payment_pending'])
                          ->orWhereHas('payments', function ($pq) {
                              $pq->where('status', 'pending');
                          });
                });
            },
            'confirmed' => function($q) { $q->whereIn('status', ['downpayment_received', 'fully_paid', 'confirmed', 'event_in_progress', 'event_completed', 'pending_return', 'pending_resolution', 'completed']); },
            'cancelled' => function($q) { $q->whereIn('status', ['cancelled']); },
        ];

        $activeStage = $request->query('stage', 'all');

        $sort = $request->query('sort', 'newest');
        
        if ($activeStage === 'request') {
            $guestQuery = \App\Models\TemporaryGuestBooking::whereNull('claimed_at');
            
            if ($eventTypeFilter !== 'all' && !empty($eventTypeFilter)) {
                $guestQuery->where('event_type', $eventTypeFilter);
            }

            if (!empty($eventDateFrom)) {
                $guestQuery->whereDate('event_date', '>=', $eventDateFrom);
            }
            if (!empty($eventDateTo)) {
                $guestQuery->whereDate('event_date', '<=', $eventDateTo);
            }

            if (!empty($searchTerm)) {
                $guestQuery->where(function ($q) use ($searchTerm, $searchBookingNumber) {
                    if ($searchBookingNumber !== null) {
                        $q->orWhere('id', $searchBookingNumber);
                    }
                    $q->orWhere('guest_name', 'like', "%{$searchTerm}%")
                      ->orWhere('guest_email', 'like', "%{$searchTerm}%")
                      ->orWhere('event_type', 'like', "%{$searchTerm}%")
                      ->orWhere('venue', 'like', "%{$searchTerm}%");
                });
            }

            switch ($sort) {
                case 'oldest':
                    $guestQuery->oldest();
                    break;
                case 'event_date_asc':
                    $guestQuery->orderBy('event_date', 'asc');
                    break;
                case 'event_date_desc':
                    $guestQuery->orderBy('event_date', 'desc');
                    break;
                case 'newest':
                default:
                    $guestQuery->latest();
                    break;
            }
            
            $bookings = $guestQuery->paginate(15)->withQueryString();
        } else {
            if ($activeStage !== 'all' && isset($stageMap[$activeStage])) {
                $stageMap[$activeStage]($query);
            }

            switch ($sort) {
                case 'oldest':
                    $query->oldest();
                    break;
                case 'event_date_asc':
                    $query->orderBy('event_date', 'asc');
                    break;
                case 'event_date_desc':
                    $query->orderBy('event_date', 'desc');
                    break;
                case 'newest':
                default:
                    $query->latest();
                    break;
            }

            $bookings = $query->paginate(15)->withQueryString();
        }

        $stageCounts = [
            'all' => Booking::count(),
            'request' => \App\Models\TemporaryGuestBooking::whereNull('claimed_at')->count(),
            'review' => Booking::whereIn('status', ['pending', 'change_requested', 'cancellation_requested'])->count(),
            'quotation' => Booking::whereIn('status', ['quotation_sent'])->count(),
            'approval' => Booking::whereIn('status', ['approved', 'admin_approved'])->count(),
            'payment' => Booking::where(function ($query) {
                $query->whereIn('status', ['payment_submitted', 'payment_pending'])
                      ->orWhereHas('payments', function ($q) {
                          $q->where('status', 'pending');
                      });
            })->count(),
            'confirmed' => Booking::whereIn('status', ['downpayment_received', 'fully_paid', 'confirmed', 'event_in_progress', 'event_completed', 'pending_return', 'pending_resolution', 'completed'])->count(),
            'cancelled' => Booking::whereIn('status', ['cancelled'])->count(),
        ];

        return view('admin.bookings', [
            'bookings' => $bookings,
            'statusFilter' => $statusFilter,
            'searchTerm' => $searchTerm,
            'sort' => $sort,
            'activeStage' => $activeStage,
            'stageCounts' => $stageCounts,
            'eventTypeFilter' => $eventTypeFilter,
            'eventDateFrom' => $eventDateFrom,
            'eventDateTo' => $eventDateTo,
            'availableEventTypes' => $availableEventTypes,
        ]);
    }

    /**
     * Open the booking's return record from what was dispatched (ReturnRecordService). Lines that
     * were never dispatched, or are not linked to inventory, are not returnable and are left out.
     */
    /**
     * A search for a booking number: "53", "#53", "booking 53", "Booking #53", "BOOKING NO. 53" or "id 53"
     * (any letter case) gives 53; anything else is not a number search.
     */
    protected function bookingNumberFromSearch(string $term): ?int
    {
        return preg_match('/^(?:(?:booking|id)\s*(?:no\.?|number)?\s*)?#?\s*(\d{1,9})$/i', trim($term), $matches)
            ? (int) $matches[1]
            : null;
    }

    protected function ensureReturnRecordForBooking(Booking $booking): void
    {
        app(\App\Services\ReturnRecordService::class)->openFor($booking);
    }

    public function show(Booking $booking): View
    {
        return $this->buildBookingReviewView($booking);
    }

    public function assignStaff(Request $request, Booking $booking): RedirectResponse
    {
        $validated = $request->validate([
            'staff_id' => ['nullable', 'exists:users,id,role,staff'],
        ]);

        $oldStaffId = $booking->staff_id;
        $booking->staff_id = $validated['staff_id'] ?? null;
        $booking->save();

        if ((string) $oldStaffId !== (string) $booking->staff_id) {
            $this->logAuditEvent(
                'booking',
                'staff_assigned',
                $booking,
                $booking->staff_id ? 'Operational Staff assigned to booking' : 'Operational Staff assignment removed',
                Auth::id(),
                ['staff_id' => $oldStaffId],
                ['staff_id' => $booking->staff_id],
                'staff_assignment'
            );
        }

        return redirect()->back()->with('success', $booking->staff_id
            ? 'Staff member assigned to this event.'
            : 'Staff assignment removed from this event.');
    }

    public function reply(Request $request, Booking $booking, \App\Services\BookingAttachmentService $attachmentService): RedirectResponse
    {
        $rules = array_merge(
            [
                'message' => ['required', 'string', 'max:2000'],
                'action' => ['required', 'string', 'in:reply_only'],
                'submission_key' => ['nullable', 'string', 'max:255'],
                'visibility' => ['required', 'string', 'in:client_admin,admin_staff,shared'],
            ],
            \App\Services\BookingAttachmentService::getValidationRules(false)
        );

        $validated = $request->validate($rules);

        if (!empty($validated['submission_key'])) {
            $existing = \App\Models\BookingMessage::where('submission_key', $validated['submission_key'])->first();
            if ($existing) {
                return redirect()->back()->with('success', 'Reply sent successfully.');
            }
        }

        try {
            $messageData = [
                'booking_id' => $booking->id,
                'sender_type' => 'admin',
                'sender_id' => Auth::id(),
                'message' => $validated['message'],
                'visibility' => $validated['visibility'],
                'submission_key' => $validated['submission_key'] ?? null,
            ];

            $attachmentService->storeMessage(
                $booking, 
                $messageData, 
                $request->file('attachment'), 
                $validated['attachment_category'] ?? null
            );

            // Internal admin↔staff notes are not visible to the client, so the client is not notified about them.
            if ($validated['visibility'] !== 'admin_staff') {
                $this->createClientNotificationForBooking($booking, 'New message from Admin', 'Admin has replied to your request regarding booking #' . $booking->id . '.', 'booking_update');
            }
            $this->logAuditEvent('booking', 'admin_reply', $booking, 'Admin replied to client request', Auth::id());

            return redirect()->back()->with('success', 'Reply sent successfully.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return redirect()->back()->with('success', 'Reply sent successfully.');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23000) {
                return redirect()->back()->with('success', 'Reply sent successfully.');
            }
            \Illuminate\Support\Facades\Log::error('Failed to send admin reply: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Unable to send reply. Please try again.');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send admin reply: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Unable to send reply. Please try again.');
        }
    }

    /**
     * Return messages for admin booking review.
     */
    public function getMessages(Booking $booking): JsonResponse
    {
        // Mark incoming client messages as read upon opening the conversation
        $booking->messages()
            ->where('sender_type', 'client')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        AdminAlert::where('booking_id', $booking->id)
            ->where('type', 'client_message')
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $clientName = $booking->client?->full_name ?? ($booking->guest_name ?? 'Client');

        $messages = $booking->messages()
            ->orderBy('created_at', 'asc')
            ->get();

        $formatted = $messages->map(function ($msg) use ($clientName) {
            return [
                'id' => $msg->id,
                'booking_id' => $msg->booking_id,
                'sender_type' => $msg->sender_type,
                'sender_id' => $msg->sender_id,
                'sender_label' => $msg->sender_type === 'admin' ? 'You (Admin)' : ($msg->sender_type === 'client' ? $clientName : 'Staff'),
                'is_mine' => $msg->sender_type === 'admin',
                'message' => $msg->message,
                'visibility' => $msg->visibility,
                'related_quotation_version' => $msg->related_quotation_version,
                'submission_key' => $msg->submission_key,
                'created_at' => $msg->created_at?->toISOString(),
                'created_at_human' => $msg->created_at?->format('M j, g:i A'),
                'read_at' => $msg->read_at?->toISOString(),
                'is_read' => !is_null($msg->read_at),
                'has_attachment' => !empty($msg->attachment_path),
                'attachment_name' => $msg->attachment_name,
                'attachment_category' => $msg->attachment_category,
                'attachment_category_label' => $msg->attachment_category ? ucwords(str_replace('_', ' ', $msg->attachment_category)) : null,
                'attachment_url' => $msg->attachment_path ? route('secure.attachment.show', $msg->id) : null,
            ];
        });

        $unreadCount = $booking->messages()
            ->where('sender_type', 'client')
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'success' => true,
            'messages' => $formatted,
            'unread_count' => $unreadCount,
            'booking_status' => $booking->status,
        ]);
    }

    /**
     * Admin sends a message to the Client.
     */
    public function sendMessage(Request $request, Booking $booking, \App\Services\BookingAttachmentService $attachmentService): JsonResponse
    {
        $rules = array_merge(
            [
                'message' => ['required', 'string', 'max:2000'],
                'visibility' => ['nullable', 'string', 'in:client_admin,shared,admin_staff'],
                'related_quotation_version' => ['nullable', 'integer'],
                'submission_key' => ['nullable', 'string', 'max:255'],
            ],
            \App\Services\BookingAttachmentService::getValidationRules(false)
        );

        $validated = $request->validate($rules);
        $visibility = $validated['visibility'] ?? 'client_admin';
        $submissionKey = $validated['submission_key'] ?? null;

        if (!empty($submissionKey)) {
            $existing = $booking->messages()->where('submission_key', $submissionKey)->first();
            if ($existing) {
                return response()->json([
                    'success' => true,
                    'is_duplicate' => true,
                    'message' => [
                        'id' => $existing->id,
                        'booking_id' => $existing->booking_id,
                        'sender_type' => $existing->sender_type,
                        'sender_label' => 'You (Admin)',
                        'is_mine' => true,
                        'message' => $existing->message,
                        'visibility' => $existing->visibility,
                        'related_quotation_version' => $existing->related_quotation_version,
                        'submission_key' => $existing->submission_key,
                        'created_at' => $existing->created_at?->toISOString(),
                        'created_at_human' => $existing->created_at?->format('M j, g:i A'),
                        'read_at' => $existing->read_at?->toISOString(),
                        'is_read' => !is_null($existing->read_at),
                        'has_attachment' => !empty($existing->attachment_path),
                        'attachment_name' => $existing->attachment_name,
                        'attachment_category' => $existing->attachment_category,
                        'attachment_url' => $existing->attachment_path ? route('secure.attachment.show', $existing->id) : null,
                    ],
                ]);
            }
        }

        try {
            $messageData = [
                'booking_id' => $booking->id,
                'sender_type' => 'admin', // SERVER-DERIVED
                'sender_id' => Auth::id(), // SERVER-DERIVED
                'message' => $validated['message'],
                'visibility' => $visibility,
                'related_quotation_version' => $validated['related_quotation_version'] ?? null,
                'submission_key' => $submissionKey,
            ];

            $message = $attachmentService->storeMessage(
                $booking,
                $messageData,
                $request->file('attachment'),
                $validated['attachment_category'] ?? null
            );

            // Internal Admin & Staff notes are not visible to the client, so they must not notify the client.
            if ($visibility !== 'admin_staff') {
                $this->createClientNotificationForBooking(
                    $booking,
                    'New message from Admin',
                    'Admin has replied to your request regarding booking #' . $booking->id . '.',
                    'booking_update'
                );
            }

            $this->logAuditEvent('booking', 'admin_reply', $booking, 'Admin replied to client request', Auth::id());

            return response()->json([
                'success' => true,
                'message' => [
                    'id' => $message->id,
                    'booking_id' => $message->booking_id,
                    'sender_type' => $message->sender_type,
                    'sender_label' => 'You (Admin)',
                    'is_mine' => true,
                    'message' => $message->message,
                    'visibility' => $message->visibility,
                    'related_quotation_version' => $message->related_quotation_version,
                    'submission_key' => $message->submission_key,
                    'created_at' => $message->created_at?->toISOString(),
                    'created_at_human' => $message->created_at?->format('M j, g:i A'),
                    'read_at' => null,
                    'is_read' => false,
                    'has_attachment' => !empty($message->attachment_path),
                    'attachment_name' => $message->attachment_name,
                    'attachment_category' => $message->attachment_category,
                    'attachment_url' => $message->attachment_path ? route('secure.attachment.show', $message->id) : null,
                ],
            ], 201);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $existing = $booking->messages()->where('submission_key', $submissionKey)->first();
            if ($existing) {
                return response()->json([
                    'success' => true,
                    'is_duplicate' => true,
                    'message' => [
                        'id' => $existing->id,
                        'booking_id' => $existing->booking_id,
                        'sender_type' => $existing->sender_type,
                        'sender_label' => 'You (Admin)',
                        'is_mine' => true,
                        'message' => $existing->message,
                        'created_at' => $existing->created_at?->toISOString(),
                        'created_at_human' => $existing->created_at?->format('M j, g:i A'),
                    ],
                ]);
            }
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to send admin message: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Unable to send message.'], 500);
        }
    }

    /**
     * Mark messages read for admin.
     */
    public function markMessagesRead(Booking $booking): JsonResponse
    {
        $affected = $booking->messages()
            ->where('sender_type', 'client')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        AdminAlert::where('booking_id', $booking->id)
            ->where('type', 'client_message')
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'marked_read' => $affected,
            'unread_count' => 0,
        ]);
    }

    public function handleCancellationRequest(Request $request, Booking $booking): RedirectResponse
    {
        if ($booking->status !== 'cancellation_requested') {
            return redirect()->back()->with('error', 'Booking is not awaiting cancellation review.');
        }

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:approve,deny'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $priorStatus = $booking->pre_cancellation_status;
        if (empty($priorStatus) && $booking->quotations()->where('status', 'issued')->exists()) {
            $priorStatus = 'quotation_sent';
        }

        $eligibleStatuses = [
            'pending',
            'quotation_sent',
            'change_requested',
            'approved',
            'admin_approved',
            'payment_pending',
            'payment_submitted',
            'downpayment_received',
            'confirmed',
            'in_preparation',
            'event_in_progress',
        ];

        if ($validated['action'] === 'approve') {
            // Money the client may have sent is looked at before the booking closes (a payment
            // can only be verified once the cancellation is denied).
            if ($this->hasPaymentAwaitingVerification($booking)) {
                return redirect()->back()->with('error', 'The client submitted a payment reference that is still waiting for verification. Reject it, or deny the cancellation and verify it, before approving the cancellation.');
            }

            return DB::transaction(function () use ($booking, $validated, $priorStatus) {
                if (!empty($validated['admin_note'])) {
                    \App\Models\BookingMessage::create([
                        'booking_id' => $booking->id,
                        'sender_type' => 'admin',
                        'sender_id' => Auth::id(),
                        'message' => $validated['admin_note'],
                    ]);
                }

                $booking->status = 'cancelled';
                $booking->pre_cancellation_status = null;
                $booking->save();

                $reservationStatuses = ['downpayment_received', 'confirmed', 'in_preparation', 'event_in_progress', 'event_completed'];
                $hasLocks = InventoryTransaction::where('booking_id', $booking->id)
                    ->where('transaction_type', 'booking_lock')
                    ->exists();

                if (in_array($priorStatus, $reservationStatuses, true) || $hasLocks) {
                    $this->releaseInventoryForBooking($booking);
                }

                if ($booking->hasDispatchedReusableMaterials()) {
                    $this->ensureReturnRecordForBooking($booking);
                }

                $this->createClientNotificationForBooking(
                    $booking,
                    'Cancellation Approved',
                    'Your cancellation request for booking #' . $booking->id . ' has been approved.',
                    'booking_update'
                );
                $this->logAuditEvent('booking', 'cancellation_approved', $booking, 'Admin approved cancellation request', Auth::id());

                return redirect()->back()->with('success', 'Booking has been cancelled.');
            });
        }

        // Action === 'deny'
        if (empty($priorStatus) || !in_array($priorStatus, $eligibleStatuses, true)) {
            return redirect()->back()->with('error', 'Cannot process cancellation denial: prior booking status is missing or invalid.');
        }

        return DB::transaction(function () use ($booking, $validated, $priorStatus) {
            if (!empty($validated['admin_note'])) {
                \App\Models\BookingMessage::create([
                    'booking_id' => $booking->id,
                    'sender_type' => 'admin',
                    'sender_id' => Auth::id(),
                    'message' => $validated['admin_note'],
                ]);
            }

            if ($priorStatus === 'quotation_sent') {
                $booking->status = 'change_requested';
            } else {
                $booking->status = $priorStatus;
            }

            $booking->cancellation_reason = null;
            $booking->pre_cancellation_status = null;
            $booking->save();

            $this->createClientNotificationForBooking(
                $booking,
                'Cancellation Denied',
                'Your cancellation request for booking #' . $booking->id . ' has been denied. The Admin has sent a note.',
                'booking_update'
            );
            $this->logAuditEvent('booking', 'cancellation_denied', $booking, 'Admin denied cancellation request', Auth::id());

            $successMsg = $priorStatus === 'quotation_sent'
                ? 'Cancellation request denied. Booking reverted to discussion state.'
                : 'Cancellation request denied. Booking operational status restored to ' . $booking->status_display_label . '.';

            return redirect()->back()->with('success', $successMsg);
        });
    }

    public static function inferIsPerishableFromName(?string $name): bool
    {
        if (empty($name)) return true;
        $keywords = ['backdrop', 'frame', 'arch', 'vase', 'stand', 'lighting', 'light', 'structure', 'archway', 'tripod', 'standee'];
        $lower = strtolower($name);
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return false; // hardware
            }
        }

        // Default to perishable for floral-sounding names
        return true;
    }

    public function notifications(): View
    {
        $alerts = collect();
        $storedAlerts = AdminAlert::where('is_read', false)
            ->where('type', '!=', 'inventory_shortage')
            ->latest('created_at')
            ->get()
            ->each(function ($alert): void {
                $alert->is_dynamic = false;
            });

        foreach ($storedAlerts as $storedAlert) {
            $alerts->push($storedAlert);
        }
        $paymentPendingBookings = Booking::where(function ($query) {
            $query->whereIn('status', ['payment_submitted', 'payment_pending'])
                  ->orWhereHas('payments', function ($q) {
                      $q->where('status', 'pending');
                  });
        })
            ->latest('updated_at')
            ->get();

        foreach ($paymentPendingBookings as $booking) {
            $alerts->push((object) [
                'id' => 'booking-' . $booking->id,
                'type' => 'payment_pending',
                'title' => 'Payment Review Required',
                'message' => sprintf('Booking #%d is awaiting admin verification for the submitted payment reference.', $booking->id),
                'booking_id' => $booking->id,
                'inventory_item_id' => null,
                'created_at' => $booking->updated_at ?? $booking->created_at,
                'is_read' => false,
                'is_dynamic' => true,
            ]);
        }

        $bookingShortageAlerts = collect();
        $bookedInventoryItemIds = collect();
        $activeBookings = Booking::with(['bookingItems.inventoryItem'])
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', [
                        'cancelled',
                        'rejected',
                        'completed',
                        'declined',
                        'event_in_progress',
                        'event_completed',
                        'pending_return',
                        'pending_resolution',
                    ]);
            })
            ->latest('updated_at')
            ->get();

        // Read-only: booking lines are linked to inventory only by Admin's explicit actions.
        $shortageService = app(\App\Services\BookingShortageService::class);
        foreach ($activeBookings as $booking) {
            $shortageItems = $shortageService->itemsFor($booking);
            foreach ($shortageItems as $shortageItem) {
                if (($shortageItem['is_shortage'] ?? false) && $shortageItem['inventory_item_id']) {
                    $bookedInventoryItemIds->push($shortageItem['inventory_item_id']);
                }
            }

            $shortageCount = count(array_filter($shortageItems, fn (array $item): bool => (bool) ($item['is_shortage'] ?? false)));
            if ($shortageCount === 0) {
                continue;
            }

            $itemNames = array_values(array_filter(array_map(fn ($item) => ($item['is_shortage'] ?? false) ? ($item['name'] ?? null) : null, $shortageItems)));
            $summaryNames = implode(', ', array_slice($itemNames, 0, 4));
            $summarySuffix = count($itemNames) > 4 ? ', ...' : '';

            $bookingShortageAlerts->push((object) [
                'id' => 'booking-shortage-' . $booking->id,
                'type' => 'booking_shortage',
                'title' => 'Stock Alert: Booking #' . $booking->id,
                'message' => $shortageCount . ' item' . ($shortageCount === 1 ? '' : 's') . ' need attention' . (!empty($summaryNames) ? ' (' . $summaryNames . $summarySuffix . ')' : '') . '. Available items are shown below in green.',
                'booking_id' => $booking->id,
                'inventory_item_id' => null,
                'created_at' => $booking->updated_at ?? $booking->created_at,
                'is_read' => false,
                'is_dynamic' => true,
                'items' => $shortageItems,
                'alert_type' => 'booking_shortage',
            ]);
        }

        foreach ($bookingShortageAlerts as $alert) {
            $alerts->push($alert);
        }

        $generalLowStockItems = InventoryItem::whereNotNull('min_stock')
            ->get()
            ->filter(function ($item) use ($bookedInventoryItemIds) {
                $netAvailable = max(0.0, (float) $item->current_stock - (float) $item->reserved_stock);
                $isBelowThreshold = ((float) $item->current_stock <= (float) $item->min_stock) || $netAvailable < 0;

                return $isBelowThreshold && !$bookedInventoryItemIds->contains($item->id);
            });

        if ($generalLowStockItems->isNotEmpty()) {
            $generalItems = $generalLowStockItems->map(function ($item) {
                $netAvailable = max(0.0, (float) $item->current_stock - (float) $item->reserved_stock);

                return [
                    'inventory_item_id' => $item->id,
                    'name' => $item->name,
                    'required' => (float) $item->min_stock,
                    'current_stock' => (float) $item->current_stock,
                    'reserved_stock' => (float) $item->reserved_stock,
                    'net_available' => $netAvailable,
                    'shortfall' => max(0.0, (float) $item->min_stock - $netAvailable),
                ];
            })->values()->all();

            $alerts->push((object) [
                'id' => 'general-low-stock',
                'type' => 'general_low_stock',
                'title' => 'Stock Alert: General Low Stock (' . count($generalItems) . ' Items)',
                'message' => count($generalItems) . ' item' . (count($generalItems) === 1 ? '' : 's') . ' below the minimum stock threshold',
                'booking_id' => null,
                'inventory_item_id' => null,
                'created_at' => now(),
                'is_read' => false,
                'is_dynamic' => true,
                'items' => $generalItems,
                'alert_type' => 'general_low_stock',
            ]);
        }

        return view('admin.notifications', [
            'alerts' => $alerts->sortByDesc(fn ($alert) => $alert->created_at)->values(),
        ]);
    }

    public function resolveBookingShortages(Request $request, Booking $booking): RedirectResponse
    {
        $validated = $request->validate([
            'stock' => ['nullable', 'array'],
            'stock.*' => ['nullable', 'numeric', 'min:0'],
            'stock_updates' => ['nullable', 'array'],
            'stock_updates.*' => ['nullable', 'numeric', 'min:0'],
            'action' => ['nullable', 'string', 'in:restock_and_resolve,restock_and_edit'],
        ]);

        $stockUpdates = $request->input('stock', $request->input('stock_updates', []));
        $shouldResolveAlert = ($validated['action'] ?? 'restock_and_resolve') === 'restock_and_resolve';
        $restockedAnyItem = false;

        if ($shouldResolveAlert) {
            // Acknowledging an alert (read state) does not mean the physical shortage was resolved.
            // Duplicate procurement is now prevented by an operation-specific transaction check below.
        }

        DB::transaction(function () use ($stockUpdates, $booking, $shouldResolveAlert, &$restockedAnyItem): void {
            // Lock the booking parent record first to ensure consistent parent-first locking hierarchy
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            // Extract and sort inventory item IDs in ascending order for deterministic row locking
            $itemIds = collect($stockUpdates)
                ->filter(fn ($qty) => (float) $qty > 0)
                ->keys()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->toArray();

            // Acquire inventory item row locks deterministically in ascending PK order
            $lockedItems = !empty($itemIds)
                ? InventoryItem::whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
                : collect();

            foreach ($itemIds as $inventoryItemId) {
                $updatedStock = (float) ($stockUpdates[$inventoryItemId] ?? 0);
                if ($updatedStock <= 0) {
                    continue;
                }

                $inventoryItem = $lockedItems->get($inventoryItemId);
                if (!$inventoryItem) {
                    continue;
                }
                
                // Idempotency / Duplicate Prevention:
                // Prevent duplicate submission of the EXACT SAME restocking operation within 10 seconds.
                $isDuplicate = InventoryTransaction::where('inventory_item_id', $inventoryItem->id)
                    ->where('booking_id', $lockedBooking->id)
                    ->where('transaction_type', 'procurement')
                    ->where('quantity_change', $updatedStock)
                    ->where('created_at', '>=', now()->subSeconds(10))
                    ->exists();

                if ($isDuplicate) {
                    continue;
                }

                $inventoryItem->increment('current_stock', $updatedStock);
                InventoryTransaction::create([
                    'inventory_item_id' => $inventoryItem->id,
                    'booking_id' => $lockedBooking->id,
                    'quantity_change' => $updatedStock,
                    'transaction_type' => 'procurement',
                    'reason' => 'Admin restock for booking #' . $lockedBooking->id,
                    'performed_by' => Auth::id(),
                ]);
                $restockedAnyItem = true;
            }

            if ($shouldResolveAlert && $restockedAnyItem) {
                AdminAlert::where('booking_id', $lockedBooking->id)
                    ->where('is_read', false)
                    ->update(['is_read' => true]);
            }
        });

        if (($validated['action'] ?? null) === 'restock_and_edit') {
            return redirect()->route('admin.bookings.edit', ['booking' => $booking->id])->with('success', 'Stock updates applied. Please finish the booking changes from the edit screen.');
        }

        if ($request->input('redirect_to') === 'booking' || ($request->headers->get('referer') && str_contains($request->headers->get('referer'), '/bookings/' . $booking->id))) {
            return redirect()->route('admin.bookings.show', ['booking' => $booking->id])->with('success', 'Booking shortages resolved successfully.');
        }

        return redirect()->route('admin.notifications')->with('success', 'Booking shortages resolved successfully.');
    }

    public function promoteBookingItemsToInventory(Request $request, Booking $booking): RedirectResponse
    {
        $validated = $request->validate([
            'booking_item_ids' => ['required', 'array', 'min:1'],
            'booking_item_ids.*' => ['integer'],
        ]);

        // Names are matched ignoring case, spacing, punctuation and singular/plural
        // (InventoryItem::catalogMatches): an item already in the catalog is linked, never added twice.
        $promoted = 0;
        $linked = 0;
        $skipped = [];
        DB::transaction(function () use ($validated, $booking, &$promoted, &$linked, &$skipped): void {
            foreach ($validated['booking_item_ids'] as $bookingItemId) {
                $bookingItem = $booking->bookingItems()
                    ->whereKey($bookingItemId)
                    ->whereNull('inventory_item_id')
                    ->lockForUpdate()
                    ->first();

                $name = trim((string) $bookingItem?->item_name);
                if (!$bookingItem || $name === '') {
                    continue;
                }

                ['exact' => $exact, 'similar' => $similar] = InventoryItem::catalogMatches($name, unit: 'piece');
                if ($exact && !$exact->trashed()) {
                    $bookingItem->forceFill(['inventory_item_id' => $exact->id])->save();
                    $linked++;
                    continue;
                }
                if ($exact || $similar) {
                    $skipped[] = $exact
                        ? "\"{$name}\" (\"{$exact->name}\" is archived: restore it first)"
                        : "\"{$name}\" (similar to \"{$similar->name}\": link it from the booking)";
                    continue;
                }

                $possibleMatches = InventoryItem::catalogSimilarityCandidates($name);
                if ($possibleMatches->isNotEmpty()) {
                    $candidateNames = $possibleMatches->pluck('name')->implode(', ');
                    $skipped[] = "\"{$name}\" (possible duplicate of: {$candidateNames}; review and link it from the booking)";
                    continue;
                }

                $inventoryItem = InventoryItem::create([
                    'name' => $name,
                    'category' => 'misc',
                    'is_perishable' => false,
                    'current_stock' => 0,
                    'unit_cost' => (float) ($bookingItem->quoted_unit_price ?? 0),
                    'min_stock' => 0,
                    'unit' => 'piece',
                ]);
                $bookingItem->forceFill(['inventory_item_id' => $inventoryItem->id])->save();
                $promoted++;
            }
        });

        $parts = [];
        if ($promoted > 0) {
            $parts[] = $promoted . ' AI item' . ($promoted === 1 ? '' : 's') . ' added to inventory with zero stock.';
        }
        if ($linked > 0) {
            $parts[] = $linked . ' already in inventory and linked instead of added again.';
        }
        if ($skipped) {
            $parts[] = 'Not added: ' . implode('; ', $skipped) . '.';
        }

        return redirect()->route('admin.notifications')->with(
            ($promoted + $linked) > 0 ? 'success' : 'error',
            $parts ? implode(' ', $parts) : 'No eligible missing AI items were found.'
        );
    }

    public function edit(Booking $booking): View
    {
        return $this->buildBookingReviewView($booking, true);
    }

    protected function normalizeBookingStatus(mixed $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $normalized = Str::slug(trim((string) $status), '_');

        return $normalized === '' ? null : $normalized;
    }

    protected function sanitizeAdminNoteMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $message = preg_replace('/\r\n?/', "\n", $message);
        $lines = preg_split('/\n/', trim((string) $message));
        $filtered = [];
        $insideGeneratedSection = false;

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);

            if (preg_match('/^\s*(Item adjustments|item adjustments)\s*:\s*$/i', $trimmed)) {
                $insideGeneratedSection = true;
                continue;
            }

            if ($insideGeneratedSection) {
                if ($trimmed === '') {
                    continue;
                }

                if (preg_match('/^[\-•\*] /', $trimmed) || preg_match('/^\d+\./', $trimmed)) {
                    continue;
                }

                $insideGeneratedSection = false;
            }

            $filtered[] = $line;
        }

        $message = implode("\n", $filtered);

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $message));
    }

    protected function buildItemAdjustmentSummary(array $items): array
    {
        $activeItems = [];
        $removedItems = [];

        foreach ($items as $it) {
            $name = trim((string) ($it['item_name'] ?? 'Item'));
            $qty = array_key_exists('quantity', $it) ? (float) ($it['quantity'] ?? 0) : (array_key_exists('adjusted_quantity', $it) ? (float) ($it['adjusted_quantity'] ?? 0) : null);
            $isRemoved = !empty($it['remove']) || !empty($it['unavailable']);

            if ($isRemoved) {
                $removedItems[] = '• ' . $name;
                continue;
            }

            if ($qty === null) {
                continue;
            }

            $activeItems[] = '• ' . $name . '  Qty: ' . number_format($qty, 2, '.', '');
        }

        return [
            'items' => $activeItems,
            'removed' => $removedItems,
        ];
    }

    /**
     * The client-facing price line for a newly issued quotation, measured against the quotation
     * the client had (null on a first issue), never against unissued working prices.
     */
    protected function buildPriceAdjustmentSummary(?float $previousSharedTotal, float $issuedTotal): array
    {
        if ($previousSharedTotal === null) {
            return ['• Quoted total: ₱' . number_format($issuedTotal, 2)];
        }

        if (abs($issuedTotal - $previousSharedTotal) <= 0.01) {
            return [];
        }

        return [
            '• Quote updated: ₱' . number_format($previousSharedTotal, 2) . ' → ₱' . number_format($issuedTotal, 2),
        ];
    }

    protected function appendGeneratedAdjustmentNote(string $adminNote, array $items, array $priceChanges = []): string
    {
        $summary = $this->buildItemAdjustmentSummary($items);
        $sections = [];

        if ($adminNote !== '') {
            $sections[] = trim($adminNote);
        }

        if (!empty($summary['items'])) {
            $sections[] = "Item adjustments:\n" . implode("\n", $summary['items']);
        }

        if (!empty($summary['removed'])) {
            $sections[] = "Removed items:\n" . implode("\n", $summary['removed']);
        }

        if (!empty($priceChanges)) {
            $sections[] = "Price changes:\n" . implode("\n", $priceChanges);
        }

        return trim(implode("\n\n", $sections));
    }

    protected function logAuditEvent(string $module, string $action, ?Booking $booking = null, ?string $details = null, ?int $userId = null, ?array $oldValues = null, ?array $newValues = null, ?string $eventType = null): void
    {
        AuditLog::create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'module' => $module,
            'event_type' => $eventType,
            'details' => $details,
            'old_values' => $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => request()->ip(),
            'entity_type' => $booking ? Booking::class : null,
            'entity_id' => $booking?->id,
        ]);
    }

    protected function createClientNotificationForBooking(Booking $booking, string $title, string $message, string $type = 'booking_update'): void
    {
        if (!$booking->client) {
            return;
        }

        $user = null;
        if (filter_var($booking->client->email, FILTER_VALIDATE_EMAIL)) {
            $user = \App\Models\User::where('email', $booking->client->email)->first();
        }
        if (!$user && !empty($booking->client->user_id)) {
            $user = \App\Models\User::find($booking->client->user_id);
        }
        if (!$user) {
            return;
        }

        // If message is structured payload, prefer its human-readable `text` key when present.
        if (is_array($message)) {
            $messageText = trim((string) ($message['text'] ?? '')) ?: trim(json_encode($message, JSON_UNESCAPED_UNICODE));
        } else {
            $messageText = trim((string) $message);
        }

        $existing = ClientNotification::where('user_id', $user->id)
            ->where('booking_id', $booking->id)
            ->where('message', $messageText)
            ->latest()
            ->first();

        if ($existing && $existing->created_at && $existing->created_at->diffInMinutes(now()) < 30) {
            return;
        }

        ClientNotification::create([
            'user_id' => $user->id,
            'booking_id' => $booking->id,
            'type' => $type,
            'title' => $title,
            'message' => $messageText,
            'is_read' => false,
        ]);
    }

    protected function buildBookingReviewView(Booking $booking, bool $isEditMode = false): View
    {
        // Read-only: unlinked lines are shown as such; linking happens on Admin's explicit actions.
        $booking->load(['client', 'aiAnalyses', 'payments', 'returns.returnItems', 'bookingItems.inventoryItem', 'quotations', 'staffChecklistItems.completedBy', 'assignedStaff']);
        $analysis = $booking->aiAnalyses()->latest('analyzed_at')->first();
        $payment = $booking->payments()->latest()->first();
        $activeQuotation = $booking->activeQuotation;

        // Load all inventory items with their substitutes to power the substitution engine
        $allInventoryItems = \App\Models\InventoryItem::with('substitutes')->get();
        $staffUsers = User::where('role', 'staff')->orderBy('name')->get(['id', 'name', 'email']);
        $bookingItems = $booking->bookingItems()->orderBy('created_at')->get();

        // Compute a strict read-only flag for the quote review view
        $quoteReadOnly = in_array($booking->status, ['downpayment_received', 'event_in_progress', 'event_completed', 'pending_return', 'pending_resolution', 'completed'], true);

        $bookingMessages = $booking->messages()->orderBy('created_at')->get();
        $unreadMessageCount = $bookingMessages->where('sender_type', 'client')->whereNull('read_at')->count();

        $confirmedItems = $bookingItems->filter(fn ($bi) => $bi->confirmed_at !== null && (float) $bi->quantity > 0);
        $reusableItems = $confirmedItems->filter(fn ($bi) => $bi->inventoryItem && !$bi->requiresFreshProcurement());
        $freshFlowerItems = $confirmedItems->filter(fn ($bi) => $bi->requiresFreshProcurement());
        $unconfirmedItems = $bookingItems->filter(fn ($bi) => $bi->confirmed_at === null && (float) $bi->quantity > 0);

        $bookingShortages = [];
        $reusableStatusList = [];
        $allReusableReserved = $reusableItems->isNotEmpty();

        foreach ($reusableItems as $bItem) {
            $inv = $bItem->inventoryItem;
            $required = (float) $bItem->quantity;

            $locked = (float) InventoryTransaction::query()
                ->where('booking_id', $booking->id)
                ->where('inventory_item_id', $inv->id)
                ->where('transaction_type', 'booking_lock')
                ->where('quantity_change', '<', 0)
                ->sum('quantity_change');
            $released = (float) InventoryTransaction::query()
                ->where('booking_id', $booking->id)
                ->where('inventory_item_id', $inv->id)
                ->where('transaction_type', 'booking_release')
                ->where('quantity_change', '>', 0)
                ->sum('quantity_change');

            $netLocked = abs($locked) - $released;
            $remaining = max(0.0, $required - $netLocked);
            $isReserved = $netLocked >= $required;

            if (!$isReserved) {
                $allReusableReserved = false;
            }

            $available = (float) $inv->net_available;
            $hasShortage = $available < $remaining;

            $netDispatched = abs((float) InventoryTransaction::query()
                ->where('booking_id', $booking->id)
                ->where('inventory_item_id', $inv->id)
                ->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])
                ->sum('quantity_change'));

            $outstandingDispatch = max(0.0, $netLocked - $netDispatched);

            if ($hasShortage) {
                $bookingShortages[] = [
                    'inventory_item' => $inv,
                    'booking_item' => $bItem,
                    'required' => $required,
                    'locked' => $netLocked,
                    'remaining' => $remaining,
                    'available' => $available,
                    'shortage' => $remaining - $available,
                ];
            }

            $reusableStatusList[] = [
                'booking_item' => $bItem,
                'inventory_item' => $inv,
                'required' => $required,
                'locked' => $netLocked,
                'remaining' => $remaining,
                'available' => $available,
                'is_reserved' => $isReserved,
                'has_shortage' => $hasShortage,
                'net_dispatched' => $netDispatched,
                'outstanding_dispatch' => $outstandingDispatch,
            ];
        }

        if ($reusableItems->isEmpty()) {
            $allReusableReserved = true;
        }

        $totalObligation = (float) $booking->total_obligation;
        $paidAmount = (float) $booking->total_paid;
        $remainingBalance = (float) $booking->remaining_balance;
        $damageCharges = (float) $booking->damage_charges;
        $baseQuotedAmount = (float) ($booking->final_quoted_price ?? $booking->total_quoted ?? 0);
        $canLogFinalPayment = in_array($booking->status, ['event_in_progress', 'event_completed', 'pending_return', 'pending_resolution'], true) && ($remainingBalance > 0);

        return view('admin.booking-show', [
            'booking' => $booking,
            'analysis' => $analysis,
            'payment' => $payment,
            'allInventoryItems' => $allInventoryItems,
            'catalogSimilarityItems' => InventoryItem::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at']),
            'staffUsers' => $staffUsers,
            'bookingItems' => $bookingItems,
            'isEditMode' => $isEditMode,
            'isReadOnly' => $isEditMode && in_array($booking->status, ['event_in_progress', 'event_completed', 'pending_return', 'pending_resolution', 'completed'], true),
            'quoteReadOnly' => $quoteReadOnly,
            'activeQuotation' => $activeQuotation,
            'bookingMessages' => $bookingMessages,
            'unreadMessageCount' => $unreadMessageCount,
            'reusableStatusList' => $reusableStatusList,
            'freshFlowerItems' => $freshFlowerItems,
            'unconfirmedItems' => $unconfirmedItems,
            'bookingShortages' => $bookingShortages,
            'allReusableReserved' => $allReusableReserved,
            'totalObligation' => $totalObligation,
            'paidAmount' => $paidAmount,
            'remainingBalance' => $remainingBalance,
            'damageCharges' => $damageCharges,
            'baseQuotedAmount' => $baseQuotedAmount,
            'canLogFinalPayment' => $canLogFinalPayment,
        ]);
    }

    /**
     * Link unlinked booking lines to the catalog item with the same name, when exactly one
     * catalog item has that name. Runs only as part of Admin sending the quotation; a name shared
     * by several catalog items is left for Admin to link explicitly.
     */
    protected function reconcileBookingInventoryLinks(Booking $booking): void
    {
        $inventoryByName = InventoryItem::query()
            ->get(['id', 'name'])
            ->filter(fn (InventoryItem $item): bool => trim((string) $item->name) !== '')
            ->groupBy(fn (InventoryItem $item): string => $this->normalizeInventoryName($item->name))
            ->filter(fn ($items): bool => $items->count() === 1)
            ->map(fn ($items) => $items->first());

        if ($inventoryByName->isEmpty()) {
            return;
        }

        $booking->bookingItems()
            ->whereNull('inventory_item_id')
            ->get()
            ->each(function (BookingItem $bookingItem) use ($inventoryByName): void {
                $inventoryItem = $inventoryByName->get($this->normalizeInventoryName($bookingItem->item_name));
                if ($inventoryItem) {
                    $bookingItem->forceFill(['inventory_item_id' => $inventoryItem->id])->save();
                }
            });
    }

    protected function normalizeInventoryName(?string $name): string
    {
        // Same words in any order, ignoring case and punctuation (InventoryItem::wordKey).
        return InventoryItem::wordKey($name);
    }

    /**
     * Refuse to add a catalog item whose name already exists (ignoring case and surrounding
     * spaces); Admin links the line to that item instead. Null when the name is free.
     */
    /**
     * Why $name cannot be added to the catalog, or null. Names are compared ignoring case, spacing
     * and punctuation, and singular/plural (InventoryItem::catalogMatches), archived items included.
     */
    protected function duplicateCatalogNameError(string $name, bool $confirmDistinct = false): ?string
    {
        ['exact' => $exact, 'similar' => $similar] = InventoryItem::catalogMatches($name);

        if ($exact) {
            return $exact->trashed()
                ? "An inventory item named \"{$exact->name}\" is archived. Restore it from Archived Items, then link the material to it."
                : "An inventory item named \"{$exact->name}\" already exists. Link the material to it instead of adding a duplicate.";
        }

        if ($similar) {
            return "A similar inventory item \"{$similar->name}\" already exists" . ($similar->trashed() ? ' (archived)' : '') . ". Link the material to it, or use a clearly different name.";
        }

        $possibleMatches = InventoryItem::catalogSimilarityCandidates($name);
        if ($possibleMatches->isNotEmpty() && !$confirmDistinct) {
            return 'Possible catalog matches: ' . $possibleMatches->pluck('name')->implode(', ')
                . '. Review these items, link the correct one, or confirm that the new item is genuinely different.';
        }

        return null;
    }

    protected function adjustInventoryForBookingStatus(Booking $booking, ?string $oldStatus, ?string $newStatus): void
    {
        if ($oldStatus === $newStatus) {
            return;
        }

        $confirmedStatuses = ['downpayment_received', 'confirmed'];
        $cancelledStatuses = ['cancelled', 'declined', 'rejected'];

        if (in_array($newStatus, [...$confirmedStatuses, 'fully_paid'], true)) {
            if (!$booking->hasVerifiedPayment() || !$booking->hasValidPreparationSchedule()) {
                return;
            }

            $this->reserveReusableMaterialsForBooking($booking);
        }

        if (in_array($oldStatus, $confirmedStatuses, true) && in_array($newStatus, $cancelledStatuses, true)) {
            $this->releaseInventoryForBooking($booking);
        }
    }

    public function reserveReusableMaterialsForBooking(Booking $booking): array
    {
        $items = $this->confirmedInventoryItems($booking);
        $reusableIds = $booking->reusableBookingItems()->pluck('inventory_item_id')->map(fn ($id) => (int) $id)->unique();
        $reusableItems = $items->filter(fn ($item) => $reusableIds->contains((int) $item->id));

        if ($reusableItems->isEmpty()) {
            return ['reserved' => 0, 'shortages' => []];
        }

        $itemIds = $reusableItems->pluck('id')->sort()->values()->toArray();
        $lockedItems = InventoryItem::whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $shortages = [];
        $itemsToLock = [];

        foreach ($reusableItems as $inventoryItem) {
            $requiredQty = (float) ($inventoryItem->pivot->quantity ?? 0);
            if ($requiredQty <= 0) {
                continue;
            }

            $alreadyLocked = (float) InventoryTransaction::query()
                ->where('booking_id', $booking->id)
                ->where('inventory_item_id', $inventoryItem->id)
                ->where('transaction_type', 'booking_lock')
                ->where('quantity_change', '<', 0)
                ->sum('quantity_change');

            $alreadyReleased = (float) InventoryTransaction::query()
                ->where('booking_id', $booking->id)
                ->where('inventory_item_id', $inventoryItem->id)
                ->where('transaction_type', 'booking_release')
                ->where('quantity_change', '>', 0)
                ->sum('quantity_change');

            $netReserved = abs($alreadyLocked) - $alreadyReleased;
            $remainingToLock = $requiredQty - $netReserved;

            if ($remainingToLock <= 0) {
                continue;
            }

            $lockedItem = $lockedItems->get($inventoryItem->id);
            if ($lockedItem && $lockedItem->net_available < $remainingToLock) {
                $shortages[] = [
                    'name' => $lockedItem->name,
                    'required' => $remainingToLock,
                    'available' => $lockedItem->net_available,
                ];
            } else {
                $itemsToLock[] = [
                    'item' => $inventoryItem,
                    'quantity' => $remainingToLock,
                ];
            }
        }

        if (!empty($shortages)) {
            $names = collect($shortages)->pluck('name')->join(', ');
            throw new \RuntimeException("Insufficient available stock to reserve materials: {$names}. Please restock or resolve shortages first.");
        }

        $reservedCount = 0;
        foreach ($itemsToLock as $lockData) {
            InventoryTransaction::create([
                'inventory_item_id' => $lockData['item']->id,
                'booking_id' => $booking->id,
                'quantity_change' => -$lockData['quantity'],
                'transaction_type' => 'booking_lock',
                'reason' => 'Preparation-period reservation for booking #' . $booking->id,
                'performed_by' => Auth::id() ?? $booking->handled_by,
            ]);
            $reservedCount++;
        }

        if ($reservedCount > 0) {
            $this->logAuditEvent(
                'booking',
                'materials_reserved',
                $booking,
                "Reserved {$reservedCount} reusable inventory material(s) for booking #{$booking->id}",
                Auth::id()
            );
        }

        return ['reserved' => $reservedCount, 'shortages' => []];
    }

    public function reserveMaterials(Request $request, Booking $booking): RedirectResponse
    {
        if (Auth::user()?->role !== 'admin') {
            abort(403, 'Only an authorized admin can reserve booking materials.');
        }

        if (!$booking->hasVerifiedPayment() || !in_array($booking->status, ['downpayment_received', 'confirmed', 'fully_paid', 'in_preparation', 'event_in_progress'], true)) {
            return redirect()->back()->with('error', 'Cannot reserve materials: a down payment or full payment must be verified first.');
        }

        if (is_null($booking->preparation_start_date)) {
            return redirect()->back()->with('error', 'Cannot reserve materials: please set a Preparation Start Date first.');
        }

        if (!$booking->hasValidPreparationSchedule()) {
            return redirect()->back()->with('error', 'Cannot reserve materials: the preparation date must be at least 14 days before the event.');
        }

        try {
            $result = DB::transaction(function () use ($booking) {
                $res = $this->reserveReusableMaterialsForBooking($booking);
                $booking->preparation_status = 'in_preparation';
                $booking->save();
                return $res;
            });

            $freshFlowerCount = $booking->freshProcurementItems()->count();

            $msg = 'Reusable materials reserved successfully for preparation period.';
            if ($freshFlowerCount > 0) {
                $msg .= " Note: {$freshFlowerCount} fresh flower requirement(s) are tracked for procurement.";
            }

            return redirect()->back()->with('success', $msg);
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    protected function releaseInventoryForBooking(Booking $booking, ?string $reason = null): void
    {
        app(\App\Services\InventoryDispatchService::class)->releaseUnfulfilledReservations(
            $booking,
            Auth::id() ?? $booking->handled_by,
            $reason ?? ('Released confirmed booking inventory for booking #' . $booking->id)
        );
    }

    protected function collectInsufficientBookingItems(Booking $booking, array $items): array
    {
        $requestedQuantities = [];
        foreach ($items as $it) {
            $bookingItemId = $it['booking_item_id'] ?? null;
            $itemName = trim((string) ($it['item_name'] ?? ''));
            $requestedQty = isset($it['quantity']) ? (float) $it['quantity'] : null;

            if ($bookingItemId) {
                $bookingItem = BookingItem::find($bookingItemId);
                if ($bookingItem && $bookingItem->inventory_item_id) {
                    $requestedQuantities[$bookingItem->inventory_item_id] = $requestedQty ?? (float) $bookingItem->quantity;
                }
            }

            if ($itemName !== '' && $requestedQty !== null) {
                $inventoryItem = InventoryItem::query()
                    ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($itemName)])
                    ->first();

                if ($inventoryItem) {
                    $requestedQuantities[$inventoryItem->id] = $requestedQty;
                }
            }
        }

        if (empty($requestedQuantities)) {
            foreach ($this->confirmedInventoryItems($booking) as $inventoryItem) {
                $requestedQuantities[$inventoryItem->id] = (float) ($inventoryItem->pivot->quantity ?? 0);
            }
        }

        $insufficientItems = [];
        foreach ($requestedQuantities as $inventoryItemId => $requestedQty) {
            if ($requestedQty <= 0) {
                continue;
            }

            $inventoryItem = InventoryItem::find($inventoryItemId);
            if (!$inventoryItem) {
                continue;
            }

            if ((float) $inventoryItem->current_stock < $requestedQty) {
                $insufficientItems[] = $inventoryItem->name;
            }
        }

        return array_values(array_unique($insufficientItems));
    }

    protected function validateBookingInventoryAvailability(Booking $booking, ?string $status): bool
    {
        if (!in_array($status, ['downpayment_received', 'confirmed', 'fully_paid'], true)) {
            return true;
        }

        $reusableIds = $booking->reusableBookingItems()->pluck('inventory_item_id')->map(fn ($id) => (int) $id)->unique();
        foreach ($this->confirmedInventoryItems($booking) as $inventoryItem) {
            // Fresh flowers are perishable procurement requirements, not date-overlap locks
            if (!$reusableIds->contains((int) $inventoryItem->id)) {
                continue;
            }

            $requiredQty = (float) ($inventoryItem->pivot->quantity ?? 0);
            if ($requiredQty <= 0) {
                continue;
            }

            $existingConfirmedDemand = Booking::query()
                ->whereDate('event_date', $booking->event_date->toDateString())
                ->whereIn('status', ['downpayment_received', 'confirmed', 'in_preparation', 'event_in_progress'])
                ->where('id', '!=', $booking->id)
                ->with('inventoryItems')
                ->get()
                ->sum(function ($existingBooking) use ($inventoryItem) {
                    $pivot = $existingBooking->inventoryItems->firstWhere('id', $inventoryItem->id);

                    return $pivot ? (float) $pivot->pivot->quantity : 0;
                });

            $availableAfterOverlap = (float) $inventoryItem->current_stock - $existingConfirmedDemand;
            if ($availableAfterOverlap < $requiredQty) {
                return false;
            }
        }

        return true;
    }

    public function storeProposal(Request $request, Booking $booking): RedirectResponse
    {
        $request->validate([
            'proposal_file' => ['required', 'file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png', 'max:20480'],
        ]);

        $file = $request->file('proposal_file');
        $path = $file->store('bookings/proposals', 'public');

        $version = $booking->presentations()->count() > 0 ? 'v' . ((int) $booking->presentations()->count() + 1) : 'v1';

        $presentation = Presentation::create([
            'booking_id' => $booking->id,
            'version' => $version,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'sent_at' => now(),
            'sent_by' => Auth::id(),
            'status' => 'sent',
        ]);

        $this->logAuditEvent('booking', 'proposal_uploaded', $booking, 'Uploaded proposal ' . $presentation->version, Auth::id());

        // Notify client via in-app notification and attempt email
        $noteMessage = 'A new proposal (' . $presentation->version . ') has been uploaded for your booking. Please review the proposal versions and provide feedback.';
        $this->createClientNotificationForBooking($booking, 'New proposal uploaded', $noteMessage, 'proposal');

        try {
            if ($booking->client && filter_var($booking->client->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($booking->client->email)->send(new BookingStatusChanged($booking, null, 'proposal_uploaded'));
            }
        } catch (\Throwable $e) {
            logger()->warning('Proposal upload notification email failed: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Proposal uploaded successfully.');
    }

    /**
     * README Client Workflow: complete the "Raflora Review" stage so the booking moves to
     * "Material Preparation / Validation".
     */
    public function completeReview(Request $request, Booking $booking): RedirectResponse
    {
        if (Auth::user()?->role !== 'admin') {
            abort(403, 'Only an authorized admin can complete the Raflora review.');
        }

        if (is_null($booking->client_id)) {
            return redirect()->back()->with('error', 'This booking must be claimed by a client account before Raflora Review can be completed.');
        }

        if ($booking->status !== 'pending') {
            return redirect()->back()->with('error', 'Raflora Review can only be completed while the booking is pending review.');
        }

        if ($booking->isReviewed()) {
            return redirect()->back()->with('success', 'Raflora Review is already complete for this booking.');
        }

        $booking->markReviewed(Auth::id());
        $booking->save();

        $this->logAuditEvent('booking', 'raflora_review_completed', $booking, 'Raflora Review completed; material preparation and validation started', Auth::id(), null, null, 'raflora_review_completed');

        $this->createClientNotificationForBooking(
            $booking,
            'Raflora Review Complete',
            'Raflora has reviewed your event details and is now preparing and validating the materials for your booking.',
            'booking_update'
        );

        return redirect()->back()->with('success', 'Raflora Review completed. Material preparation and validation can now proceed.');
    }

    /**
     * Validate a status change submitted directly through the booking form.
     * Returns an error message when the change would bypass the booking workflow, or null.
     */
    protected function illegalRawStatusTransition(Booking $booking, ?string $from, string $to): ?string
    {
        $from = Booking::normalizeStatus($from) ?? 'pending';

        if ($from === $to) {
            return null;
        }

        if (in_array($from, ['completed', 'cancelled', 'declined', 'rejected'], true)) {
            return 'This booking is closed (' . $booking->status_display_label . '), so its status can no longer be changed.';
        }

        if ($booking->isDelivery() && in_array($to, ['event_completed', 'pending_return', 'pending_resolution', 'completed'], true)) {
            return 'Delivery orders have no return audit. Use "Mark Item Received" once the client has the items.';
        }

        return match ($to) {
            'declined', 'cancelled', 'rejected' => $this->hasPaymentAwaitingVerification($booking)
                ? 'The client submitted a payment reference that is still waiting for verification. Verify or reject it before closing this booking.'
                : null,
            'downpayment_received', 'confirmed', 'fully_paid' => $booking->total_paid > 0
                ? null
                : 'A booking can only be confirmed after a payment has been verified. Verify the client\'s submitted payment instead.',
            'quotation_sent' => 'Use "Approve & Send Official Quote" so the official quotation is issued and recorded.',
            'admin_approved' => 'Use "Final Approve & Enable Payment" on a quotation the client has accepted.',
            'approved', 'change_requested', 'cancellation_requested', 'payment_submitted', 'payment_pending'
                => 'This status is set by the client\'s own action and cannot be assigned by Admin.',
            'event_in_progress' => 'Use "Mark Event In Progress" so fresh-flower readiness, dispatch, and inventory locks are checked.',
            'event_completed', 'pending_return', 'pending_resolution' => in_array($from, ['event_in_progress', 'event_completed', 'pending_return', 'pending_resolution'], true)
                ? null
                : 'The event must be in progress before it can move to post-event settlement.',
            // Completion keeps its dedicated guard (post-event stage, zero balance, completed return audit).
            'completed' => null,
            'pending' => in_array($from, ['change_requested', 'cancellation_requested'], true)
                ? null
                : 'A booking cannot be moved back to pending review from its current stage.',
            default => null,
        };
    }

    /**
     * A material validation action implies the Raflora Review stage is complete.
     * Records the review once (pending / change_requested bookings only).
     */
    protected function recordReviewFromMaterialAction(Booking $booking, string $trigger): void
    {
        if (!in_array($booking->status, ['pending', 'change_requested'], true)) {
            return;
        }

        if ($booking->markReviewed(Auth::id())) {
            $booking->save();
            $this->logAuditEvent('booking', 'raflora_review_completed', $booking, 'Raflora Review completed via ' . $trigger, Auth::id(), null, null, 'raflora_review_completed');
        }
    }

    public function finalApproveQuotation(Request $request, Booking $booking): RedirectResponse
    {
        if ($booking->status !== 'approved') {
            return redirect()->back()->with('error', 'This booking is not awaiting Admin final approval.');
        }

        $booking->setNormalizedStatus('admin_approved')->save();
        $this->logAuditEvent('booking', 'quotation_final_approved', $booking, 'Admin final-approved the accepted quotation', Auth::id());

        $this->createClientNotificationForBooking(
            $booking,
            'Quotation Approved — Payment Unlocked',
            'Your accepted quotation has been approved by Raflora. You may now submit your downpayment reference to secure your booking.',
            'booking_update'
        );

        return redirect()->back()->with('success', 'Quotation final-approved. Payment submission is now available.');
    }

    /**
     * Link an AI-suggested booking item to an existing inventory item.
     * This route validates only item-specific inputs.
     */
    public function linkAiItem(Request $request, Booking $booking, ?BookingItem $bookingItem = null): RedirectResponse
    {
        if (Auth::user()?->role !== 'admin') {
            abort(403, 'Only an authorized admin can link material suggestions.');
        }

        $request->validate([
            // Archived items are not linkable: restore them first.
            'link_inventory_item_id' => ['required', \Illuminate\Validation\Rule::exists('inventory_items', 'id')->whereNull('deleted_at')],
        ], [
            'link_inventory_item_id.exists' => 'That inventory item is archived or no longer exists. Restore it from Archived Items first.',
        ]);
        $request->validate([
            'ai_item_name' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        // If booking item wasn't routed, allow resolving by ai_item_name
        $bookingItem = $this->resolveAiSuggestedBookingItem($request, $booking, $bookingItem);

        // A row shown straight from the AI analysis has no booking line yet: create it for a name
        // this booking's analysis really suggested, then link it.
        if (!$bookingItem && !$request->filled('booking_item_id') && $request->filled('ai_item_name')
            && ($suggestion = $this->analysisSuggestionNamed($booking, (string) $request->input('ai_item_name')))) {
            $bookingItem = $this->createLineFromAiSuggestion($request, $booking, $suggestion);
        }

        if (!$bookingItem) {
            return redirect()->back()->with('error', 'AI suggested item could not be located.');
        }

        $linkId = $request->input('link_inventory_item_id');
        $bookingItem->inventory_item_id = $linkId;
        $bookingItem->item_name = InventoryItem::find($linkId)?->name ?? $bookingItem->item_name;
        $bookingItem->save();

        $this->logAuditEvent('booking', 'ai_item_linked', $booking, 'Linked AI suggestion "' . $bookingItem->item_name . '" to inventory item "' . (InventoryItem::find($linkId)?->name ?? 'unknown') . '" (#' . $linkId . ')', Auth::id());
        $this->recordReviewFromMaterialAction($booking, 'material linking');

        return redirect()->back()->with('success', 'AI suggestion linked to master inventory.');
    }

    /**
     * Promote an AI-suggested booking item into the master inventory.
     */
    public function promoteAiItem(Request $request, Booking $booking, ?BookingItem $bookingItem = null): RedirectResponse
    {
        if (Auth::user()?->role !== 'admin') {
            abort(403, 'Only an authorized admin can promote material suggestions.');
        }

        $request->validate([
            'catalog_name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'is_perishable' => ['nullable', 'boolean'],
            'booking_item_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'numeric', 'min:0.01'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'confirm_distinct_catalog_item' => ['nullable', 'boolean'],
        ]);

        $bookingItem = $this->resolveAiSuggestedBookingItem($request, $booking, $bookingItem);

        // A row shown straight from the AI analysis has no booking line yet; it is created below,
        // only for a name this booking's analysis really suggested.
        $suggestion = (!$bookingItem && !$request->filled('booking_item_id') && $request->filled('ai_item_name'))
            ? $this->analysisSuggestionNamed($booking, (string) $request->input('ai_item_name'))
            : null;

        // A named material that is not on this booking is an invalid request, not a new item.
        if (!$bookingItem && !$suggestion && ($request->filled('booking_item_id') || $request->filled('ai_item_name'))) {
            return redirect()->back()->with('error', 'That material is not part of this booking.');
        }

        $existing = InventoryItem::catalogMatches(
            (string) $request->input('catalog_name'),
            unit: (string) $request->input('unit', 'piece')
        )['exact'];
        if (($bookingItem || $suggestion) && $existing && !$existing->trashed()) {
            $bookingItem ??= $this->createLineFromAiSuggestion($request, $booking, $suggestion);
            // Already in the catalog: link this line to it instead of adding a duplicate item.
            $bookingItem->inventory_item_id = $existing->id;
            $bookingItem->item_name = $existing->name;
            $bookingItem->save();

            $this->logAuditEvent('booking', 'ai_item_linked', $booking, 'Linked AI suggestion to existing inventory item "' . $existing->name . '" (#' . $existing->id . '): Promote matched an existing catalog name', Auth::id());
            $this->recordReviewFromMaterialAction($booking, 'material linking');

            return redirect()->back()->with('success', "\"{$existing->name}\" is already in inventory, so this material was linked to it instead of adding a duplicate.");
        }

        if ($duplicateError = $this->duplicateCatalogNameError(
            (string) $request->input('catalog_name'),
            $request->boolean('confirm_distinct_catalog_item')
        )) {
            return redirect()->back()->with('error', $duplicateError);
        }

        if (!$bookingItem && $suggestion) {
            $bookingItem = $this->createLineFromAiSuggestion($request, $booking, $suggestion);
        }

        if (!$bookingItem) {
            Log::warning('promoteAiItem: could not locate AI suggested booking item', ['request' => $request->all(), 'booking_id' => $booking->id]);
            $latestAnalysis = $booking->aiAnalyses()->latest('analyzed_at')->first();
            Log::info('promoteAiItem: latest AI analysis for booking', ['analysis' => $latestAnalysis?->toArray()]);

            // Create master inventory item regardless of AI match
            $inventoryItem = InventoryItem::create([
                'name' => trim($request->input('catalog_name')),
                'category' => $request->input('category', 'misc'),
                'is_perishable' => (bool) $request->input('is_perishable', false),
                'current_stock' => 0,
                'unit_cost' => (float) ($request->input('unit_cost') ?? 0),
                'min_stock' => 0,
                'unit' => $request->input('unit', 'piece'),
            ]);

            // Create a booking item that references the new inventory item so procurement/pivot sync works
            $bookingItem = BookingItem::create([
                'booking_id' => $booking->id,
                'inventory_item_id' => $inventoryItem->id,
                'item_name' => $inventoryItem->name,
                'quantity' => max(1, (float) ($request->input('quantity') ?? 1)),
                'quoted_unit_price' => (float) ($request->input('unit_price') ?? 0),
                'is_ai_suggested' => 1,
            ]);

            $this->logAuditEvent('booking', 'ai_item_promoted', $booking, 'Promoted AI suggestion to new inventory item "' . $inventoryItem->name . '" (#' . $inventoryItem->id . ')', Auth::id());
            $this->recordReviewFromMaterialAction($booking, 'material promotion');

            return redirect()->back()->with('success', 'AI suggestion promoted into inventory catalog.');
        }

        // Normal path when booking item resolved - use submitted values when provided
        $inventoryItem = InventoryItem::create([
            'name' => trim($request->input('catalog_name')),
            'category' => $request->input('category', 'misc'),
            'is_perishable' => (bool) $request->input('is_perishable', false),
            'current_stock' => 0,
            'unit_cost' => (float) ($request->input('unit_cost') ?? $bookingItem->quoted_unit_price),
            'min_stock' => 0,
            'unit' => $request->input('unit', 'piece'),
        ]);

        $bookingItem->inventory_item_id = $inventoryItem->id;
        $bookingItem->item_name = $inventoryItem->name;
        $bookingItem->save();

        $this->logAuditEvent('booking', 'ai_item_promoted', $booking, 'Promoted AI suggestion to new inventory item "' . $inventoryItem->name . '" (#' . $inventoryItem->id . ')', Auth::id());
        $this->recordReviewFromMaterialAction($booking, 'material promotion');

        return redirect()->back()->with('success', 'AI suggestion promoted into inventory catalog.');
    }

    public function confirmMaterial(Request $request, Booking $booking, BookingItem $bookingItem): RedirectResponse
    {
        if (Auth::user()?->role !== 'admin') {
            abort(403, 'Only an authorized admin can confirm material requirements.');
        }

        $bookingItem = $booking->bookingItems()->whereKey($bookingItem->id)->firstOrFail();
        if ($bookingItem->confirmed_at && !$request->has('booking_item_ids')) {
            return redirect()->back()->with('success', 'Material requirement is already confirmed.');
        }

        if (!$bookingItem->inventory_item_id) {
            return redirect()->back()->with('error', 'Link or promote this AI suggestion before confirming it.');
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'booking_item_ids' => ['sometimes', 'array', 'min:1'],
            'booking_item_ids.*' => ['required', 'integer', 'distinct'],
        ] + $this->typedLineRules(''), $this->typedLineMessages());
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->errors()->first() . ' Nothing was saved.');
        }
        $typed = $validator->validated();
        $lineIds = array_values(array_unique(array_map('intval', $typed['booking_item_ids'] ?? [$bookingItem->id])));
        if (!in_array((int) $bookingItem->id, $lineIds, true)) {
            return redirect()->back()->with('error', 'The grouped material selection is invalid. Nothing was saved.');
        }

        $lines = $booking->bookingItems()->whereIn('id', $lineIds)->orderBy('id')->get();
        if ($lines->count() !== count($lineIds)
            || $lines->contains(fn (BookingItem $line): bool => $line->confirmed_at !== null || !$line->inventory_item_id)
            || $lines->pluck('inventory_item_id')->unique()->count() !== 1
            || (int) $lines->first()->inventory_item_id !== (int) $bookingItem->inventory_item_id) {
            return redirect()->back()->with('error', 'Only unconfirmed lines linked to the same inventory item can be confirmed together. Nothing was saved.');
        }

        $typedLines = [];
        if (array_key_exists('quantity', $typed) || array_key_exists('unit_price', $typed)) {
            $quantity = array_key_exists('quantity', $typed) ? (int) $typed['quantity'] : (int) $lines->sum('quantity');
            $originalQuantity = (float) $lines->sum(fn (BookingItem $line) => (float) $line->quantity);
            $originalAmount = (float) $lines->sum(fn (BookingItem $line) => (float) $line->quantity * (float) $line->quoted_unit_price);
            $originalAveragePrice = $originalQuantity > 0 ? $originalAmount / $originalQuantity : null;
            $newPrice = array_key_exists('unit_price', $typed) ? round((float) $typed['unit_price'], 2) : null;
            $preserveSourcePrices = $newPrice !== null && $originalAveragePrice !== null
                && round($newPrice, 2) === round($originalAveragePrice, 2);
            $remainingQuantity = $quantity;

            foreach ($lines->values() as $index => $line) {
                $lineQuantity = $index === $lines->count() - 1
                    ? $remainingQuantity
                    : ($originalQuantity > 0
                        ? (int) floor($quantity * ((float) $line->quantity / $originalQuantity))
                        : (int) floor($quantity / $lines->count()));
                $remainingQuantity -= $lineQuantity;
                $typedLines[$line->id] = ['quantity' => $lineQuantity];
                if ($newPrice !== null) {
                    if (!$preserveSourcePrices) {
                        $typedLines[$line->id]['unit_price'] = $newPrice;
                    }
                }
            }
        }

        try {
            DB::transaction(function () use ($booking, $lines, $typedLines): void {
                $this->applyTypedLineValues($booking, $lines, $typedLines);
                foreach ($lines as $line) {
                    $this->confirmBookingItemRequirement($booking, $line);
                }
            });
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $this->recordReviewFromMaterialAction($booking, 'material confirmation');

        $count = $lines->count();
        $message = $count === 1
            ? 'Material requirement confirmed for this booking.'
            : $count . ' materials confirmed for this booking.';

        return redirect()->back()->with('success', $message);
    }

    /**
     * Validation for a quote row's typed Qty / Unit Cost ($prefix '' for one row, 'lines.*.' for many).
     */
    protected function typedLineRules(string $prefix): array
    {
        return [
            $prefix . 'quantity' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
            $prefix . 'unit_price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    protected function typedLineMessages(): array
    {
        return [
            '*.required' => 'Quantity and unit cost cannot be blank.',
            '*.integer' => 'Quantity must be a whole number.',
            '*.numeric' => 'Unit cost must be a number.',
            '*.min' => 'Quantity and unit cost cannot be negative.',
            '*.max' => 'The value is too large.',
        ];
    }

    /**
     * Save the Qty / Unit Cost Admin typed in the quote table onto these booking lines (used when
     * confirming materials). Only the booking's own lines change: inventory stock and the catalog
     * price are untouched. A quantity change is refused once stock for that item is already
     * reserved or dispatched for this booking, so the stock hold can never disagree with the line.
     * A refusal throws, so the caller's transaction rolls every row back (all or nothing).
     *
     * @param  \Illuminate\Support\Collection<int, BookingItem>  $lines
     * @param  array<int|string, array{quantity?: int|string, unit_price?: float|string}>  $typed  keyed by booking item id
     */
    protected function applyTypedLineValues(Booking $booking, \Illuminate\Support\Collection $lines, array $typed): void
    {
        $quoteReadOnly = in_array($booking->status, ['downpayment_received', 'event_in_progress', 'event_completed', 'pending_return', 'pending_resolution', 'completed', 'cancelled', 'declined', 'rejected'], true);

        foreach ($lines as $line) {
            $values = $typed[$line->id] ?? null;
            if (!is_array($values)) {
                continue;
            }

            $newQuantity = array_key_exists('quantity', $values) ? (float) $values['quantity'] : (float) $line->quantity;
            $newPrice = array_key_exists('unit_price', $values) ? round((float) $values['unit_price'], 2) : (float) $line->quoted_unit_price;
            $quantityChanged = round($newQuantity, 4) !== round((float) $line->quantity, 4);
            $priceChanged = round($newPrice, 2) !== round((float) $line->quoted_unit_price, 2);
            if (!$quantityChanged && !$priceChanged) {
                continue;
            }

            if ($quoteReadOnly) {
                throw new \RuntimeException('Quantities and prices can no longer be changed at this booking\'s stage (' . $booking->status_display_label . '). Nothing was saved.');
            }

            if ($quantityChanged && $line->inventory_item_id) {
                $stockMoved = InventoryTransaction::where('booking_id', $booking->id)
                    ->where('inventory_item_id', $line->inventory_item_id)
                    ->whereIn('transaction_type', ['booking_lock', 'dispatch', 'dispatch_correction'])
                    ->exists();
                if ($stockMoved) {
                    throw new \RuntimeException('Stock for "' . $line->item_name . '" is already reserved or dispatched for this booking, so its quantity cannot be changed here. Nothing was saved.');
                }
            }

            $old = ['quantity' => (float) $line->quantity, 'quoted_unit_price' => (float) $line->quoted_unit_price];
            $line->quantity = $newQuantity;
            $line->quoted_unit_price = $newPrice;
            $line->save();

            $this->logAuditEvent(
                'booking',
                'quote_line_updated',
                $booking,
                'Quote line "' . $line->item_name . '" saved on confirmation: qty ' . $old['quantity'] . ' → ' . $newQuantity . ', unit cost ₱' . number_format($old['quoted_unit_price'], 2) . ' → ₱' . number_format($newPrice, 2),
                Auth::id(),
                ['booking_item_id' => $line->id] + $old,
                ['booking_item_id' => $line->id, 'quantity' => $newQuantity, 'quoted_unit_price' => $newPrice],
                'quote_line_updated'
            );
        }
    }

    /**
     * Confirm every AI-suggested material that is already linked to inventory, one item at a time,
     * exactly as the per-row "Confirm Material" button does. Unmatched suggestions are left for linking.
     */
    public function confirmAllMaterials(Request $request, Booking $booking): RedirectResponse
    {
        if (Auth::user()?->role !== 'admin') {
            abort(403, 'Only an authorized admin can confirm material requirements.');
        }

        $readyItems = $booking->bookingItems()
            ->where('is_ai_suggested', true)
            ->whereNull('confirmed_at')
            ->whereNotNull('inventory_item_id')
            ->orderBy('id')
            ->get();

        if ($readyItems->isEmpty()) {
            return redirect()->back()->with('error', 'There are no linked materials waiting for confirmation.');
        }

        // The Qty and Unit Cost typed for these rows are saved with the confirmation; all or nothing.
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['lines' => (array) $request->input('lines', [])],
            ['lines' => ['array']] + $this->typedLineRules('lines.*.'),
            $this->typedLineMessages()
        );
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->errors()->first() . ' Nothing was saved.');
        }
        $typedLines = $validator->validated()['lines'] ?? [];

        try {
            DB::transaction(function () use ($booking, $readyItems, $typedLines): void {
                $this->applyTypedLineValues($booking, $readyItems, $typedLines);
                foreach ($readyItems as $bookingItem) {
                    $this->confirmBookingItemRequirement($booking, $bookingItem);
                }
            });
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
        $this->recordReviewFromMaterialAction($booking, 'material confirmation');

        $unmatchedCount = $booking->bookingItems()
            ->where('is_ai_suggested', true)
            ->whereNull('inventory_item_id')
            ->count();
        $message = $readyItems->count() . ' ' . Str::plural('material', $readyItems->count()) . ' confirmed for this booking.';
        if ($unmatchedCount > 0) {
            $message .= ' ' . $unmatchedCount . ' unmatched ' . Str::plural('suggestion', $unmatchedCount) . ' still ' . ($unmatchedCount === 1 ? 'needs' : 'need') . ' linking or promoting.';
        }

        return redirect()->back()->with('success', $message);
    }

    protected function confirmBookingItemRequirement(Booking $booking, BookingItem $bookingItem): void
    {
        $bookingItem->confirmed_at = now();
        $bookingItem->save();

        $this->logAuditEvent(
            'booking',
            'material_confirmed',
            $booking,
            'Confirmed material "' . $bookingItem->item_name . '" (line #' . $bookingItem->id . ')',
            Auth::id(),
            ['booking_item_id' => $bookingItem->id, 'confirmed_at' => null],
            ['booking_item_id' => $bookingItem->id, 'confirmed_at' => $bookingItem->confirmed_at?->toIso8601String()],
            'material_requirement_confirmed'
        );
    }

    /**
     * The row of this booking's latest AI analysis named $name (case, spacing and punctuation
     * ignored), or null. The quote editor lists those rows before they have a booking line.
     */
    protected function analysisSuggestionNamed(Booking $booking, string $name): ?array
    {
        $key = InventoryItem::nameKey($name);
        $materials = $booking->aiAnalyses()->latest('analyzed_at')->first()?->suggested_materials ?? [];

        foreach ((array) $materials as $material) {
            if (is_array($material) && $key !== '' && InventoryItem::nameKey($material['item_name'] ?? '') === $key) {
                return $material;
            }
        }

        return null;
    }

    /**
     * The booking line for an AI analysis row, made when Admin links or promotes it: the Qty and
     * Unit Cost typed in the row (or the AI's quantity), unconfirmed like any AI suggestion.
     */
    protected function createLineFromAiSuggestion(Request $request, Booking $booking, array $suggestion): BookingItem
    {
        $typedQuantity = $request->input('quantity');
        $aiQuantity = $suggestion['quantity'] ?? $suggestion['estimated_quantity'] ?? null;
        $quantity = is_numeric($typedQuantity) && (float) $typedQuantity > 0
            ? (float) $typedQuantity
            : (is_numeric($aiQuantity) && (float) $aiQuantity > 0 ? (float) $aiQuantity : 1.0);
        $typedPrice = $request->input('unit_price');
        $aiPrice = $suggestion['unit_cost_php'] ?? $suggestion['estimated_unit_cost_php'] ?? null;

        return BookingItem::create([
            'booking_id' => $booking->id,
            'item_name' => trim((string) $suggestion['item_name']),
            'quantity' => $quantity,
            'quoted_unit_price' => is_numeric($typedPrice) && (float) $typedPrice >= 0 ? round((float) $typedPrice, 2) : 0,
            'ai_recommended_price' => is_numeric($aiPrice) && (float) $aiPrice > 0 ? round((float) $aiPrice, 2) : null,
            'is_ai_suggested' => true,
            'procurement_status' => 'pending',
            'notes' => 'AI suggested',
        ]);
    }

    protected function resolveAiSuggestedBookingItem(Request $request, Booking $booking, ?BookingItem $bookingItem = null): ?BookingItem
    {
        if ($bookingItem) {
            return $bookingItem;
        }

        if ($request->filled('booking_item_id')) {
            $bookingItem = BookingItem::where('booking_id', $booking->id)
                ->where('id', $request->input('booking_item_id'))
                ->first();

            if ($bookingItem) {
                return $bookingItem;
            }
        }

        if ($request->filled('ai_item_name')) {
            $normalizedName = trim(strtolower($request->input('ai_item_name')));
            return BookingItem::where('booking_id', $booking->id)
                ->where('is_ai_suggested', 1)
                ->whereRaw('LOWER(TRIM(item_name)) = ?', [$normalizedName])
                ->first();
        }

        return null;
    }

    protected function confirmedInventoryItems(Booking $booking)
    {
        $bookingItems = $booking->bookingItems()->get();
        $unconfirmedInventoryIds = $bookingItems
            ->filter(fn ($bookingItem) => $bookingItem->confirmed_at === null)
            ->pluck('inventory_item_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $booking->inventoryItems()->get()->filter(function ($inventoryItem) use ($unconfirmedInventoryIds): bool {
            return !$unconfirmedInventoryIds->contains((int) $inventoryItem->id);
        })->values();
    }

    /**
     * Booking form actions that issue a quotation version.
     */
    private const QUOTATION_ISSUING_ACTIONS = ['send_quotation', 'reissue_quotation', 'reconfirm_price_revised'];

    public function update(Request $request, Booking $booking): RedirectResponse
    {
        // Issuing a quotation is one operation: when it is refused or fails, none of its writes
        // are kept, so the booking, its materials and any accepted quotation stay as they were.
        if (in_array($request->input('action'), self::QUOTATION_ISSUING_ACTIONS, true)) {
            return DB::transaction(fn (): RedirectResponse => $this->applyBookingUpdate($request, $booking));
        }

        return $this->applyBookingUpdate($request, $booking);
    }

    /**
     * Stop the booking update with an error message. Thrown rather than returned so that, inside
     * a quotation transaction (update()), every write made so far is rolled back.
     */
    protected function refuseBookingUpdate(string $message): never
    {
        // withInput(): the quote editor refills the Qty / Unit Cost Admin typed, so nothing is retyped.
        throw new HttpResponseException(redirect()->back()->withInput()->with('error', $message));
    }

    /**
     * The final price the Admin typed into "Final Quoted Total (Override)", or null to issue the
     * calculated total. The quote editor keeps that box in step with its own running total and
     * posts final_price_overridden=0 until the Admin types a price; that running total can count
     * rows that are not on the quotation (unconfirmed AI suggestions), so it never becomes the
     * price by itself.
     */
    protected function adminOverridePrice(Request $request): ?float
    {
        if ((string) $request->input('final_price_overridden') === '0' || !$request->filled('final_quoted_price')) {
            return null;
        }

        $price = (float) $request->input('final_quoted_price');

        return $price > 0 ? $price : null;
    }

    /**
     * Expand one consolidated quotation row back into its unchanged source booking lines.
     * The displayed total quantity can be adjusted, so distribute it proportionally while
     * applying the same quoted unit price and remove/substitution choice to every source line.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function expandGroupedQuotationRows(Booking $booking, array $items): array
    {
        $expanded = [];
        $seenBookingItemIds = [];

        foreach ($items as $item) {
            $sourceIds = array_values(array_unique(array_map('intval', (array) ($item['source_booking_item_ids'] ?? []))));
            if (count($sourceIds) < 2) {
                $expanded[] = $item;
                continue;
            }

            $representativeId = (int) ($item['booking_item_id'] ?? 0);
            if (!in_array($representativeId, $sourceIds, true)) {
                $this->refuseBookingUpdate('The submitted consolidated material row is invalid.');
            }

            if (array_intersect($seenBookingItemIds, $sourceIds)) {
                $this->refuseBookingUpdate('A booking material was submitted more than once.');
            }

            $lines = $booking->bookingItems()
                ->whereIn('id', $sourceIds)
                ->orderBy('id')
                ->get();

            if ($lines->count() !== count($sourceIds)
                || !$lines->first()?->inventory_item_id
                || $lines->pluck('inventory_item_id')->unique()->count() !== 1
                || $lines->pluck('is_ai_suggested')->unique()->count() !== 1
                || $lines->map(fn (BookingItem $line): bool => $line->confirmed_at !== null)->unique()->count() !== 1
                || $lines->map(fn (BookingItem $line): bool => $line->procurement_status === \App\Services\QuotationPricingService::REMOVED_FROM_QUOTATION)->unique()->count() !== 1) {
                $this->refuseBookingUpdate('Only lines linked to the same inventory item with matching review and removal status can be consolidated.');
            }

            $submittedQuantity = $item['quantity'] ?? null;
            if (!is_numeric($submittedQuantity) || (float) $submittedQuantity < 0) {
                $this->refuseBookingUpdate('The consolidated material quantity is invalid.');
            }

            $originalQuantity = (float) $lines->sum(fn (BookingItem $line) => (float) $line->quantity);
            $originalAmount = (float) $lines->sum(fn (BookingItem $line) => (float) $line->quantity * (float) $line->quoted_unit_price);
            $originalAveragePrice = $originalQuantity > 0 ? $originalAmount / $originalQuantity : null;
            $preserveSourcePrices = $originalAveragePrice !== null
                && is_numeric($item['unit_price'] ?? null)
                && round((float) $item['unit_price'], 2) === round($originalAveragePrice, 2);
            $remainingQuantity = round((float) $submittedQuantity, 2);
            $seenBookingItemIds = array_merge($seenBookingItemIds, $sourceIds);

            foreach ($lines->values() as $index => $line) {
                $isLast = $index === $lines->count() - 1;
                $quantity = $isLast
                    ? $remainingQuantity
                    : round(
                        $originalQuantity > 0
                            ? (float) $submittedQuantity * ((float) $line->quantity / $originalQuantity)
                            : (float) $submittedQuantity / $lines->count(),
                        2
                    );
                $remainingQuantity = round($remainingQuantity - $quantity, 2);

                $expandedItem = $item;
                $expandedItem['booking_item_id'] = $line->id;
                $expandedItem['quantity'] = $quantity;
                $expandedItem['is_ai_suggested'] = (bool) $line->is_ai_suggested;
                if ($preserveSourcePrices) {
                    $expandedItem['unit_price'] = (float) $line->quoted_unit_price;
                }
                if ((int) ($item['inventory_item_id'] ?? 0) === (int) $line->inventory_item_id) {
                    $expandedItem['item_name'] = $line->item_name;
                }
                unset($expandedItem['source_booking_item_ids']);
                $expanded[] = $expandedItem;
            }
        }

        return $expanded;
    }

    protected function applyBookingUpdate(Request $request, Booking $booking): RedirectResponse
    {
        if ($request->filled('resolve_ai_item') || $request->filled('ai_item_name')) {
            $bookingItem = null;

            if ($request->filled('resolve_ai_item')) {
                $bookingItem = $booking->bookingItems()->find($request->input('resolve_ai_item'));
            }

            if (!$bookingItem) {
                $bookingItem = $this->resolveAiSuggestedBookingItem($request, $booking, null);
            }

            if ($bookingItem) {
                $linkId = $request->input('link_inventory_item_id');
                if ($request->filled('link_inventory_item_id')) {
                    $bookingItem->inventory_item_id = $linkId;
                    $bookingItem->item_name = InventoryItem::find($linkId)?->name ?? $bookingItem->item_name;
                    $bookingItem->save();
                    $this->recordReviewFromMaterialAction($booking, 'material linking');

                    return redirect()->back()->with('success', 'AI suggestion linked to the selected inventory item.');
                }

                if ($request->filled('promote_inventory_name')) {
                    if ($duplicateError = $this->duplicateCatalogNameError((string) $request->input('promote_inventory_name'))) {
                        return redirect()->back()->with('error', $duplicateError);
                    }

                    $inventoryItem = InventoryItem::create([
                        'name' => trim($request->input('promote_inventory_name')),
                        'category' => $request->input('promote_inventory_category', 'misc'),
                        'is_perishable' => (bool) $request->input('promote_inventory_is_perishable', false),
                        'current_stock' => 0,
                        'unit_cost' => (float) $bookingItem->quoted_unit_price,
                        'min_stock' => 0,
                        'unit' => $request->input('promote_inventory_unit', 'piece'),
                    ]);

                    $bookingItem->inventory_item_id = $inventoryItem->id;
                    $bookingItem->item_name = $inventoryItem->name;
                    $bookingItem->save();
                    $this->recordReviewFromMaterialAction($booking, 'material promotion');

                    return redirect()->back()->with('success', 'AI suggestion promoted into the inventory catalog.');
                }
            }
        }
        $normalizedStatus = Booking::normalizeStatus($request->input('status'));
        if ($normalizedStatus !== null) {
            $request->merge(['status' => $normalizedStatus]);
        }

        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:255'],
            'event_date' => ['required', 'date'],
            'venue' => ['required', 'string', 'max:500'],
            'status' => ['required', 'string', 'in:pending,approved,rejected,event_in_progress,event_completed,pending_return,pending_resolution,completed,cancelled,quotation_sent,payment_submitted,payment_pending,confirmed,fully_paid,downpayment_received,declined,change_requested,cancellation_requested'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
            'admin_note' => ['nullable', 'string', 'max:1000'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
            'multiplier' => ['nullable', 'numeric', 'min:1'],
            'final_quoted_price' => ['nullable', 'numeric', 'min:0'],
            'final_price_overridden' => ['nullable', 'in:0,1'],
            'preparation_start_date' => ['nullable', 'date', 'before_or_equal:event_date'],
            'preparation_status' => ['nullable', 'string', 'in:scheduled,in_preparation,ready,completed'],
        ]);

        $proposedEventDate = Carbon::parse($data['event_date'])->startOfDay();
        $proposedPreparationDate = $request->exists('preparation_start_date')
            ? (filled($request->input('preparation_start_date')) ? Carbon::parse($data['preparation_start_date'])->startOfDay() : null)
            : $booking->preparation_start_date?->copy()->startOfDay();

        if ($request->exists('preparation_start_date') && filled($request->input('preparation_start_date'))) {
            if (!$booking->hasVerifiedPayment()) {
                return redirect()->back()->withErrors([
                    'preparation_start_date' => 'Preparation can only be scheduled after a down payment or full payment is verified.',
                ])->withInput();
            }

            if (!$booking->isPreparationDateAllowed($proposedPreparationDate, $proposedEventDate)) {
                return redirect()->back()->withErrors([
                    'preparation_start_date' => 'Choose a date from today through at least 14 days before the event.',
                ])->withInput();
            }
        } elseif ($proposedPreparationDate && !$booking->isPreparationDateAllowed($proposedPreparationDate, $proposedEventDate)) {
            return redirect()->back()->withErrors([
                'preparation_start_date' => 'The event date change makes the saved preparation date invalid. Choose a new preparation date at least 14 days before the event.',
            ])->withInput();
        }

        if ($request->filled('preparation_status') && !$booking->hasVerifiedPayment()) {
            return redirect()->back()->with('error', 'Operational preparation cannot start until a down payment or full payment is verified.');
        }

        // Batch promotion adds catalog items: refuse a duplicate name before anything is written.
        if ($request->input('action') === 'promote_selected') {
            foreach ((array) $request->input('items', []) as $it) {
                $catalogName = is_array($it) ? trim((string) ($it['promote_catalog_name'] ?? '')) : '';
                if ($catalogName !== '' && ($duplicateError = $this->duplicateCatalogNameError($catalogName))) {
                    return redirect()->back()->with('error', $duplicateError);
                }
            }
        }

        // The price the client has already been shown; working prices are never compared against.
        $sharedQuotation = $booking->sharedQuotation();
        $previousSharedPrice = $sharedQuotation ? (float) $sharedQuotation->final_quoted_price : null;
        $issuedQuotation = null;

        $oldStatus = $booking->status;
        $action = $request->input('action', 'save');

        // A status submitted with the form must match a real business event; workflow steps
        // with prerequisites (quotation issuance, client acceptance, payment verification,
        // event start) go through their own guarded actions.
        $normalizedOldStatus = Booking::normalizeStatus($oldStatus) ?? 'pending';
        $statusGoverningActions = [
            'send_quotation', 'reissue_quotation', 'reconfirm_price_unchanged', 'reconfirm_price_revised',
            'mark_event_in_progress', 'mark_event_completed', 'log_final_payment', 'accept',
        ];

        if (in_array($action, $statusGoverningActions, true)) {
            // These actions decide the resulting status from the booking's real current status;
            // a submitted status value is never trusted for them.
            $data['status'] = $normalizedOldStatus;
        } else {
            $transitionError = $this->illegalRawStatusTransition($booking, $oldStatus, $data['status']);
            if ($transitionError !== null) {
                return redirect()->back()->with('error', $transitionError);
            }
        }

        if ($action === 'mark_event_in_progress' && !in_array($normalizedOldStatus, ['downpayment_received', 'confirmed', 'fully_paid', 'in_preparation'], true)) {
            return redirect()->back()->with('error', 'Only a confirmed booking with a verified payment can be marked as in progress.');
        }
        if ($action === 'mark_event_in_progress' && !$booking->hasVerifiedPayment()) {
            return redirect()->back()->with('error', 'Only a booking with a verified down payment or full payment can be marked as in progress.');
        }
        if ($action === 'mark_event_completed' && $booking->isDelivery()) {
            return redirect()->back()->with('error', 'Delivery orders have no return audit. Use "Mark Item Received" once the client has the items.');
        }
        if ($action === 'mark_event_completed' && $normalizedOldStatus !== 'event_in_progress') {
            return redirect()->back()->with('error', 'Only an event in progress can be marked as completed.');
        }
        if ($action === 'log_final_payment' && !in_array($normalizedOldStatus, self::FINAL_PAYMENT_STATUSES, true)) {
            return redirect()->back()->with('error', 'A final payment can only be logged once the event is in progress or completed.');
        }
        if ($action === 'log_final_payment' && $this->hasPaymentAwaitingVerification($booking)) {
            return redirect()->back()->with('error', 'The client submitted a payment reference that is still waiting for verification. Verify or reject it before logging a final payment.');
        }
        if ($action === 'send_quotation') {
            $this->reconcileBookingInventoryLinks($booking);
            $booking->load('bookingItems.inventoryItem');


            $booking->bookingItems()
                ->where('is_ai_suggested', true)
                ->whereNull('confirmed_at')
                ->whereNotNull('inventory_item_id')
                ->where('quantity', '>', 0)
                ->update(['confirmed_at' => now()]);

            $unconfirmedAiItems = $booking->bookingItems()
                ->where('is_ai_suggested', true)
                ->whereNull('confirmed_at')
                ->where('quantity', '>', 0)
                ->pluck('item_name')
                ->filter()
                ->unique()
                ->values();

            if ($unconfirmedAiItems->isNotEmpty()) {
                $this->refuseBookingUpdate('Confirm every material requirement before sending the quotation.');
            }
        }
        $booking->event_type = $data['event_type'];
        $booking->event_date = $data['event_date'];
        $booking->venue = $data['venue'];
        $booking->setNormalizedStatus($data['status']);
        $booking->special_requests = $data['special_requests'] ?? $booking->special_requests;
        $booking->handled_by = Auth::id();

        if ($request->has('preparation_start_date')) {
            $booking->preparation_start_date = $request->input('preparation_start_date') ? Carbon::parse($request->input('preparation_start_date'))->toDateString() : null;
        }
        if ($request->filled('preparation_status')) {
            $booking->preparation_status = $request->input('preparation_status');
        }

        // The price itself is set by QuotationIssuanceService from the quotable items, or from a
        // price the Admin typed (adminOverridePrice()), so the booking always matches the quotation.
        $overridePrice = $this->adminOverridePrice($request);

        if ($action === 'send_quotation') {
            if (isset($data['multiplier'])) {
                $booking->multiplier = $data['multiplier'];
            }
        }

        // Process admin note from the booking review form.
        $adminNote = $this->sanitizeAdminNoteMessage(($data['admin_notes'] ?? $data['admin_note'] ?? '')) ?? '';

        // Process item adjustments if provided without appending stale raw item lists to the custom client note.
        $items = $request->input('items', []);
        if (is_array($items)) {
            $items = $this->expandGroupedQuotationRows($booking, $items);
        }
        $newRawMaterialsSum = 0;
        $latestAnalysis = $booking->aiAnalyses()->latest('analyzed_at')->first();
        $analysisMaterials = $latestAnalysis?->suggested_materials ?? [];
        $analysisModified = false;
        $substitutionAuditEntries = [];

        if (!empty($items) && is_array($items)) {
            foreach ($items as $it) {
                $name = trim((string) ($it['item_name'] ?? $it['name'] ?? 'Item'));
                $qty = isset($it['quantity']) ? (float) $it['quantity'] : (isset($it['adjusted_quantity']) ? (float) $it['adjusted_quantity'] : null);
                $qty = $qty !== null ? $qty : (isset($it['qty']) ? (float) $it['qty'] : null);
                $unitPrice = isset($it['unit_price']) ? (float) $it['unit_price'] : (isset($it['unit_cost']) ? (float) $it['unit_cost'] : null);
                $unavailable = !empty($it['unavailable']) || !empty($it['remove']);
                $inventoryItemId = !empty($it['inventory_item_id']) ? (int) $it['inventory_item_id'] : null;
                $isAiSuggested = !empty($it['is_ai_suggested']) || ($inventoryItemId === null && $name !== '');

                if (!empty($it['booking_item_id'])) {
                    $existingBookingItem = $booking->bookingItems()->find($it['booking_item_id']);
                    if (!$existingBookingItem) {
                        $this->refuseBookingUpdate('The submitted booking material does not belong to this booking.');
                    }

                    $isAiSuggested = (bool) $existingBookingItem->is_ai_suggested;
                }

                if (!empty($it['booking_item_id'])) {
                    $bookingItem = $booking->bookingItems()->find($it['booking_item_id']);
                    if ($bookingItem) {
                        $updated = false;

                        if ($name !== '' && $bookingItem->item_name !== $name) {
                            $bookingItem->item_name = $name;
                            $updated = true;
                        }

                        if ($inventoryItemId !== null && $bookingItem->inventory_item_id !== $inventoryItemId) {
                            $originalInventoryItem = $bookingItem->inventoryItem;
                            $replacementInventoryItem = InventoryItem::find($inventoryItemId);

                            if ($originalInventoryItem) {
                                if (!$replacementInventoryItem || !$originalInventoryItem->substitutes()->whereKey($replacementInventoryItem->id)->exists()) {
                                    $this->refuseBookingUpdate('The selected substitute is not designated for this material.');
                                }
                            } else {
                                if (!$replacementInventoryItem) {
                                    $this->refuseBookingUpdate('The selected inventory item is invalid.');
                                }
                            }

                            if (Auth::user()?->role !== 'admin') {
                                abort(403, 'Only an authorized admin can substitute booking materials.');
                            }

                            $substitutionAuditEntries[] = [
                                'booking_item_id' => $bookingItem->id,
                                'original_inventory_item_id' => $originalInventoryItem?->id,
                                'replacement_inventory_item_id' => $replacementInventoryItem->id,
                                'was_confirmed' => $bookingItem->confirmed_at !== null,
                            ];

                            $bookingItem->inventory_item_id = $replacementInventoryItem->id;
                            $bookingItem->item_name = $replacementInventoryItem->name;
                            $name = $replacementInventoryItem->name;
                            $unitPrice = (float) $replacementInventoryItem->unit_cost;
                            $bookingItem->confirmed_at = null;
                            $updated = true;
                        }

                        if ($action === 'send_quotation') {
                            if ($unavailable) {
                                $bookingItem->procurement_status = \App\Services\QuotationPricingService::REMOVED_FROM_QUOTATION;
                                $updated = true;
                            } elseif ($qty !== null && $qty <= 0) {
                                $bookingItem->procurement_status = 'out_of_stock';
                                $updated = true;
                            } elseif ($bookingItem->procurement_status === \App\Services\QuotationPricingService::REMOVED_FROM_QUOTATION || $bookingItem->procurement_status === 'out_of_stock') {
                                $bookingItem->procurement_status = 'pending';
                                $updated = true;
                            }
                        }

                        $isSubstitution = $inventoryItemId !== null && $bookingItem->inventory_item_id === $inventoryItemId && isset($replacementInventoryItem);
                        if (in_array($action, ['send_quotation', 'send_note']) || $isSubstitution) {
                            if ($qty !== null && (float) $bookingItem->quantity !== $qty) {
                                $bookingItem->quantity = $qty;
                                $updated = true;
                            }
                            if ($unitPrice !== null && (float) $bookingItem->quoted_unit_price !== $unitPrice) {
                                $bookingItem->quoted_unit_price = $unitPrice;
                                $updated = true;
                            }
                        }

                        if ($bookingItem->is_ai_suggested !== $isAiSuggested) {
                            $bookingItem->is_ai_suggested = $isAiSuggested;
                            $updated = true;
                        }

                        if ($updated) {
                            $bookingItem->save();

                            if (!empty($substitutionAuditEntries)) {
                                $substitution = end($substitutionAuditEntries);
                                if ($substitution['booking_item_id'] === $bookingItem->id) {
                                    $this->logAuditEvent(
                                        'booking',
                                        'material_substituted',
                                        $booking,
                                        'Substituted material on line #' . $bookingItem->id . ' with "' . $bookingItem->item_name . '"',
                                        Auth::id(),
                                        $substitution,
                                        array_merge($substitution, [
                                            'confirmed_at' => null,
                                            'replacement_unit_cost' => $unitPrice,
                                        ]),
                                        'material_substituted'
                                    );
                                }
                            }
                        }

                        continue;
                    }
                }

                if (!empty($analysisMaterials) && $name !== '') {
                    foreach ($analysisMaterials as $index => $analysisItem) {
                        if (!empty($analysisItem['item_name']) && strcasecmp(trim($analysisItem['item_name']), $name) === 0) {
                            if ($unavailable) {
                                unset($analysisMaterials[$index]);
                                $analysisModified = true;
                                break;
                            }

                            if ($qty !== null) {
                                $analysisMaterials[$index]['estimated_quantity'] = $qty;
                                $analysisModified = true;
                            }

                            if ($unitPrice !== null) {
                                $analysisMaterials[$index]['estimated_unit_cost_php'] = $unitPrice;
                                $analysisModified = true;
                            }

                            break;
                        }
                    }
                }

                if ($action === 'send_quotation') {
                    if ($unavailable) {
                        $bookingItem = BookingItem::create([
                            'booking_id' => $booking->id,
                            'inventory_item_id' => $inventoryItemId,
                            'item_name' => $name,
                            'quantity' => $qty !== null ? max(0, $qty) : 0,
                            'quoted_unit_price' => $unitPrice !== null ? $unitPrice : 0,
                            'is_ai_suggested' => $isAiSuggested,
                            'procurement_status' => \App\Services\QuotationPricingService::REMOVED_FROM_QUOTATION,
                        ]);
                        continue;
                    }
                } else {
                    if ($unavailable) {
                        continue;
                    }
                }

                $inventoryItem = null;
                if ($inventoryItemId) {
                    $inventoryItem = InventoryItem::find($inventoryItemId);
                }

                if (!$inventoryItem && $name !== '' && !$isAiSuggested) {
                    $inventoryItem = InventoryItem::where('name', $name)->first();
                }

                // A line without a catalog item stays unlinked (and unconfirmed) for Admin to link or
                // promote explicitly; free text never creates a catalog item. 'unmatched' is not used
                // here because it marks a line removed from the quotation.
                if ($action === 'send_quotation') {
                    $bookingItem = BookingItem::create([
                        'booking_id' => $booking->id,
                        'inventory_item_id' => $inventoryItem?->id,
                        'item_name' => $name,
                        'quantity' => $qty !== null ? max(0, $qty) : 0,
                        'quoted_unit_price' => $unitPrice !== null ? $unitPrice : 0,
                        'is_ai_suggested' => $isAiSuggested,
                        'procurement_status' => ($qty !== null && $qty <= 0) ? 'out_of_stock' : 'pending',
                    ]);
                } else {
                    // Do not create new items if not sending quotation
                    $bookingItem = null;
                }

            }

            if ($latestAnalysis && $analysisModified) {
                $latestAnalysis->suggested_materials = array_values($analysisMaterials);
                $latestAnalysis->save();
            }

            // Process batch promotions submitted inline in the main form
            if ($request->input('action') === 'promote_selected') {
                foreach ($items as $it) {
                    if (!empty($it['promote_catalog_name'])) {
                        $catName = trim((string) $it['promote_catalog_name']);
                        if ($catName === '') continue;

                        $inventoryItem = InventoryItem::create([
                            'name' => $catName,
                            'category' => $it['promote_category'] ?? 'misc',
                            'is_perishable' => !empty($it['promote_is_perishable']),
                            'current_stock' => 0,
                            'unit_cost' => (float) ($it['unit_price'] ?? 0),
                            'min_stock' => 0,
                            'unit' => $it['promote_unit'] ?? 'piece',
                        ]);

                        if (!empty($it['booking_item_id'])) {
                            $bItem = BookingItem::find($it['booking_item_id']);
                            if ($bItem) {
                                $bItem->inventory_item_id = $inventoryItem->id;
                                $bItem->item_name = $inventoryItem->name;
                                $bItem->save();
                            }
                        } else {
                            $bItem = BookingItem::create([
                                'booking_id' => $booking->id,
                                'inventory_item_id' => $inventoryItem->id,
                                'item_name' => $inventoryItem->name,
                                'quantity' => max(1, (float) ($it['quantity'] ?? 1)),
                                'quoted_unit_price' => (float) ($it['unit_price'] ?? 0),
                                'is_ai_suggested' => 1,
                            ]);
                        }

                    }
                }
            }

            $newRawMaterialsSum = 0;
            $updatedPivots = $this->confirmedInventoryItems($booking);
            foreach ($updatedPivots as $pivotItem) {
                $finalQty = $pivotItem->pivot->quantity;
                $finalUnitCost = $pivotItem->pivot->quoted_unit_price;
                $newRawMaterialsSum += ($finalQty * $finalUnitCost);
            }
            $booking->raw_materials_sum = $newRawMaterialsSum;
        }

        // Price lines are added below only if this save issues a quotation.
        $generatedAdjustmentNote = $this->appendGeneratedAdjustmentNote($adminNote, $items);

        if (array_key_exists('admin_notes', $data) || array_key_exists('admin_note', $data)) {
            $booking->admin_notes = $generatedAdjustmentNote !== '' ? $generatedAdjustmentNote : null;
        }

        if ($data['status'] === 'declined' && $booking->wasChanged('status')) {
            $booking->cancellation_reason = $adminNote ?: $booking->cancellation_reason;
        }

        // Handle action buttons
        $notificationStatus = null;

        if ($action === 'send_quotation') {
            $customValidUntil = $request->filled('valid_until') ? (string) $request->input('valid_until') : null;

            try {
                $issuedQuotation = app(\App\Services\QuotationIssuanceService::class)->issue($booking, Auth::id(), $customValidUntil, overridePrice: $overridePrice);
            } catch (\RuntimeException $e) {
                // Rolls back this request's item, price and status changes (update()).
                $this->refuseBookingUpdate($e->getMessage());
            }

            // Issuing the official quotation implies Raflora Review and material validation are complete.
            $reviewRecordedByIssuance = in_array($oldStatus, ['pending', 'change_requested'], true)
                && $booking->markReviewed(Auth::id());
            $booking->setNormalizedStatus('quotation_sent');
            $notificationStatus = 'quotation_sent';
        } elseif ($action === 'mark_event_in_progress') {
            $hasFreshFlowers = $booking->freshProcurementItems()->isNotEmpty();

            if ($hasFreshFlowers && !$booking->areFreshFlowersReady()) {
                $booking->status = $oldStatus;
                $booking->save();
                return redirect()->back()->with('error', 'Unable to start the event: fresh flower procurement readiness must be confirmed by Admin before execution.');
            }

            if (!$this->hasRequiredDispatchOccurred($booking)) {
                $booking->status = $oldStatus;
                $booking->save();
                return redirect()->back()->with('error', 'Cannot start the event because required reusable materials have not been dispatched.');
            }

            $reusableIds = $booking->reusableBookingItems()->pluck('inventory_item_id')->map(fn ($id) => (int) $id)->unique();
            foreach ($this->confirmedInventoryItems($booking) as $inventoryItem) {
                if (!$reusableIds->contains((int) $inventoryItem->id)) {
                    continue;
                }

                $requiredQty = (float) ($inventoryItem->pivot->quantity ?? 0);
                if ($requiredQty <= 0) {
                    continue;
                }

                $existingReservation = InventoryTransaction::query()
                    ->where('booking_id', $booking->id)
                    ->where('inventory_item_id', $inventoryItem->id)
                    ->where('transaction_type', 'booking_lock')
                    ->where('quantity_change', '<', 0)
                    ->exists();

                if ($existingReservation) {
                    continue;
                }

                $booking->status = $oldStatus;
                $booking->save();

                return redirect()->back()->with('error', 'Unable to start the event because confirmed inventory has not been locked for: ' . $inventoryItem->name . '.');
            }

            $booking->setNormalizedStatus('event_in_progress');
        } elseif ($action === 'mark_event_completed') {
            if (!$this->hasRequiredDispatchOccurred($booking)) {
                $booking->status = $oldStatus;
                return redirect()->back()->with('error', 'Cannot mark event completed: no materials have been dispatched.');
            }

            $booking->setNormalizedStatus('event_completed');
            if ($booking->remaining_balance <= 0.0) {
                $booking->setNormalizedStatus('pending_return');
            }
        } elseif ($action === 'log_final_payment') {
            $remainingToPay = (float) $booking->remaining_balance;
            if ($remainingToPay > 0) {
                $payment = Payment::create([
                    'booking_id' => $booking->id,
                    'quotation_id' => $booking->acceptedQuotation?->id,
                    'amount' => $remainingToPay,
                    'payment_option' => 'full_payment',
                    'amount_paid' => $remainingToPay,
                    'remaining_balance' => 0.00,
                    'payment_type' => 'cash',
                    'reference_number' => 'admin-final-' . $booking->id . '-' . time() . '-' . rand(100, 999),
                    'status' => 'fully_paid',
                    'recorded_by' => Auth::id(),
                    'verified_by' => Auth::id(),
                    'verified_at' => now(),
                ]);

                $this->logAuditEvent('booking', 'payment_verified', $booking, 'Final payment logged by admin', Auth::id(), null, ['payment' => $payment->toArray()], 'final_payment_logged');
            }

            $hasPendingResolution = $booking->returns()
                ->whereHas('returnItems', function ($q) {
                    $q->where('charge_decision', 'pending')
                        ->where(function ($sub) {
                            $sub->whereIn('condition', ['damaged', 'lost', 'mixed'])
                                ->orWhere('quantity_damaged', '>', 0)
                                ->orWhere('quantity_lost', '>', 0);
                        });
                })->exists();

            $hasCompletedReturn = $booking->returns()->where('status', 'Completed')->exists();
            $hasIncompleteReturn = $booking->returns()->where('status', '!=', 'Completed')->exists();
            
            if ($booking->isDelivery()) {
                // No return audit for delivery orders: completed once received and paid (DeliveryOrderService).
                $booking->setNormalizedStatus(app(\App\Services\DeliveryOrderService::class)->statusAfterPayment($booking->fresh(), $normalizedOldStatus));
            } elseif ($hasPendingResolution) {
                $booking->setNormalizedStatus('pending_resolution');
            } elseif ($hasCompletedReturn && !$hasIncompleteReturn && $booking->fresh()->remaining_balance <= 0) {
                $booking->setNormalizedStatus('completed');
            } elseif ($hasCompletedReturn && !$hasIncompleteReturn) {
                $booking->setNormalizedStatus('event_completed');
            } else {
                $booking->setNormalizedStatus('pending_return');
            }

            $booking->save();
            $this->ensureReturnRecordForBooking($booking);
        } elseif ($action === 'reissue_quotation') {
            $customValidUntil = $request->filled('valid_until') ? (string) $request->input('valid_until') : null;

            try {
                $issuedQuotation = app(\App\Services\QuotationIssuanceService::class)->issue($booking, Auth::id(), $customValidUntil, overridePrice: $overridePrice);
            } catch (\RuntimeException $e) {
                // Rolls back this request's changes; an accepted quotation stays untouched (update()).
                $this->refuseBookingUpdate($e->getMessage());
            }

            // Admin re-issues the quotation with refreshed pricing window
            $booking->setNormalizedStatus('quotation_sent');
            $notificationStatus = 'quotation_sent';
            // Dismiss any related expired quotation alerts
            \App\Models\AdminAlert::where('type', 'quotation_expired')
                ->where('booking_id', $booking->id)
                ->where('is_read', false)
                ->update(['is_read' => true]);
        } elseif ($action === 'reconfirm_price_unchanged') {
            $tentativeQuote = $booking->tentativeQuotation();
            if (!$tentativeQuote) {
                return redirect()->back()->with('error', 'No tentative quotation pending reconfirmation found for this booking.');
            }

            $tentativeQuote->is_tentative = false;
            $tentativeQuote->reconfirmed_at = now();
            $tentativeQuote->save();

            \App\Models\AdminAlert::where('type', 'price_reconfirmation_due')
                ->where('booking_id', $booking->id)
                ->where('is_read', false)
                ->update(['is_read' => true]);

            \App\Models\AuditLog::record(
                Auth::id(),
                'price_reconfirmed_unchanged',
                sprintf('Quotation v%d price reconfirmed unchanged at ₱%s by Admin.', $tentativeQuote->version, number_format((float) $tentativeQuote->final_quoted_price, 2)),
                'quotation_reconfirmation',
                [
                    'booking_id' => $booking->id,
                    'quotation_id' => $tentativeQuote->id,
                    'version' => $tentativeQuote->version,
                    'final_quoted_price' => (float) $tentativeQuote->final_quoted_price,
                ]
            );

            // Preserve existing booking status
            $booking->status = $oldStatus;
            $booking->save();

            return redirect()->back()->with('success', 'Price reconfirmed successfully. Current pricing remains in effect.');
        } elseif ($action === 'reconfirm_price_revised') {
            $customValidUntil = $request->filled('valid_until') ? (string) $request->input('valid_until') : null;

            try {
                $newQuote = app(\App\Services\QuotationIssuanceService::class)->reconfirmWithRevision($booking, Auth::id(), $customValidUntil, $overridePrice);
            } catch (\RuntimeException $e) {
                // Rolls back this request's changes (update()).
                $this->refuseBookingUpdate($e->getMessage());
            }

            $booking->setNormalizedStatus('quotation_sent');
            $notificationStatus = 'quotation_sent';

            return redirect()->back()->with('success', 'Revised quotation v' . $newQuote->version . ' issued and sent to client for review and acceptance.');
        } elseif ($action === 'accept') {
            if ($booking->status !== 'approved') {
                return redirect()->back()->with('error', 'Only an accepted quotation can be final-approved.');
            }

            $booking->setNormalizedStatus('admin_approved');
            $notificationStatus = 'admin_approved';
        } elseif ($data['status'] === 'declined' && $booking->wasChanged('status')) {
            $notificationStatus = 'declined';
        }

        if ($issuedQuotation) {
            $generatedAdjustmentNote = $this->appendGeneratedAdjustmentNote(
                $adminNote,
                $items,
                $this->buildPriceAdjustmentSummary($previousSharedPrice, (float) $issuedQuotation->final_quoted_price)
            );

            if (array_key_exists('admin_notes', $data) || array_key_exists('admin_note', $data)) {
                $booking->admin_notes = $generatedAdjustmentNote !== '' ? $generatedAdjustmentNote : null;
            }
        }

        if ($booking->status !== $oldStatus
            && in_array($booking->status, ['cancelled', 'declined', 'rejected'], true)
            && in_array($oldStatus, ['downpayment_received', 'confirmed', 'in_preparation', 'event_in_progress', 'event_completed'], true)) {
            $this->releaseInventoryForBooking($booking);

            if ($booking->hasDispatchedReusableMaterials()) {
                $this->ensureReturnRecordForBooking($booking);
            }
        }

        if ($booking->status !== $oldStatus && !in_array($booking->status, ['pending', 'quotation_sent', 'approved', 'rejected', 'event_in_progress', 'completed', 'pending_return', 'pending_resolution', 'cancelled', 'declined'], true)) {
            if (!$this->validateBookingInventoryAvailability($booking, $booking->status)) {
                $booking->status = $oldStatus;

                return redirect()->back()->with('error', 'This booking would exceed the available stock for the selected event date.');
            }

            $this->adjustInventoryForBookingStatus($booking, $oldStatus, $booking->status);
        }

        if (in_array($booking->status, ['event_completed', 'pending_return', 'pending_resolution', 'completed'], true) 
            && !in_array($oldStatus, ['event_completed', 'pending_return', 'pending_resolution', 'completed'], true)) {
            if (!$this->hasRequiredDispatchOccurred($booking)) {
                $booking->status = $oldStatus;
                return redirect()->back()->with('error', 'Cannot transition to completion state: no materials have been dispatched.');
            }

            $this->releaseInventoryForBooking(
                $booking,
                'Released unfulfilled reservation upon event completion for booking #' . $booking->id
            );

            if ($booking->hasDispatchedReusableMaterials()) {
                $this->ensureReturnRecordForBooking($booking);
            }
        }

        if ($booking->status === 'completed' && $oldStatus !== 'completed') {
            if (!in_array($oldStatus, ['event_completed', 'pending_return', 'pending_resolution'], true)) {
                $booking->status = $oldStatus;
                return redirect()->back()->with('error', 'Cannot complete booking: the event has not concluded or reached post-event settlement.');
            }

            $remainingBal = (float) $booking->remaining_balance;
            if ($remainingBal > 0) {
                $booking->status = $oldStatus;
                return redirect()->back()->with('error', 'Cannot complete booking: an outstanding balance of ₱' . number_format($remainingBal, 2) . ' remains.');
            }

            if (!$booking->isDelivery() && ($booking->hasDispatchedReusableMaterials() || $booking->returns()->exists())) {
                $hasCompletedReturn = $booking->returns()->where('status', 'Completed')->exists();
                $hasIncompleteReturn = $booking->returns()->where('status', '!=', 'Completed')->exists();
                if (!$hasCompletedReturn || $hasIncompleteReturn) {
                    $booking->status = $oldStatus;
                    return redirect()->back()->with('error', 'Cannot complete booking: material return audit has not been completed.');
                }
            }

            $hasPendingResolution = $booking->returns()
                ->whereHas('returnItems', function ($q) {
                    $q->where('charge_decision', 'pending')
                        ->where(function ($sub) {
                            $sub->whereIn('condition', ['damaged', 'lost', 'mixed'])
                                ->orWhere('quantity_damaged', '>', 0)
                                ->orWhere('quantity_lost', '>', 0);
                        });
                })->exists();

            if ($hasPendingResolution) {
                $booking->status = $oldStatus;
                return redirect()->back()->with('error', 'Cannot complete booking: unresolved damage charges remain pending adjudication.');
            }
        }

        // The status change itself is audited once by the Booking model (status_changed).

        if ($booking->wasChanged('preparation_start_date') || $booking->wasChanged('preparation_status')) {
            $this->logAuditEvent(
                'booking',
                'preparation_schedule_updated',
                $booking,
                'Preparation schedule updated for booking #' . $booking->id,
                Auth::id(),
                ['preparation_start_date' => $booking->getOriginal('preparation_start_date'), 'preparation_status' => $booking->getOriginal('preparation_status')],
                ['preparation_start_date' => $booking->preparation_start_date?->toDateString(), 'preparation_status' => $booking->preparation_status],
                'preparation_schedule_updated'
            );
        }

        if ($booking->wasChanged('final_quoted_price') || $booking->wasChanged('total_quoted')) {
            $this->logAuditEvent('booking', 'quote_updated', $booking, 'Quotation updated to ' . $booking->final_quoted_price ?: $booking->total_quoted, Auth::id());
        }

        $booking->save();

        if (!empty($reviewRecordedByIssuance)) {
            $this->logAuditEvent('booking', 'raflora_review_completed', $booking, 'Raflora Review completed via quotation issuance', Auth::id(), null, null, 'raflora_review_completed');
        }

        if ($notificationStatus) {
            $title = match ($notificationStatus) {
                'quotation_sent' => 'Your quotation is ready',
                'payment_pending' => 'Booking accepted — payment is pending',
                'declined' => 'Your booking request was declined',
                default => 'Booking status updated',
            };

            $statusMessage = $adminNote ?: match ($notificationStatus) {
                'quotation_sent' => 'Your updated quotation is ready for review.',
                'payment_pending' => 'Your booking has been accepted and is now awaiting payment verification.',
                'declined' => 'The booking request was declined. Please review the details for next steps.',
                default => 'There is a new booking update from Raflora Enterprises.',
            };

            $appendedNote = trim((string) $generatedAdjustmentNote);
            if ($adminNote !== '' && str_starts_with($appendedNote, $adminNote)) {
                $appendedNote = trim((string) substr($appendedNote, strlen($adminNote)));
                $appendedNote = preg_replace('/^\n+/', '', (string) $appendedNote);
            }

            $message = $statusMessage;
            if (!empty($appendedNote)) {
                $message .= "\n\n" . $appendedNote;
            }

            $this->createClientNotificationForBooking($booking, $title, $message, 'booking_update');
        }

        if ($notificationStatus && $booking->client && filter_var($booking->client->email, FILTER_VALIDATE_EMAIL)) {
            // Sent once the quotation transaction commits (immediately outside one), so a client is
            // never emailed about a quotation that was rolled back.
            // Skipped when the status-change hook already emailed this status.
            DB::afterCommit(function () use ($booking, $notificationStatus): void {
                \App\Services\ClientStatusNotifier::email($booking, $notificationStatus);
            });
        }

        return redirect()->route('admin.bookings.show', ['booking' => $booking->id])
            ->with('success', 'Booking updated successfully.');
    }

    /**
     * Verify a payment submitted by a client.
     */
    public function verifyPayment(Request $request, Payment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'amount_received' => [
                'nullable', 
                'numeric', 
                'gt:0',
                function ($attribute, $value, $fail) use ($payment) {
                    if (round((float) $value, 2) !== round((float) $payment->amount, 2)) {
                        $fail('The received amount must be exactly equal to the required payment amount (' . number_format((float) $payment->amount, 2) . ').');
                    }
                }
            ],
        ]);

        $insufficientItems = [];

        try {
            DB::transaction(function () use ($request, $payment, $validated, &$insufficientItems) {
                $booking = Booking::with('inventoryItems')->whereKey($payment->booking_id)->lockForUpdate()->first();
                
                if (!$booking) {
                    throw new \Exception('booking_not_found');
                }

                // A remaining-balance payment sent while the event runs settles money only: no stock work.
                $isPostEvent = in_array($booking->status, ['event_in_progress', 'event_completed', 'pending_return', 'pending_resolution'], true)
                    || $booking->returns()->exists()
                    || $booking->hasDispatchedReusableMaterials();

                if (!in_array($booking->status, ['payment_submitted', 'event_in_progress', 'pending_resolution', 'event_completed', 'pending_return'], true)) {
                    throw new \Exception('invalid_status');
                }

                $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

                if ($payment->verified_at) {
                    throw new \Exception('already_verified');
                }

                // Only a submission still awaiting review can be verified (never a rejected one).
                if ($payment->status !== 'pending') {
                    throw new \Exception('not_pending');
                }

                // Lock inventory items ordered by ID to prevent deadlocks
                $itemIds = $booking->inventoryItems->pluck('id')->sort()->values()->toArray();
                if (!empty($itemIds)) {
                    $inventoryItems = InventoryItem::whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                } else {
                    $inventoryItems = collect();
                }

                // A valid preparation schedule is a record of planned work; payment unlocks reservation.
                $shouldReserveNow = !$isPostEvent
                    && $booking->hasVerifiedPayment()
                    && $booking->hasValidPreparationSchedule();

                if ($shouldReserveNow) {
                    $freshInventoryIds = $booking->bookingItems()
                        ->with('inventoryItem')
                        ->get()
                        ->filter(fn (BookingItem $bookingItem) => $bookingItem->requiresFreshProcurement())
                        ->pluck('inventory_item_id')
                        ->map(fn ($id) => (int) $id)
                        ->unique();
                    // Revalidate availability
                    foreach ($booking->inventoryItems as $item) {
                        $quantity = (float) ($item->pivot->quantity ?? 0);
                        if ($quantity <= 0) {
                            continue;
                        }

                        $inventoryItem = $inventoryItems->get($item->id);
                        if (!$inventoryItem) {
                            continue;
                        }

                        if ($freshInventoryIds->contains((int) $inventoryItem->id)) {
                            continue;
                        }

                        $existingReservation = (float) InventoryTransaction::query()
                            ->where('booking_id', $booking->id)
                            ->where('inventory_item_id', $inventoryItem->id)
                            ->where('transaction_type', 'booking_lock')
                            ->where('quantity_change', '<', 0)
                            ->sum('quantity_change');
                            
                        $existingRelease = (float) InventoryTransaction::query()
                            ->where('booking_id', $booking->id)
                            ->where('inventory_item_id', $inventoryItem->id)
                            ->where('transaction_type', 'booking_release')
                            ->where('quantity_change', '>', 0)
                            ->sum('quantity_change');
                            
                        $netDispatch = abs((float) InventoryTransaction::query()
                            ->where('booking_id', $booking->id)
                            ->where('inventory_item_id', $inventoryItem->id)
                            ->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])
                            ->sum('quantity_change'));

                        $alreadyAllocatedToBooking = abs($existingReservation) - $existingRelease - $netDispatch;
                        
                        if (round($alreadyAllocatedToBooking, 4) < 0) {
                            throw new \LogicException(sprintf('Inconsistent ledger state: Booking allocation cannot be negative (%f).', $alreadyAllocatedToBooking));
                        }
                        
                        // Effective available = global net_available + what this booking already holds
                        $effectiveAvailableStock = (float) $inventoryItem->net_available + $alreadyAllocatedToBooking;

                        if ($effectiveAvailableStock < $quantity) {
                            $insufficientItems[] = $inventoryItem->name;
                        }
                    }

                    if (!empty($insufficientItems)) {
                        throw new \Exception('insufficient_stock');
                    }
                }

                $oldPayment = $payment->toArray();
                $oldBookingStatus = $booking->status;

                $amountReceived = isset($validated['amount_received']) ? (float) $validated['amount_received'] : (float) $payment->amount;

                $payment->status = $payment->payment_option === 'full_payment' ? 'fully_paid' : 'downpayment_received';
                $payment->verified_by = Auth::id();
                $payment->verified_at = now();
                $payment->amount_paid = $amountReceived;
                $payment->save();
                
                $payment->remaining_balance = $booking->fresh()->remaining_balance;
                $payment->save();
                
                $booking = $booking->fresh();
                
                $isPostEvent = in_array($oldBookingStatus, ['event_completed', 'pending_return', 'pending_resolution'], true)
                    || $booking->returns()->exists()
                    || $booking->hasDispatchedReusableMaterials();

                if ($oldBookingStatus === 'event_in_progress') {
                    // Balance paid while the event runs: the event stays in progress (as with "Log Final Payment").
                } elseif ($booking->isDelivery() && $isPostEvent) {
                    // No return audit for delivery orders: completed once received and paid (DeliveryOrderService).
                    $booking->setNormalizedStatus(app(\App\Services\DeliveryOrderService::class)->statusAfterPayment($booking, $oldBookingStatus));
                } elseif ($isPostEvent) {
                    $hasPendingResolution = $booking->returns()
                        ->whereHas('returnItems', function ($q) {
                            $q->where('charge_decision', 'pending')
                                ->where(function ($sub) {
                                    $sub->whereIn('condition', ['damaged', 'lost', 'mixed'])
                                        ->orWhere('quantity_damaged', '>', 0)
                                        ->orWhere('quantity_lost', '>', 0);
                                });
                        })->exists();

                    $hasCompletedReturn = $booking->returns()->where('status', 'Completed')->exists();
                    $hasIncompleteReturn = $booking->returns()->where('status', '!=', 'Completed')->exists();

                    if ($booking->remaining_balance <= 0) {
                        if ($hasCompletedReturn && !$hasIncompleteReturn && !$hasPendingResolution) {
                            $booking->setNormalizedStatus('completed');
                        } elseif ($hasPendingResolution) {
                            $booking->setNormalizedStatus('pending_resolution');
                        } else {
                            $booking->setNormalizedStatus('event_completed');
                        }
                    } else {
                        if ($hasPendingResolution) {
                            $booking->setNormalizedStatus('pending_resolution');
                        } else {
                            $booking->setNormalizedStatus('event_completed');
                        }
                    }
                } else {
                    $booking->setNormalizedStatus($payment->payment_option === 'full_payment' ? 'confirmed' : 'downpayment_received');
                }
                
                $booking->save();
                
                // Reservation Integrity
                $this->adjustInventoryForBookingStatus($booking, $oldBookingStatus, $booking->status);

                $this->logAuditEvent(
                    'booking',
                    'payment_verified',
                    $booking,
                    'Payment #' . $payment->id . ' verified by admin: ' . Auth::id(),
                    Auth::id(),
                    ['payment' => $oldPayment, 'booking_status' => $oldBookingStatus],
                    ['payment' => $payment->toArray(), 'booking_status' => $booking->status],
                    'payment_verified'
                );

                if ($oldBookingStatus === 'pending_resolution') {
                    $this->createClientNotificationForBooking($booking, 'Resolution Payment Verified', 'Your payment for resolution/damages has been verified.', 'payment_update');
                } else {
                    $this->createClientNotificationForBooking($booking, 'Payment Verified', 'Your payment has been successfully verified. Your booking is now ' . $booking->status_display_label . '.', 'payment_update');
                }
            });
            
            return back()->with('success', 'Payment verified and booking updated.');

        } catch (\Exception $e) {
            if ($e->getMessage() === 'booking_not_found') {
                return back()->with('error', 'The associated booking could not be found.');
            }
            if ($e->getMessage() === 'invalid_status') {
                return back()->with('error', 'Payment verification requires a submitted payment.');
            }
            if ($e->getMessage() === 'already_verified') {
                return back()->with('warning', 'This payment has already been verified.');
            }
            if ($e->getMessage() === 'not_pending') {
                return back()->with('error', 'Only a payment submission awaiting review can be verified.');
            }
            if (str_starts_with($e->getMessage(), 'insufficient_stock')) {
                if (!empty($insufficientItems)) {
                    return back()->with('error', 'Unable to verify downpayment while stock is insufficient for: ' . implode(', ', array_unique($insufficientItems)) . '.');
                }
                $itemName = str_replace('insufficient_stock_', '', $e->getMessage());
                return back()->with('error', 'Unable to verify downpayment while stock is insufficient for: ' . $itemName . '.');
            }
            
            Log::error('Payment verification failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->with('error', 'An unexpected error occurred during verification. Please try again.');
        }
    }

    protected function hasRequiredDispatchOccurred(Booking $booking): bool
    {
        $reusableIds = $booking->reusableBookingItems()->pluck('inventory_item_id')->map(fn ($id) => (int) $id)->unique();
        $confirmedHardware = $this->confirmedInventoryItems($booking)
            ->filter(fn ($item) => $reusableIds->contains((int) $item->id));
        
        if ($confirmedHardware->isEmpty()) {
            return true; // No reusable materials required for physical dispatch
        }
        
        $totalNetDispatch = \App\Models\InventoryTransaction::where('booking_id', $booking->id)
            ->whereIn('transaction_type', ['dispatch', 'dispatch_correction'])
            ->whereIn('inventory_item_id', $reusableIds)
            ->sum('quantity_change');
            
        // Dispatch quantity_change is negative, so we use absolute value.
        // A value greater than 0 means at least partial dispatch of reusable materials has occurred.
        return abs((float) $totalNetDispatch) > 0.0;
    }

    /**
     * Reject a payment submitted by a client.
     */
    public function rejectPayment(Request $request, Payment $payment): RedirectResponse
    {
        $booking = $payment->booking()->first();

        if (!$booking) {
            return back()->with('error', 'The associated booking could not be found.');
        }

        if ($payment->status !== 'pending') {
            return back()->with('error', 'Only pending payments can be rejected.');
        }

        if ($payment->verified_at) {
            return back()->with('warning', 'This payment has already been verified and cannot be rejected.');
        }

        DB::transaction(function () use ($payment, $booking) {
            $lockedBooking = \App\Models\Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $oldPayment = $payment->toArray();
            $oldBookingStatus = $lockedBooking->status;

            $payment->status = 'rejected';
            $payment->save();

            $revertStatus = $this->statusAfterRejectedPayment($lockedBooking, $payment, $oldBookingStatus);

            // Only a booking that was waiting for this payment goes back to the payment step. A
            // cancellation request keeps waiting for Admin's decision (its saved prior stage moves
            // instead), and a closed booking stays closed.
            if (in_array($oldBookingStatus, self::AWAITING_PAYMENT_STATUSES, true)) {
                $lockedBooking->setNormalizedStatus($revertStatus)->save();
            } elseif ($oldBookingStatus === 'cancellation_requested'
                && in_array(Booking::normalizeStatus($lockedBooking->pre_cancellation_status), self::AWAITING_PAYMENT_STATUSES, true)) {
                $lockedBooking->pre_cancellation_status = $revertStatus;
                $lockedBooking->save();
            }

            $this->logAuditEvent(
                'booking',
                'payment_rejected',
                $lockedBooking,
                'Payment #' . $payment->id . ' rejected by admin: ' . Auth::id(),
                Auth::id(),
                ['payment' => $oldPayment, 'booking_status' => $oldBookingStatus],
                ['payment' => $payment->toArray(), 'booking_status' => $lockedBooking->status],
                'payment_rejected'
            );

            $canResubmit = in_array($lockedBooking->status, ['admin_approved', 'event_in_progress', 'event_completed', 'pending_resolution'], true);
            $this->createClientNotificationForBooking(
                $lockedBooking,
                'Payment Not Verified',
                'Raflora could not verify your payment reference ' . $payment->reference_number . '. '
                    . ($canResubmit
                        ? 'Please check your receipt and submit a corrected reference from your booking page.'
                        : 'If you already sent this payment, please contact Raflora.'),
                'payment_update'
            );
        });

        return back()->with('success', 'Payment has been rejected and the client has been notified.');
    }

    /**
     * Booking statuses in which a submitted payment is what the booking is waiting for.
     */
    private const AWAITING_PAYMENT_STATUSES = ['payment_submitted', 'payment_pending', 'event_completed', 'pending_return', 'pending_resolution'];

    /**
     * Whether a client payment reference is still waiting for Admin to verify or reject it.
     */
    protected function hasPaymentAwaitingVerification(Booking $booking): bool
    {
        return $booking->payments()->where('status', 'pending')->exists();
    }

    /**
     * The payment stage a booking returns to once its submitted payment is rejected.
     */
    protected function statusAfterRejectedPayment(Booking $lockedBooking, Payment $payment, ?string $oldBookingStatus): string
    {
        $hasReturns = $lockedBooking->returns()->exists();
        $hasDispatches = $lockedBooking->hasDispatchedReusableMaterials();
        $isPostEvent = $hasReturns || $hasDispatches || in_array($oldBookingStatus, ['event_completed', 'pending_return', 'pending_resolution'], true);

        if ($isPostEvent) {
            $hasPendingResolution = $lockedBooking->returns()
                ->whereHas('returnItems', function ($q) {
                    $q->where('charge_decision', 'pending')
                        ->where(function ($sub) {
                            $sub->whereIn('condition', ['damaged', 'lost', 'mixed'])
                                ->orWhere('quantity_damaged', '>', 0)
                                ->orWhere('quantity_lost', '>', 0);
                        });
                })->exists();

            $hasCompletedReturn = $lockedBooking->returns()->where('status', 'Completed')->exists();

            if ($hasPendingResolution) {
                $revertStatus = 'pending_resolution';
            } elseif ($hasCompletedReturn) {
                $revertStatus = 'event_completed';
            } else {
                $revertStatus = 'pending_return';
            }
        } else {
            $hasPriorVerifiedPayments = $lockedBooking->payments()
                ->whereIn('status', Booking::VERIFIED_PAYMENT_STATUSES)
                ->where('id', '!=', $payment->id)
                ->exists();

            if ($hasPriorVerifiedPayments) {
                $revertStatus = 'downpayment_received';
            } else {
                $revertStatus = 'admin_approved';
            }
        }

        return $revertStatus;
    }

    /**
     * Log final payment for a booking. Creates a Payment if none exists and verifies it in a single transaction.
     */
    public function finalPayment(Request $request, Booking $booking): RedirectResponse
    {
        $validated = $request->validate([
            'amount_received' => ['required', 'numeric', 'gt:0'],
            'payment_type' => ['required', 'string', 'in:gcash,bank_transfer,cash'],
        ]);

        $amountReceived = round((float) $validated['amount_received'], 2);

        $error = DB::transaction(function () use ($booking, $amountReceived, $validated): ?string {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            // Same rules as the "Log Final Payment" button: only once the event has started, only
            // the outstanding balance, and never alongside a client payment still being checked.
            if (!in_array($lockedBooking->status, self::FINAL_PAYMENT_STATUSES, true)) {
                return 'A final payment can only be logged once the event is in progress or completed.';
            }

            if ($this->hasPaymentAwaitingVerification($lockedBooking)) {
                return 'The client submitted a payment reference that is still waiting for verification. Verify or reject it before logging a final payment.';
            }

            $totalObligation = (float) $lockedBooking->total_obligation;
            $priorPaid = (float) $lockedBooking->total_paid;
            $remainingBefore = max(0.0, $totalObligation - $priorPaid);

            if ($remainingBefore <= 0.0) {
                return 'This booking has no outstanding balance.';
            }

            if ($amountReceived > round($remainingBefore, 2)) {
                return 'The amount is more than the remaining balance of ₱' . number_format($remainingBefore, 2) . '.';
            }

            $newRemaining = max(0.0, $remainingBefore - $amountReceived);
            $isFullyPaid = ($newRemaining <= 0.0);

            $referenceNumber = 'admin-final-' . $lockedBooking->id . '-' . time() . '-' . rand(100, 999);

            $payment = Payment::create([
                'booking_id' => $lockedBooking->id,
                'quotation_id' => $lockedBooking->acceptedQuotation?->id,
                'amount' => $amountReceived,
                'payment_option' => $isFullyPaid ? 'full_payment' : 'downpayment',
                'amount_paid' => $amountReceived,
                'remaining_balance' => $newRemaining,
                'payment_type' => $validated['payment_type'],
                'reference_number' => $referenceNumber,
                'status' => $isFullyPaid ? 'fully_paid' : 'downpayment_received',
                'recorded_by' => Auth::id(),
                'verified_by' => Auth::id(),
                'verified_at' => now(),
            ]);

            $this->logAuditEvent('booking', 'payment_verified', $lockedBooking, 'Final payment recorded and verified by admin', Auth::id(), null, ['payment' => $payment->toArray()], 'final_payment_logged');

            $hasPendingResolution = $lockedBooking->returns()
                ->whereHas('returnItems', function ($q) {
                    $q->where('charge_decision', 'pending')
                        ->where(function ($sub) {
                            $sub->whereIn('condition', ['damaged', 'lost', 'mixed'])
                                ->orWhere('quantity_damaged', '>', 0)
                                ->orWhere('quantity_lost', '>', 0);
                        });
                })->exists();

            $hasCompletedReturn = $lockedBooking->returns()->where('status', 'Completed')->exists();
            $hasIncompleteReturn = $lockedBooking->returns()->where('status', '!=', 'Completed')->exists();

            $oldStatus = $lockedBooking->status;

            if ($lockedBooking->isDelivery()) {
                // No return audit for delivery orders: completed once received and paid (DeliveryOrderService).
                $lockedBooking->setNormalizedStatus(app(\App\Services\DeliveryOrderService::class)->statusAfterPayment($lockedBooking->fresh(), $oldStatus))->save();
                if ($lockedBooking->status !== $oldStatus) {
                    $this->logAuditEvent('booking', 'final_payment_status_applied', $lockedBooking, 'Delivery order settled after final payment', Auth::id(), ['booking_status' => $oldStatus], ['booking_status' => $lockedBooking->status], 'booking_completed');
                }
            } elseif ($newRemaining <= 0.0) {
                if ($hasPendingResolution) {
                    $lockedBooking->setNormalizedStatus('pending_resolution')->save();
                    $this->logAuditEvent('booking', 'final_payment_status_applied', $lockedBooking, 'Booking set to pending resolution after payment because return items await adjudication', Auth::id(), ['booking_status' => $oldStatus], ['booking_status' => $lockedBooking->status], 'pending_resolution');
                } elseif ($hasCompletedReturn && !$hasIncompleteReturn) {
                    $lockedBooking->setNormalizedStatus('completed')->save();

                    $this->logAuditEvent('booking', 'final_payment_status_applied', $lockedBooking, 'Booking completed after final payment because return audit was already completed', Auth::id(), ['booking_status' => $oldStatus], ['booking_status' => $lockedBooking->status], 'booking_completed');
                } elseif (in_array($lockedBooking->status, ['event_completed', 'pending_return'], true)) {
                    $lockedBooking->setNormalizedStatus('pending_return')->save();

                    $this->logAuditEvent('booking', 'final_payment_status_applied', $lockedBooking, 'Booking moved to pending return audit after final payment', Auth::id(), ['booking_status' => $oldStatus], ['booking_status' => $lockedBooking->status], 'pending_return');
                    $this->ensureReturnRecordForBooking($lockedBooking);
                } else {
                    $lockedBooking->save();
                }
            } else {
                if ($hasPendingResolution) {
                    $lockedBooking->setNormalizedStatus('pending_resolution')->save();
                } elseif ($hasCompletedReturn && !$hasIncompleteReturn && $lockedBooking->status !== 'event_completed') {
                    $lockedBooking->setNormalizedStatus('event_completed')->save();

                    $this->logAuditEvent('booking', 'final_payment_status_applied', $lockedBooking, 'Booking kept as event completed with balance pending after return audit completed', Auth::id(), ['booking_status' => $oldStatus], ['booking_status' => $lockedBooking->status], 'status_updated');
                }
            }

            return null;
        });

        if ($error !== null) {
            return redirect()->back()->with('error', $error);
        }

        return redirect()->back()->with('success', 'Final payment processed successfully.');
    }

    /**
     * Booking statuses in which Admin may log a final payment (the event has started).
     */
    private const FINAL_PAYMENT_STATUSES = ['event_in_progress', 'event_completed', 'pending_return', 'pending_resolution'];

    /**
     * Decline a booking request.
     */
    public function decline(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Declining applies before the event starts; afterwards the booking follows the
        // execution/return workflow (or a cancellation), and closed bookings stay closed.
        if (in_array($booking->status, ['event_in_progress', 'event_completed', 'pending_return', 'pending_resolution', 'completed', 'cancelled', 'declined', 'rejected'], true)) {
            return redirect()->back()->with('error', 'This booking can no longer be declined (' . $booking->status_display_label . ').');
        }

        // Money the client may have sent is looked at before the booking closes. The row lock keeps
        // a payment submitted at the same moment from slipping in between the check and the save.
        $declined = DB::transaction(function () use ($booking, $data): bool {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($this->hasPaymentAwaitingVerification($booking)) {
                return false;
            }

            if (in_array($booking->status, ['downpayment_received', 'confirmed'], true)) {
                $this->releaseInventoryForBooking($booking);
            }

            $booking->setNormalizedStatus('declined');
            if (!empty($data['admin_note'])) {
                $booking->cancellation_reason = $data['admin_note'];
            }
            $booking->handled_by = Auth::id();
            $booking->save();

            return true;
        });

        if (!$declined) {
            return redirect()->back()->with('error', 'The client submitted a payment reference that is still waiting for verification. Verify or reject it before declining this booking.');
        }

        $booking->refresh();

        $declineMessage = trim((string) ($data['admin_note'] ?? 'The booking request was declined. Please review the details for next steps.'));

        $user = \App\Models\User::where('email', $booking->client?->email)->first();
        if ($user) {
            ClientNotification::create([
                'user_id' => $user->id,
                'booking_id' => $booking->id,
                'type' => 'booking_update',
                'title' => 'Your booking request was declined',
                'message' => $declineMessage,
                'is_read' => false,
            ]);
        }

        // notify client (skipped when the status-change hook already emailed it)
        \App\Services\ClientStatusNotifier::email($booking, 'declined');

        return redirect()->route('admin.bookings')->with('success', 'Booking declined.');
    }

    public function dispatchItems(Request $request, Booking $booking, \App\Services\InventoryDispatchService $dispatchService)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'items.*.quantity' => 'required|numeric|min:0',
            'reason' => 'required|string|max:255',
        ]);

        try {
            $dispatchService->dispatchItems($booking, $validated['items'], Auth::id(), $validated['reason']);
            return back()->with('success', 'Items dispatched successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Dispatch failed: ' . $e->getMessage());
        }
    }

    public function correctDispatch(Request $request, InventoryTransaction $transaction, \App\Services\InventoryDispatchService $dispatchService)
    {
        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
        ]);

        try {
            $dispatchService->correctDispatch($transaction, $validated['quantity'], Auth::id(), $validated['reason']);
            return back()->with('success', 'Dispatch correction applied successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Correction failed: ' . $e->getMessage());
        }
    }

    /**
     * Admin explicitly confirms fresh flower readiness for an event.
     */
    /**
     * Delivery orders: Admin records that the client received the items (e.g. the client
     * confirmed by message or call without using the portal button). No return audit follows.
     */
    /**
     * Accept or decline the client's schedule change request (ScheduleChangeService). Accepting
     * moves the event date/time; the quotation is then sent with "Approve & Send Official Quote".
     */
    public function decideScheduleRequest(Request $request, Booking $booking, \App\Models\BookingScheduleRequest $scheduleRequest, \App\Services\ScheduleChangeService $scheduleChanges): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:accept,decline'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $note = trim((string) ($validated['decision_note'] ?? '')) ?: null;

        try {
            $validated['decision'] === 'accept'
                ? $scheduleChanges->accept($booking, $scheduleRequest, Auth::id(), $note)
                : $scheduleChanges->decline($booking, $scheduleRequest, Auth::id(), $note);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $accepted = $validated['decision'] === 'accept';
        $quotationOpen = in_array($booking->fresh()->status, \App\Services\ScheduleChangeService::QUOTATION_STATUSES, true);
        $this->createClientNotificationForBooking(
            $booking,
            $accepted ? 'Schedule change accepted' : 'Schedule change declined',
            $accepted
                ? 'Your event is now on ' . $scheduleRequest->fresh()->requestedLabel() . '.' . ($quotationOpen ? ' Raflora will send the updated quotation.' : '')
                : 'Raflora could not move your event to ' . $scheduleRequest->requestedLabel() . '.' . ($note ? ' Reason: ' . $note : ''),
            'booking_update'
        );

        return back()->with('success', $accepted
            ? 'Schedule change accepted — the event date is updated.' . ($quotationOpen ? ' Send the updated quotation when ready.' : '')
            : 'Schedule change declined — the client has been told.');
    }

    public function markDeliveryReceived(Booking $booking, \App\Services\DeliveryOrderService $deliveryOrders): RedirectResponse
    {
        try {
            $status = $deliveryOrders->markReceived($booking, Auth::id(), \App\Services\DeliveryOrderService::VIA_ADMIN);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->createClientNotificationForBooking(
            $booking,
            'Delivery received',
            $status === 'completed'
                ? 'Raflora recorded that you received your order. Your booking is now complete.'
                : 'Raflora recorded that you received your order. Please settle the remaining balance to close your booking.',
            'booking_update'
        );

        return back()->with('success', $status === 'completed'
            ? 'Item received — the delivery order is complete.'
            : 'Item received — the order will complete once the remaining balance is paid.');
    }

    public function confirmFreshFlowers(Booking $booking): RedirectResponse
    {
        if (Auth::user()?->role !== 'admin') {
            abort(403, 'Unauthorized. Admin authorization required.');
        }

        if (!$booking->hasVerifiedPayment()) {
            return back()->with('error', 'Fresh flower procurement cannot be confirmed until a down payment or full payment is verified.');
        }

        if (!$booking->hasValidPreparationSchedule()) {
            return back()->with('error', 'Fresh flower procurement requires a valid preparation start date.');
        }

        $perishableItems = $booking->freshProcurementItems();

        if ($perishableItems->isEmpty()) {
            return back()->with('info', 'This booking has no perishable fresh flower items requiring procurement confirmation.');
        }

        DB::transaction(function () use ($booking, $perishableItems) {
            foreach ($perishableItems as $item) {
                $item->procurement_status = 'confirmed';
                $item->save();
            }

            $this->logAuditEvent(
                'booking',
                'fresh_flowers_confirmed',
                $booking,
                'Fresh flower procurement readiness explicitly confirmed by Admin: ' . Auth::id(),
                Auth::id()
            );
        });

        return back()->with('success', 'Fresh flower readiness has been explicitly confirmed for this event.');
    }

    public function updateFreshProcurementClassification(Request $request, Booking $booking, BookingItem $bookingItem): RedirectResponse
    {
        if (Auth::user()?->role !== 'admin') {
            abort(403, 'Unauthorized. Admin authorization required.');
        }

        abort_unless((int) $bookingItem->booking_id === (int) $booking->id, 404);

        $validated = $request->validate([
            'fresh_procurement' => ['required', 'boolean'],
        ]);

        if (!$booking->hasVerifiedPayment()) {
            return back()->with('error', 'Material classification can only be changed after a down payment or full payment is verified.');
        }

        if (!$booking->hasValidPreparationSchedule()) {
            return back()->with('error', 'Material classification requires a valid preparation start date.');
        }

        $inventoryItemId = (int) $bookingItem->inventory_item_id;
        if ($booking->bookingItems()->where('inventory_item_id', $inventoryItemId)->count() > 1) {
            return back()->with('error', 'This inventory item appears more than once on the booking. Ask an Admin to resolve the duplicate before changing its procurement classification.');
        }

        if (InventoryTransaction::where('booking_id', $booking->id)->where('inventory_item_id', $inventoryItemId)->exists()) {
            return back()->with('error', 'Procurement classification cannot be changed after inventory has been reserved or dispatched for this item.');
        }

        $freshProcurement = (bool) $validated['fresh_procurement'];
        if ($bookingItem->requiresFreshProcurement() === $freshProcurement) {
            return back()->with('info', 'This item already has the selected procurement classification.');
        }

        $previousClassification = $bookingItem->requiresFreshProcurement();
        $bookingItem->fresh_procurement_override = $freshProcurement;
        $bookingItem->procurement_status = 'pending';
        $bookingItem->save();

        $this->logAuditEvent(
            'booking',
            'fresh_procurement_classification_updated',
            $booking,
            sprintf(
                'Booking item "%s" procurement classification changed from %s to %s.',
                $bookingItem->item_name ?? $bookingItem->inventoryItem?->name ?? 'Unknown item',
                $previousClassification ? 'fresh procurement' : 'reusable inventory',
                $freshProcurement ? 'fresh procurement' : 'reusable inventory'
            ),
            Auth::id(),
            ['booking_item_id' => $bookingItem->id, 'fresh_procurement' => $previousClassification],
            ['booking_item_id' => $bookingItem->id, 'fresh_procurement' => $freshProcurement]
        );

        return back()->with('success', $freshProcurement
            ? 'Item added to fresh-flower procurement for this booking.'
            : 'Item removed from fresh-flower procurement for this booking. The booking item was retained as reusable inventory.');
    }

    /**
     * Admin approves a physical stock adjustment submitted by Staff.
     */
    public function approveStockAdjustment(Request $request, AdminAlert $alert): RedirectResponse
    {
        if (Auth::user()?->role !== 'admin') {
            abort(403, 'Unauthorized. Admin authorization required.');
        }

        if ($alert->type !== 'physical_count_variance' || !$alert->inventory_item_id) {
            return back()->with('error', 'Invalid adjustment alert.');
        }

        if ($alert->is_read) {
            return back()->with('warning', 'This adjustment has already been reviewed.');
        }

        $validated = $request->validate([
            'admin_reason' => 'nullable|string|max:255',
            'approved_stock' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($request, $alert, $validated) {
            $inventoryItem = InventoryItem::whereKey($alert->inventory_item_id)->lockForUpdate()->firstOrFail();
            
            // Extract the observed stock from alert message if not passed in request
            preg_match('/observed physical count of ([\d\.]+)/', $alert->message, $matches);
            $targetStock = $request->filled('approved_stock')
                ? (float) $validated['approved_stock']
                : (isset($matches[1]) ? (float) $matches[1] : (float) $inventoryItem->current_stock);

            $oldStock = (float) $inventoryItem->current_stock;
            $stockDelta = $targetStock - $oldStock;

            if (abs($stockDelta) >= 0.0001) {
                $inventoryItem->current_stock = max(0.0, $targetStock);
                $inventoryItem->save();

                InventoryTransaction::create([
                    'inventory_item_id' => $inventoryItem->id,
                    'booking_id' => $alert->booking_id,
                    'quantity_change' => $stockDelta,
                    'transaction_type' => 'adjustment',
                    'reason' => $validated['admin_reason'] ?? ('Admin approved physical stock adjustment from alert #' . $alert->id),
                    'performed_by' => Auth::id(),
                ]);
            }

            $alert->update(['is_read' => true]);

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'stock_adjustment_approved',
                'module' => 'inventory',
                'details' => "Admin approved stock adjustment for item #{$inventoryItem->id} ({$inventoryItem->name}) to {$targetStock} (variance: {$stockDelta})",
                'entity_type' => InventoryItem::class,
                'entity_id' => $inventoryItem->id,
            ]);
        });

        return back()->with('success', 'Physical stock adjustment approved and applied.');
    }

    /**
     * Admin rejects a physical stock adjustment submitted by Staff.
     */
    public function rejectStockAdjustment(Request $request, AdminAlert $alert): RedirectResponse
    {
        if (Auth::user()?->role !== 'admin') {
            abort(403, 'Unauthorized. Admin authorization required.');
        }

        if ($alert->type !== 'physical_count_variance') {
            return back()->with('error', 'Invalid adjustment alert.');
        }

        // Same rule as approval: a request is decided once.
        if ($alert->is_read) {
            return back()->with('warning', 'This adjustment has already been reviewed.');
        }

        $alert->update(['is_read' => true]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'stock_adjustment_rejected',
            'module' => 'inventory',
            'details' => "Admin rejected physical stock adjustment for alert #{$alert->id}",
            'entity_type' => AdminAlert::class,
            'entity_id' => $alert->id,
        ]);

        return back()->with('success', 'Physical stock adjustment rejected.');
    }
}
