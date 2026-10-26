<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\Booking;
use App\Models\User;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\AdminBootstrapController;
use App\Http\Controllers\Admin\AdminRecoveryController;
use App\Http\Controllers\Admin\AdminEmailChangeController;
use App\Http\Controllers\Admin\AdminPasswordResetController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\DeviceVerificationController;
// Home Page: Landing page route
Route::get('/', function () {
    $featuredPackages = \App\Models\Package::where('is_active', true)
        ->where('is_archived', false)
        ->with('images')
        ->take(3)
        ->get();
    $featuredGalleries = \App\Models\Gallery::where('is_archived', false)
        ->with('images')
        ->orderBy('event_date', 'desc')
        ->take(5)
        ->get();
    return view('home', [
        'featuredPackages' => $featuredPackages,
        'featuredGalleries' => $featuredGalleries,
    ]);
})->name('home');
// Gallery Page: Dedicated gallery showcase
Route::get('/gallery', fn () => view('gallery'))->name('gallery');
// About Page: Dedicated about page
Route::get('/about', fn () => view('about'))->name('about');

//flagging invalid image in bookings
// Booking-flow limits each keep their own counter (third throttle argument): without it every
// throttle:N,1 route shares one counter per IP or user, so estimate refreshes and venue searches
// used up the photo-analysis and submission limits.
Route::post('/bookings/validate-image', [BookingController::class, 'validateImageAjax'])->middleware('throttle:5,1,image-check')->name('bookings.validate-image');
Route::post('/bookings/analyze-temp-image', [BookingController::class, 'analyzeTempImage'])->middleware('throttle:5,1,photo-analysis')->name('bookings.analyze-temp-image');
// Combined per-area AI ranges for the photos analyzed in this session (guest and client forms; reads only the caller's own session).
Route::post('/bookings/analysis-estimate', [\App\Http\Controllers\AiEstimateController::class, 'preview'])->middleware('throttle:30,1,ai-estimate')->name('bookings.analysis-estimate');

// Dynamic Booking Route
Route::get('/booking/start', function () {
    if (Auth::check()) {
        return redirect()->route('bookings.create', request()->query());
    }
    return redirect()->route('guest.booking.create', request()->query());
})->name('booking.start');

// Public Packages Route
Route::get('/packages', [\App\Http\Controllers\PackageController::class, 'index'])->name('packages.index');

// Venue location finder (guest + client booking forms, Admin/Staff venue maps)
Route::get('/location/search', [\App\Http\Controllers\LocationController::class, 'search'])->middleware('throttle:30,1,location')->name('location.search');
Route::get('/location/reverse', [\App\Http\Controllers\LocationController::class, 'reverse'])->middleware('throttle:30,1,location')->name('location.reverse');

// Guest Booking Routes
Route::get('/guest/booking', [\App\Http\Controllers\GuestBookingController::class, 'create'])->name('guest.booking.create');
Route::post('/guest/booking', [\App\Http\Controllers\GuestBookingController::class, 'store'])->middleware('throttle:3,1,guest-booking')->name('guest.booking.store');
// Up to InspirationPhotos::MAX_PHOTOS photos are analyzed one after another, so the limit allows them all within a minute.
Route::post('/guest/bookings/analyze-temp-image', [\App\Http\Controllers\GuestBookingController::class, 'analyzeTempImage'])->middleware('throttle:5,1,photo-analysis')->name('guest.bookings.analyze-temp-image');
Route::get('/guest/bookings/{token}', [\App\Http\Controllers\GuestBookingController::class, 'show'])->name('guest.bookings.show');
Route::get('/guest/bookings/{token}/image/{imageIndex?}', [\App\Http\Controllers\GuestBookingController::class, 'showTemporaryImage'])->name('guest.bookings.image');
Route::get('/guest/bookings/analysis-image/{token}', [\App\Http\Controllers\GuestBookingController::class, 'showAnalysisTempImage'])->name('guest.bookings.analysis-temp-image');
Route::get('/guest/bookings/{token}/status', [\App\Http\Controllers\GuestBookingController::class, 'status'])->name('guest.bookings.status');
Route::get('/guest/booking/{token}', [\App\Http\Controllers\GuestBookingController::class, 'analysis'])->name('guest.booking.show');
Route::get('/guest/booking/analysis/{token}', [\App\Http\Controllers\GuestBookingController::class, 'analysis'])->name('guest.booking.analysis');
Route::post('/guest/bookings/{booking}/accept', [\App\Http\Controllers\GuestBookingController::class, 'acceptQuotation'])->name('guest.bookings.accept');
Route::post('/guest/bookings/{booking}/payment-reference', [\App\Http\Controllers\GuestBookingController::class, 'submitPaymentReference'])->name('guest.bookings.payment.reference');


// Authentication Routes: Real session-based authentication
Route::middleware('guest')->group(function () {
    // Login Routes
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');

    // Register Routes
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('register.attempt');

    // Forgot Password Routes
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('forgot-password');
    Route::post('/forgot-password', [AuthController::class, 'sendPasswordResetLink'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPasswordForm'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');

    // Admin Password Reset (OTP Flow)
    Route::get('/admin/password-reset', [AdminPasswordResetController::class, 'showForgot'])->name('admin.password.forgot');
    Route::post('/admin/password-reset/send', [AdminPasswordResetController::class, 'sendOtp'])->middleware('throttle:3,1')->name('admin.password.send');
    Route::get('/admin/password-reset/otp', [AdminPasswordResetController::class, 'showOtpForm'])->name('admin.password.otp.show');
    Route::post('/admin/password-reset/otp', [AdminPasswordResetController::class, 'verifyOtp'])->middleware('throttle:6,1')->name('admin.password.otp.verify');
    Route::post('/admin/password-reset/otp/resend', [AdminPasswordResetController::class, 'resendOtp'])->middleware('throttle:3,1')->name('admin.password.otp.resend');
    Route::get('/admin/password-reset/new', [AdminPasswordResetController::class, 'showNewPasswordForm'])->name('admin.password.new.show');
    Route::post('/admin/password-reset/new', [AdminPasswordResetController::class, 'resetPassword'])->middleware('throttle:5,1')->name('admin.password.new.submit');

    // Admin Emergency Recovery (Email Unavailable)
    Route::get('/admin/recovery', [AdminRecoveryController::class, 'show'])->name('admin.recovery.show');
    Route::post('/admin/recovery/code', [AdminRecoveryController::class, 'verifyCode'])->middleware('throttle:10,1')->name('admin.recovery.code');
    Route::post('/admin/recovery/email', [AdminRecoveryController::class, 'submitEmail'])->middleware('throttle:5,1')->name('admin.recovery.email');
    Route::post('/admin/recovery/otp', [AdminRecoveryController::class, 'verifyOtp'])->middleware('throttle:6,1')->name('admin.recovery.otp');
    Route::post('/admin/recovery/otp/resend', [AdminRecoveryController::class, 'resendOtp'])->middleware('throttle:3,1')->name('admin.recovery.resend');
    Route::post('/admin/recovery/password', [AdminRecoveryController::class, 'updatePassword'])->middleware('throttle:5,1')->name('admin.recovery.password');

    // Google OAuth Routes
    Route::get('/auth/google', [GoogleController::class, 'redirectToGoogle'])->name('auth.google');
    Route::get('/auth/google/callback', [GoogleController::class, 'handleGoogleCallback'])->name('auth.google.callback');
});

// Logout Route: Authenticated users only
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Device Verification Routes (S-01E-3): No auth middleware — user is in pending-session state.
// The controller enforces identity via the server-side pending session.
Route::prefix('auth')->group(function () {
    Route::get('/device-verify', [DeviceVerificationController::class, 'show'])
        ->middleware('throttle:20,1')
        ->name('device.verify.show');
    Route::post('/device-verify', [DeviceVerificationController::class, 'verify'])
        ->middleware('throttle:6,1')
        ->name('device.verify.submit');
    Route::post('/device-verify/resend', [DeviceVerificationController::class, 'resend'])
        ->middleware('throttle:3,1')
        ->name('device.verify.resend');
});

// Secure file serving (handles both Auth users and Guest Tokens)
Route::get('/secure-evidence/{id}', [\App\Http\Controllers\SecureFileController::class, 'showEvidence'])->name('secure.evidence.show');
Route::get('/secure-inspiration/{bookingId}', [\App\Http\Controllers\SecureFileController::class, 'showInspirationImage'])->name('secure.inspiration.show');
Route::get('/secure-attachment/{messageId}', [\App\Http\Controllers\SecureFileController::class, 'showMessageAttachment'])->name('secure.attachment.show');

// Email Verification Routes: Authenticated users only
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:6,1')->name('verification.verify');
    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:3,1')->name('verification.resend');
});

// Staff Routes: operational landing boundary. Staff features are added in later phases.
// Staff Routes: protected by auth, staff role, and email verification (S-01E-3).
// Unverified staff are redirected to the email verification gate.
Route::prefix('staff')->middleware(['auth', 'staff', 'verified'])->group(function () {
    Route::get('/account-settings', [\App\Http\Controllers\Staff\ProfileController::class, 'edit'])->name('staff.profile.edit');
    Route::post('/account-settings/photo', [\App\Http\Controllers\Staff\ProfileController::class, 'updatePhoto'])->name('staff.profile.photo.update');
    Route::get('/dashboard', [\App\Http\Controllers\Staff\WorkspaceController::class, 'dashboard'])->name('staff.dashboard');
    Route::get('/tasks', [\App\Http\Controllers\Staff\WorkspaceController::class, 'tasks'])->name('staff.tasks');
    Route::get('/assignments', [\App\Http\Controllers\Staff\WorkspaceController::class, 'assignments'])->name('staff.assignments');
    Route::get('/work-orders', [\App\Http\Controllers\Staff\WorkspaceController::class, 'workOrders'])->name('staff.work-orders');
    Route::get('/dispatch', [\App\Http\Controllers\Staff\WorkspaceController::class, 'dispatchBoard'])->name('staff.dispatch');
    Route::get('/returns', [\App\Http\Controllers\Staff\WorkspaceController::class, 'returns'])->name('staff.returns');
    Route::get('/checklist', [\App\Http\Controllers\Staff\WorkspaceController::class, 'checklist'])->name('staff.checklist');
    Route::get('/messages', [\App\Http\Controllers\Staff\WorkspaceController::class, 'messages'])->name('staff.messages');
    Route::get('/activity', [\App\Http\Controllers\Staff\WorkspaceController::class, 'activity'])->name('staff.activity');
    Route::get('/calendar', [\App\Http\Controllers\Staff\WorkspaceController::class, 'calendar'])->name('staff.calendar');
    Route::get('/guide', [\App\Http\Controllers\Staff\WorkspaceController::class, 'guide'])->name('staff.guide');
    Route::get('/search', [\App\Http\Controllers\Staff\WorkspaceController::class, 'search'])->name('staff.search');
    Route::get('/requests', [\App\Http\Controllers\Staff\WorkspaceController::class, 'requests'])->name('staff.requests');
    Route::post('/requests/inventory', [\App\Http\Controllers\Staff\WorkspaceController::class, 'storeInventoryRequest'])->middleware('throttle:10,1')->name('staff.requests.inventory');
    Route::post('/requests/issue', [\App\Http\Controllers\Staff\WorkspaceController::class, 'storeIssue'])->middleware('throttle:10,1')->name('staff.requests.issue');
    Route::get('/events/{booking}', [\App\Http\Controllers\Staff\EventController::class, 'show'])->name('staff.events.show');
    Route::put('/events/{booking}/checklist/{checklist}', [\App\Http\Controllers\Staff\EventController::class, 'updateChecklist'])->name('staff.events.checklist.update');
    Route::post('/events/{booking}/dispatch', [\App\Http\Controllers\Staff\EventController::class, 'dispatchItems'])->name('staff.events.dispatch');
    Route::put('/events/{booking}/inventory/{inventoryItem}', [\App\Http\Controllers\Staff\EventController::class, 'updatePhysicalStock'])->name('staff.events.inventory.update');
    Route::put('/events/{booking}/return', [\App\Http\Controllers\Staff\EventController::class, 'submitReturn'])->name('staff.events.return.update');
    Route::put('/events/{booking}/return/condition', [\App\Http\Controllers\Staff\EventController::class, 'recordCondition'])->name('staff.events.return.condition');
    Route::post('/events/{booking}/reply', [\App\Http\Controllers\Staff\EventController::class, 'reply'])->name('staff.events.reply');
});

// Client Routes: Protected by authentication and email verification
Route::prefix('client')->middleware(['auth', 'verified', 'client'])->group(function () {
    // Client Dashboard: Main portal overview
    Route::get('/dashboard', function () {
        $user = Auth::user();
        $client = $user ? \App\Models\Client::firstOrCreate(
            ['email' => $user->email],
            [
                'full_name' => $user->name,
                'phone' => $user->mobile_number,
                'address' => $user->address,
            ]
        ) : null;

        return view('client.dashboard', [
            'bookings' => $client ? $client->bookings()->latest()->get() : collect(),
        ]);
    })->name('client.dashboard');

    // Client notifications
    Route::get('/notifications', [\App\Http\Controllers\ClientNotificationController::class, 'index'])->name('client.notifications.index');
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\ClientNotificationController::class, 'markAsRead'])->name('client.notifications.read');
    Route::post('/notifications/{notification}/mark-as-read', [\App\Http\Controllers\ClientNotificationController::class, 'markAsReadAjax'])->name('client.notifications.mark-as-read');
    Route::post('/notifications/read-all', [\App\Http\Controllers\ClientNotificationController::class, 'markAllAsRead'])->name('client.notifications.read-all');
    // Admin may open a client's booking updates for supervision; the controller enforces admin-or-owner.
    Route::get('/bookings/{booking}/updates', [\App\Http\Controllers\ClientNotificationController::class, 'bookingUpdates'])->withoutMiddleware('client')->name('client.booking.updates');

    // Client Bookings: Lists user's active bookings
    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings');
    
    // Client Guest Booking Claim
    Route::get('/claim-booking/{token}', [\App\Http\Controllers\Client\ClaimGuestBookingController::class, 'show'])->name('client.claim-guest-booking.show');
    Route::post('/claim-booking/{token}', [\App\Http\Controllers\Client\ClaimGuestBookingController::class, 'claim'])->name('client.claim-guest-booking.claim');

    // Client Booking Create: Displays new booking form
    Route::get('/bookings/create', [BookingController::class, 'create'])->name('bookings.create');

    // Client Booking Store: Processes booking form submission and shows AI analysis
    Route::post('/bookings/create', [BookingController::class, 'store'])->middleware('throttle:5,1,client-booking')->name('bookings.store');

    // Client Booking Show / Analysis: Displays the booking proposal and pricing summary
    Route::get('/bookings/{booking}/status', [BookingController::class, 'status'])->name('bookings.status');
    Route::get('/bookings/{booking}', [BookingController::class, 'analysis'])->name('bookings.show');
    Route::get('/bookings/analysis/{booking}', [BookingController::class, 'analysis'])->name('bookings.analysis');
    Route::post('/bookings/{booking}/accept', [BookingController::class, 'acceptQuotation'])->name('bookings.accept');
    Route::post('/bookings/{booking}/request-changes', [BookingController::class, 'requestChanges'])->name('bookings.request-changes');
    Route::post('/bookings/{booking}/reply', [BookingController::class, 'replyToAdmin'])->name('bookings.reply');
    Route::get('/bookings/{booking}/messages', [BookingController::class, 'getMessages'])->name('bookings.messages.index');
    Route::post('/bookings/{booking}/messages', [BookingController::class, 'sendMessage'])->name('bookings.messages.store');
    Route::post('/bookings/{booking}/messages/read', [BookingController::class, 'markMessagesRead'])->name('bookings.messages.read');
    Route::post('/bookings/{booking}/request-cancellation', [BookingController::class, 'requestCancellation'])->name('bookings.request-cancellation');
    Route::post('/bookings/{booking}/delivery-received', [BookingController::class, 'confirmDeliveryReceived'])->name('bookings.delivery-received');
    Route::post('/bookings/{booking}/payment-reference', [BookingController::class, 'submitPaymentReference'])->name('bookings.payment.reference');
    Route::post('/bookings/{booking}/proposals/{presentation}/feedback', [BookingController::class, 'submitProposalFeedback'])->name('bookings.proposals.feedback');
    // Client ↔ Raflora meetings (README Client Workflow)
    Route::post('/bookings/{booking}/meetings', [\App\Http\Controllers\Client\BookingMeetingController::class, 'store'])->middleware('throttle:10,1')->name('bookings.meetings.store');
    Route::post('/bookings/{booking}/meetings/{meeting}/cancel', [\App\Http\Controllers\Client\BookingMeetingController::class, 'cancel'])->name('bookings.meetings.cancel');

    // Client Booking History: Shows past bookings
    Route::get('/booking-history', [BookingController::class, 'history'])->name('booking-history');

    // Client Account Settings: User profile management
    Route::get('/account-settings', function (Request $request) {
        $user = $request->user();
        return view('client.account-settings', [
            'user' => $user,
            'success' => session('success'),
            'error' => session('error'),
        ]);
    })->name('account-settings');

    Route::post('/account-settings', function (Request $request) {
        $user = $request->user();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'profile_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'current_password' => ['nullable', 'required_with:new_password', 'string'],
            'new_password' => ['nullable', 'required_with:current_password', 'string', 'min:8', 'confirmed'],
        ]);

        // Client records are linked to user accounts by email. An email change must carry the
        // user's client record (and its bookings) along, must never attach the account to
        // someone else's client record, and must be re-verified before portal access resumes.
        $emailChanged = strcasecmp(trim($data['email']), (string) $user->email) !== 0;
        $currentClient = \App\Models\Client::where('email', $user->email)->first();
        if ($emailChanged) {
            $emailTakenByAnotherClient = \App\Models\Client::where('email', $data['email'])
                ->when($currentClient, fn ($q) => $q->whereKeyNot($currentClient->id))
                ->exists();
            if ($emailTakenByAnotherClient) {
                return back()->withErrors(['email' => 'This email address is already linked to another client record. Please contact Raflora for help.'])->withInput();
            }
        }

        $oldProfileImage = $user->profile_image;
        $newProfileImagePath = null;
        $profileSaveCompleted = false;

        try {
            if ($request->filled('current_password') || $request->filled('new_password')) {
                if (!\Hash::check($request->current_password, $user->password)) {
                    return back()->withErrors(['current_password' => 'Current password is incorrect.'])->withInput();
                }

                if ($request->filled('new_password')) {
                    $user->password = \Hash::make($request->new_password);
                }
            }

            if ($request->hasFile('profile_image')) {
                $newProfileImagePath = $request->file('profile_image')->store('profile-images', 'public');
                if (! $newProfileImagePath) {
                    throw new \RuntimeException('The profile photo could not be stored.');
                }
                $user->profile_image = $newProfileImagePath;
            }

            $user->first_name = $data['first_name'];
            $user->last_name = $data['last_name'];
            $user->name = $data['first_name'] . ' ' . $data['last_name'];
            $user->email = $data['email'];
            $user->mobile_number = $data['mobile_number'] ?? null;
            $user->address = $data['address'] ?? null;
            if ($emailChanged) {
                $user->email_verified_at = null;
            }

            \Illuminate\Support\Facades\DB::transaction(function () use ($user, $currentClient, $emailChanged, $data) {
                $user->save();
                if ($emailChanged && $currentClient) {
                    $currentClient->update(['email' => $data['email']]);
                }
            });
            $profileSaveCompleted = true;

            if ($newProfileImagePath && $oldProfileImage && $oldProfileImage !== $newProfileImagePath) {
                \Storage::disk('public')->delete($oldProfileImage);
            }

            if ($emailChanged) {
                \App\Models\AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'client_email_changed',
                    'module' => 'account',
                    'event_type' => 'client_email_changed',
                    'details' => 'Client account email changed; re-verification required.',
                    'ip_address' => $request->ip(),
                    'entity_type' => User::class,
                    'entity_id' => $user->id,
                ]);

                $otpService = app(\App\Services\OtpService::class);
                $otp = $otpService->generate($user, 'email_verification');
                $sent = $otpService->sendOtp($user, $otp, 10, 'email_verification');

                $redirect = redirect()->route('verification.notice')
                    ->with('info', 'Your email address was updated. Please verify the new address to continue using your client portal.');

                return $sent
                    ? $redirect
                    : $redirect->withErrors(['otp' => 'We were unable to deliver your verification code. Please click resend to try again.']);
            }

            return redirect()->route('account-settings')->with('success', 'Account settings updated successfully.');
        } catch (\Throwable $e) {
            if (! $profileSaveCompleted && $newProfileImagePath && \Storage::disk('public')->exists($newProfileImagePath)) {
                \Storage::disk('public')->delete($newProfileImagePath);
            }
            \Log::error('Account settings update error: ' . $e->getMessage());
            return back()->withErrors(['account' => 'Update failed. Please try again.'])->withInput();
        }
    })->name('account-settings.update');
});

// Admin Bootstrap Setup Routes: Accessible only to bootstrap Admin
Route::prefix('admin/setup')->middleware(['auth', 'admin', 'admin.setup'])->group(function () {
    Route::get('/', [AdminBootstrapController::class, 'show'])->name('admin.setup');
    Route::post('/email', [AdminBootstrapController::class, 'submitEmail'])->middleware('throttle:5,1')->name('admin.setup.email');
    Route::post('/otp', [AdminBootstrapController::class, 'verifyOtp'])->middleware('throttle:6,1')->name('admin.setup.otp');
    Route::post('/otp/resend', [AdminBootstrapController::class, 'resendOtp'])->middleware('throttle:3,1')->name('admin.setup.resend');
    Route::post('/password', [AdminBootstrapController::class, 'updatePassword'])->middleware('throttle:5,1')->name('admin.setup.password');
    Route::post('/acknowledge', [AdminBootstrapController::class, 'acknowledgeRecoveryCode'])->name('admin.setup.acknowledge');
    Route::post('/reissue-code', [AdminBootstrapController::class, 'reissueRecoveryCode'])->middleware('throttle:5,1')->name('admin.setup.reissue-code');
});

// Admin Routes: Protected by authentication, admin role, and completed setup middleware
Route::prefix('admin')->middleware(['auth', 'admin', 'admin.setup'])->group(function () {
    // Admin Email Change Routes
    Route::get('/email-change', [AdminEmailChangeController::class, 'show'])->name('admin.email-change.show');
    Route::post('/email-change/submit', [AdminEmailChangeController::class, 'submitNewEmail'])->middleware('throttle:5,1')->name('admin.email-change.submit');
    Route::post('/email-change/verify', [AdminEmailChangeController::class, 'verifyOtp'])->middleware('throttle:6,1')->name('admin.email-change.verify');
    Route::post('/email-change/resend', [AdminEmailChangeController::class, 'resendOtp'])->middleware('throttle:3,1')->name('admin.email-change.resend');

    // Admin Dashboard: Main operational overview
    Route::get('/dashboard', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('admin.dashboard');

    Route::get('/notifications', [AdminBookingController::class, 'notifications'])->name('admin.notifications');

    // Admin Alerts: Mark a single alert as read
    // A request waiting for Admin's approve/reject decision is closed only by that decision.
    Route::post('/alerts/{alert}/read', function (\App\Models\AdminAlert $alert) {
        if ($alert->requiresDecision() && !$alert->is_read) {
            return back()->with('error', 'This stock count request needs a decision. Open it in Notifications and approve or reject it.');
        }
        $alert->update(['is_read' => true]);
        return back()->with('success', 'Alert dismissed.');
    })->name('admin.alerts.read');

    Route::post('/notifications/resolve-shortage/{inventoryItem}', [\App\Http\Controllers\Admin\InventoryController::class, 'resolveShortage'])->name('admin.notifications.resolve-shortage');

    // Admin Alerts: Dismiss all alerts
    // Requests waiting for an approve/reject decision stay open.
    Route::post('/alerts/read-all', function () {
        \App\Models\AdminAlert::where('is_read', false)
            ->whereNotIn('type', \App\Models\AdminAlert::DECISION_TYPES)
            ->update(['is_read' => true]);
        $waiting = \App\Models\AdminAlert::where('is_read', false)->whereIn('type', \App\Models\AdminAlert::DECISION_TYPES)->count();

        return back()->with('success', $waiting > 0
            ? "Alerts dismissed. {$waiting} stock count request(s) still need your approve or reject decision."
            : 'All alerts dismissed.');
    })->name('admin.alerts.read-all');

    // Admin Stock Adjustments (Decision A)
    Route::post('/inventory/adjustments/{alert}/approve', [AdminBookingController::class, 'approveStockAdjustment'])->name('admin.inventory.adjustments.approve');
    Route::post('/inventory/adjustments/{alert}/reject', [AdminBookingController::class, 'rejectStockAdjustment'])->name('admin.inventory.adjustments.reject');

    // Admin Gallery
    Route::get('/gallery', [\App\Http\Controllers\Admin\GalleryController::class, 'index'])->name('admin.gallery');
    Route::get('/gallery/archived', [\App\Http\Controllers\Admin\GalleryController::class, 'archived'])->name('admin.gallery.archived');
    Route::post('/gallery/{gallery}/restore', [\App\Http\Controllers\Admin\GalleryController::class, 'restore'])->name('admin.gallery.restore');
    Route::get('/gallery/create', [\App\Http\Controllers\Admin\GalleryController::class, 'create'])->name('admin.gallery.create');
    Route::post('/gallery', [\App\Http\Controllers\Admin\GalleryController::class, 'store'])->name('admin.gallery.store');
    Route::get('/gallery/{gallery}/edit', [\App\Http\Controllers\Admin\GalleryController::class, 'edit'])->name('admin.gallery.edit');
    Route::put('/gallery/{gallery}', [\App\Http\Controllers\Admin\GalleryController::class, 'update'])->name('admin.gallery.update');
    Route::delete('/gallery/{gallery}', [\App\Http\Controllers\Admin\GalleryController::class, 'destroy'])->name('admin.gallery.destroy');

    // Admin Packages
    Route::get('/packages/archived', [\App\Http\Controllers\Admin\PackageController::class, 'archived'])->name('admin.packages.archived');
    Route::get('/packages/template/packages', [\App\Http\Controllers\Admin\PackageController::class, 'downloadPackageTemplate'])->name('admin.packages.template.packages');
    Route::get('/packages/template/materials', [\App\Http\Controllers\Admin\PackageController::class, 'downloadMaterialsTemplate'])->name('admin.packages.template.materials');
    Route::get('/packages/export/packages', [\App\Http\Controllers\Admin\PackageController::class, 'exportPackagesCsv'])->name('admin.packages.export.packages');
    Route::get('/packages/export/materials', [\App\Http\Controllers\Admin\PackageController::class, 'exportMaterialsCsv'])->name('admin.packages.export.materials');
    Route::post('/packages/import/packages', [\App\Http\Controllers\Admin\PackageController::class, 'importPackagesCsv'])->name('admin.packages.import.packages');
    Route::post('/packages/import/materials', [\App\Http\Controllers\Admin\PackageController::class, 'importMaterialsCsv'])->name('admin.packages.import.materials');
    Route::post('/packages/{package}/restore', [\App\Http\Controllers\Admin\PackageController::class, 'restore'])->name('admin.packages.restore');
    Route::post('/packages/{package}/archive', [\App\Http\Controllers\Admin\PackageController::class, 'archive'])->name('admin.packages.archive');
    Route::resource('packages', \App\Http\Controllers\Admin\PackageController::class)
        ->except(['show'])
        ->names('admin.packages');

    // Admin Bookings: Booking management listing
    Route::get('/bookings', [AdminBookingController::class, 'index'])->name('admin.bookings');
    Route::get('/bookings/{booking}/edit', [AdminBookingController::class, 'edit'])->name('admin.bookings.edit');
    // Admin Booking Review: Specific booking detail view
    Route::get('/bookings/{booking}', [AdminBookingController::class, 'show'])->name('admin.bookings.show');
    Route::put('/bookings/{booking}', [AdminBookingController::class, 'update'])->name('admin.bookings.update');
    Route::post('/bookings/{booking}/assign-staff', [AdminBookingController::class, 'assignStaff'])->name('admin.bookings.assign-staff');
    Route::post('/bookings/{booking}/complete-review', [AdminBookingController::class, 'completeReview'])->name('admin.bookings.complete-review');
    // Client ↔ Raflora meetings (README Client Workflow)
    Route::post('/bookings/{booking}/meetings', [\App\Http\Controllers\Admin\BookingMeetingController::class, 'store'])->name('admin.bookings.meetings.store');
    Route::post('/bookings/{booking}/meetings/{meeting}/confirm', [\App\Http\Controllers\Admin\BookingMeetingController::class, 'confirm'])->name('admin.bookings.meetings.confirm');
    Route::post('/bookings/{booking}/meetings/{meeting}/complete', [\App\Http\Controllers\Admin\BookingMeetingController::class, 'complete'])->name('admin.bookings.meetings.complete');
    Route::post('/bookings/{booking}/meetings/{meeting}/cancel', [\App\Http\Controllers\Admin\BookingMeetingController::class, 'cancel'])->name('admin.bookings.meetings.cancel');
    Route::post('/bookings/{booking}/presentations', [AdminBookingController::class, 'storeProposal'])->name('admin.bookings.presentations.store');
    // Dedicated AI suggestion actions (link/promote) to avoid validating full booking payload
    Route::post('/bookings/{booking}/ai-link', [AdminBookingController::class, 'linkAiItem'])->name('admin.bookings.ai.link');
    Route::post('/bookings/{booking}/ai-promote', [AdminBookingController::class, 'promoteAiItem'])->name('admin.bookings.ai.promote');
    Route::post('/bookings/{booking}/items/{bookingItem}/confirm', [AdminBookingController::class, 'confirmMaterial'])->name('admin.bookings.items.confirm');
    Route::post('/bookings/{booking}/items/confirm-all', [AdminBookingController::class, 'confirmAllMaterials'])->name('admin.bookings.items.confirm-all');
    Route::post('/bookings/{booking}/final-approve', [AdminBookingController::class, 'finalApproveQuotation'])->name('admin.bookings.final-approve');
    // Admin negotiation loop
    Route::post('/bookings/{booking}/reply', [AdminBookingController::class, 'reply'])->name('admin.bookings.reply');
    Route::get('/bookings/{booking}/messages', [AdminBookingController::class, 'getMessages'])->name('admin.bookings.messages.index');
    Route::post('/bookings/{booking}/messages', [AdminBookingController::class, 'sendMessage'])->name('admin.bookings.messages.store');
    Route::post('/bookings/{booking}/messages/read', [AdminBookingController::class, 'markMessagesRead'])->name('admin.bookings.messages.read');
    Route::post('/bookings/{booking}/handle-cancellation', [AdminBookingController::class, 'handleCancellationRequest'])->name('admin.bookings.handle-cancellation');
    // Admin actions: verify payment, reject payment, and decline booking
    Route::post('/payments/{payment}/verify', [AdminBookingController::class, 'verifyPayment'])->name('admin.payments.verify');
    Route::post('/payments/{payment}/reject', [AdminBookingController::class, 'rejectPayment'])->name('admin.payments.reject');
    Route::post('/bookings/{booking}/final-payment', [AdminBookingController::class, 'finalPayment'])->name('admin.bookings.final_payment');
    Route::post('/bookings/{booking}/decline', [AdminBookingController::class, 'decline'])->name('admin.bookings.decline');
    Route::post('/bookings/{booking}/reserve-materials', [AdminBookingController::class, 'reserveMaterials'])->name('admin.bookings.reserve-materials');
    Route::post('/bookings/{booking}/confirm-fresh-flowers', [AdminBookingController::class, 'confirmFreshFlowers'])->name('admin.bookings.confirm-fresh-flowers');
    Route::post('/bookings/{booking}/items/{bookingItem}/fresh-procurement', [AdminBookingController::class, 'updateFreshProcurementClassification'])->name('admin.bookings.items.fresh-procurement');
    Route::post('/bookings/{booking}/delivery-received', [AdminBookingController::class, 'markDeliveryReceived'])->name('admin.bookings.delivery-received');
    Route::post('/bookings/{booking}/schedule-requests/{scheduleRequest}', [AdminBookingController::class, 'decideScheduleRequest'])->name('admin.bookings.schedule-requests.decide');
    Route::post('/bookings/{booking}/resolve-shortages', [AdminBookingController::class, 'resolveBookingShortages'])->name('admin.bookings.resolve-shortages');
    Route::post('/notifications/resolve-booking-shortages/{booking}', [AdminBookingController::class, 'resolveBookingShortages'])->name('admin.notifications.resolve-booking-shortages');
    Route::post('/notifications/promote-booking-items/{booking}', [AdminBookingController::class, 'promoteBookingItemsToInventory'])->name('admin.notifications.promote-booking-items');
    Route::get('/notifications/booking-shortages/{booking}/export', [\App\Http\Controllers\Admin\BookingShortageExportController::class, 'csv'])->name('admin.notifications.booking-shortages.export');
    Route::get('/notifications/booking-shortages/{booking}/report', [\App\Http\Controllers\Admin\BookingShortageExportController::class, 'report'])->name('admin.notifications.booking-shortages.report');
    // Admin Dispatch
    Route::post('/bookings/{booking}/dispatch', [AdminBookingController::class, 'dispatchItems'])->name('admin.bookings.dispatch');
    Route::post('/bookings/transactions/{transaction}/correct-dispatch', [AdminBookingController::class, 'correctDispatch'])->name('admin.bookings.dispatch.correct');
    
    // Admin Inventory: Inventory management listing and CRUD
    Route::get('/inventory/archived', [\App\Http\Controllers\Admin\InventoryController::class, 'archived'])->name('admin.inventory.archived');
    Route::get('/inventory/template', [\App\Http\Controllers\Admin\InventoryController::class, 'downloadTemplate'])->name('admin.inventory.template');
    Route::get('/inventory/export', [\App\Http\Controllers\Admin\InventoryController::class, 'exportCsv'])->name('admin.inventory.export');
    Route::post('/inventory/import', [\App\Http\Controllers\Admin\InventoryController::class, 'importCsv'])->name('admin.inventory.import');
    Route::get('/inventory', [\App\Http\Controllers\Admin\InventoryController::class, 'index'])->name('admin.inventory.index');
    Route::get('/inventory/create', [\App\Http\Controllers\Admin\InventoryController::class, 'create'])->name('admin.inventory.create');
    Route::post('/inventory', [\App\Http\Controllers\Admin\InventoryController::class, 'store'])->name('admin.inventory.store');
    Route::get('/inventory/{inventoryItem}/edit', [\App\Http\Controllers\Admin\InventoryController::class, 'edit'])->name('admin.inventory.edit');
    Route::put('/inventory/{inventoryItem}', [\App\Http\Controllers\Admin\InventoryController::class, 'update'])->name('admin.inventory.update');
    Route::post('/inventory/{inventoryItem}/receive-stock', [\App\Http\Controllers\Admin\InventoryController::class, 'receiveStock'])->name('admin.inventory.receive-stock');
    Route::post('/inventory/{inventoryItem}/adjust-stock', [\App\Http\Controllers\Admin\InventoryController::class, 'adjustStock'])->name('admin.inventory.adjust-stock');
    Route::post('/inventory/{inventoryItem}/archive', [\App\Http\Controllers\Admin\InventoryController::class, 'archive'])->name('admin.inventory.archive');
    Route::post('/inventory/{id}/restore', [\App\Http\Controllers\Admin\InventoryController::class, 'restore'])->name('admin.inventory.restore');

    // Admin Return Tracking
    Route::get('/return-tracking', [\App\Http\Controllers\Admin\ReturnTrackingController::class, 'index'])->name('admin.return-tracking');
    Route::get('/return-tracking/manage/{booking}', [\App\Http\Controllers\Admin\ReturnTrackingController::class, 'manage'])->name('admin.return-tracking.manage');
    Route::post('/return-tracking/start/{booking}', [\App\Http\Controllers\Admin\ReturnTrackingController::class, 'start'])->name('admin.return-tracking.start');
    Route::get('/return-tracking/{return}', [\App\Http\Controllers\Admin\ReturnTrackingController::class, 'show'])->name('admin.return-tracking.show');
    Route::put('/return-tracking/{return}', [\App\Http\Controllers\Admin\ReturnTrackingController::class, 'update'])->name('admin.return-tracking.update');
    Route::put('/return-tracking/{return}/assign', [\App\Http\Controllers\Admin\ReturnTrackingController::class, 'assign'])->name('admin.return-tracking.assign');
    Route::put('/return-tracking/{return}/approve', [\App\Http\Controllers\Admin\ReturnTrackingController::class, 'approve'])->name('admin.return-tracking.approve');
    Route::put('/return-tracking/{return}/resolve', [\App\Http\Controllers\Admin\ReturnTrackingController::class, 'resolveIssues'])->name('admin.return-tracking.resolve');
    // Admin account management & settings
    Route::get('/users', function () {
        return redirect()->route('admin.settings');
    })->name('admin.users');
    Route::post('/account-management/password', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'updatePassword'])->name('admin.account.password');
    Route::post('/account-management/accounts', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'storeAccount'])->name('admin.account.accounts.store');

    // Admin Account Settings operations
    Route::get('/settings', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'index'])->name('admin.settings');
    Route::get('/settings/audit-log/export', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'exportAuditLog'])->name('admin.settings.audit-log.export');
    Route::post('/settings', [\App\Http\Controllers\Admin\SettingController::class, 'update'])->name('admin.settings.update');
    Route::post('/settings/profile', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'updateProfile'])->name('admin.settings.profile.update');
    Route::post('/settings/password', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'updatePassword'])->name('admin.settings.password.update');
    Route::post('/settings/sessions/revoke-others', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'revokeOtherSessions'])->name('admin.settings.sessions.revoke-others');
    Route::post('/settings/trusted-devices/revoke', [\App\Http\Controllers\Admin\AdminSettingsController::class, 'revokeTrustedDevice'])->name('admin.settings.trusted-devices.revoke');
    // Admin AI Analysis: Review AI suggested floral materials and pricing
    Route::get('/ai-analysis', [ReportController::class, 'aiAnalysis'])->name('admin.ai-analysis');
    // Admin Quotations: Manage price reconfirmation and quotations
    Route::get('/quotations', [\App\Http\Controllers\Admin\QuotationController::class, 'index'])->name('admin.quotations');
    // Note: Admin Return Tracking real routes are defined above (lines 170-173)
    // Admin Reports: KPI charts and operational activity
    Route::get('/reports', [ReportController::class, 'index'])->name('admin.reports');
    Route::get('/reports/export', [ReportController::class, 'exportCsv'])->name('admin.reports.export');
    // Admin Client Records: View client history and records
    Route::get('/client-records', [\App\Http\Controllers\Admin\ClientRecordController::class, 'index'])->name('admin.client-records');

    // Admin System Data Management: Business-data export, validated import, and synthetic demo datasets
    Route::get('/system-data/export', [\App\Http\Controllers\Admin\SystemDataController::class, 'export'])->name('admin.system-data.export');
    Route::get('/system-data/demo', [\App\Http\Controllers\Admin\SystemDataController::class, 'demo'])->name('admin.system-data.demo');
    Route::post('/system-data/preview', [\App\Http\Controllers\Admin\SystemDataController::class, 'preview'])->name('admin.system-data.preview');
    Route::post('/system-data/import', [\App\Http\Controllers\Admin\SystemDataController::class, 'import'])->name('admin.system-data.import');
});

#Temporary route for testing Gemini Vision Service
use App\Services\GeminiVisionService;

Route::get('/test-gemini', function (GeminiVisionService $service) {
    abort_unless(app()->environment('local'), 404);

    // Uses the flowaah1.jpg file in your public folder
    $imagePath = public_path('flowaah1.jpg');

    if (!file_exists($imagePath)) {
        return response()->json(['error' => 'Image flowaah1.jpg not found in public folder!'], 404);
    }

    $result = $service->analyzeImageFromPath($imagePath);

    return response()->json($result);
});

// PHASE 4 UI MOCK ROUTES — temporary UI previews; never exposed outside local development.
if (app()->environment('local')) {
    Route::middleware(['web'])->group(function () {
        Route::get('/mock', [\App\Http\Controllers\MockUIController::class, 'hub'])->name('mock.hub');
        Route::get('/mock/client/{scenario}', [\App\Http\Controllers\MockUIController::class, 'clientScenario'])->name('mock.client');
        Route::get('/mock/admin/{scenario}', [\App\Http\Controllers\MockUIController::class, 'adminScenario'])->name('mock.admin');
        Route::get('/mock/staff/{scenario}', [\App\Http\Controllers\MockUIController::class, 'staffScenario'])->name('mock.staff');
    });
}