<x-app-layout title="Verify Your Email">
    <x-auth-layout 
        bgImage="raflora-auth-login.jpg"
        brandStyle="plain"
        :hideBrandingOnMobile="true"
        formWidth="max-w-lg"
    >
        {{-- 
        RAFLORA UI FUNCTION

        Function:
        Verify Email

        Actor:
        Guest / Client

        Purpose:
        Validates the user's email address by requiring a 6-digit OTP sent to their email.

        Current Phase:
        UI-FIRST

        Current Behavior:
        Uses existing email verification flow.

        Expected Backend Action:
        Validate the submitted OTP against the stored code for the authenticated user and mark email as verified.

        Required Conditions:
        User must be authenticated but unverified. Valid unexpired OTP.

        Success Feedback:
        Redirect to authenticated dashboard/role destination with success message.

        Error/Validation Feedback:
        Validation errors for invalid, expired, or rate-limited OTP attempts.

        Next UI State:
        Authenticated dashboard/role destination on success.

        Allowed Next Actions:
        Submit OTP, resend OTP (if cooldown expired), or logout.

        Restrictions:
        User cannot access other protected routes until verified.

        Backend Dependency:
        Existing Laravel verification implementation.

        Notes:
        Includes JS for cooldown timer on resend.
        --}}
        <div class="w-full mx-auto bg-white rounded-3xl shadow-lg border border-raflora-border p-6 lg:p-8">
            <div class="text-center mb-6">
                <img src="{{ asset('assets/images/raflora-logo-nobackground.png') }}" alt="Raflora Logo" class="h-12 md:h-16 w-auto mx-auto mb-3 object-contain">
                <h1 class="font-serif text-raflora-heading text-3xl lg:text-4xl font-bold mb-2">Verify Your Email</h1>
                @if($errors->has('otp'))
                    <p class="font-sans text-raflora-muted text-base lg:text-lg px-2">
                        Verification code for<br>
                        <span class="font-bold text-raflora-text tracking-wider">{{ $email }}</span>
                    </p>
                @else
                    <p class="font-sans text-raflora-muted text-base lg:text-lg px-2">
                        A 6-digit verification code has been sent to<br>
                        <span class="font-bold text-raflora-text tracking-wider">{{ $email }}</span>
                    </p>
                @endif
            </div>

            @if(session('status'))
                <div class="mb-6 p-3 rounded-lg bg-raflora-primary-50 border border-raflora-primary-200 text-raflora-primary-800 text-sm text-center" aria-live="polite">
                    {{ session('status') }}
                </div>
            @endif

            @if(session('info'))
                <div class="mb-6 p-3 rounded-lg bg-raflora-navy-50 border border-raflora-navy-100 text-raflora-navy-800 text-sm text-center" aria-live="polite">
                    {{ session('info') }}
                </div>
            @endif

            @if(session('error'))
                <div class="mb-6 p-3 rounded-lg bg-raflora-danger-50 border border-red-200 text-raflora-danger text-sm text-center" aria-live="assertive">
                    {{ session('error') }}
                </div>
            @endif

            @if($errors->any() && !$errors->has('resend'))
                <div class="mb-6 p-3 rounded-lg bg-raflora-danger-50 border border-red-200 text-raflora-danger text-sm" aria-live="assertive">
                    <ul class="space-y-1">
                        @foreach(collect($errors->getMessages())->except('resend')->flatten() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($errors->has('resend'))
                <div class="mb-6 p-3 rounded-lg bg-raflora-warning-50 border border-raflora-warning-500 text-raflora-warning-700 text-sm text-center" aria-live="assertive">
                    {{ $errors->first('resend') }}
                </div>
            @endif

            <!-- OTP Submission Form -->
            <form method="POST" action="{{ route('verification.verify') }}" class="space-y-5 font-sans" id="otp-form" novalidate>
                @csrf
                <div id="otp-error-container" class="hidden mb-4">
                    <div class="p-3 rounded-lg bg-raflora-danger-50 border border-red-200 text-raflora-danger text-sm">
                        <span id="otp-error-message"></span>
                    </div>
                </div>

                {{-- OTP input --}}
                <div class="rf-field relative">
                    <label for="otp" class="rf-label text-sm md:text-base mb-1.5 block text-raflora-heading font-bold text-center">Verification Code</label>
                    <input
                        type="text"
                        name="otp"
                        id="otp"
                        placeholder="&bull;&bull;&bull;&bull;&bull;&bull;"
                        value="{{ old('otp') }}"
                        class="rf-input w-full px-4 py-3 md:py-3.5 rounded-xl border border-raflora-border text-center tracking-[0.5em] text-2xl font-bold text-raflora-heading bg-gray-50 focus:bg-white transition-colors placeholder:tracking-widest placeholder:font-normal"
                        maxlength="6"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        autofocus
                        required
                    >
                    <p class="text-xs text-raflora-muted text-center mt-2">
                        Code expires in 10 minutes. 5 attempts permitted.
                    </p>
                </div>

                <button
                    type="submit"
                    id="verify-btn"
                    class="w-full rf-btn-primary py-3 md:py-3.5 rounded-full text-base md:text-lg mt-4 flex items-center justify-center gap-2.5 group font-semibold shadow-md"
                >
                    <span>Verify Email</span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 group-hover:translate-x-1 transition-transform">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                    </svg>
                </button>
            </form>

            <script>
                document.getElementById('otp-form').addEventListener('submit', function(e) {
                    const otpInput = document.getElementById('otp');
                    const errorContainer = document.getElementById('otp-error-container');
                    const errorMessage = document.getElementById('otp-error-message');
                    
                    const isValid = /^\d{6}$/.test(otpInput.value);
                    if (!isValid) {
                        e.preventDefault();
                        errorMessage.textContent = 'Enter the 6-digit verification code.';
                        errorContainer.classList.remove('hidden');
                        otpInput.focus();
                    } else {
                        errorContainer.classList.add('hidden');
                    }
                });
            </script>

            {{-- Resend form --}}
            <div class="mt-8 text-center border-t border-raflora-border pt-6">
                @if(($cooldownSeconds ?? 0) > 0)
                    <p class="text-raflora-muted text-sm font-medium" id="resend-cooldown">
                        Resend available in <span id="countdown" class="text-raflora-heading">{{ $cooldownSeconds }}</span>s
                    </p>
                    <script>
                        (function () {
                            var seconds = {{ (int)($cooldownSeconds ?? 0) }};
                            var el = document.getElementById('countdown');
                            var wrapper = document.getElementById('resend-cooldown');
                            if (!el || seconds <= 0) return;

                            var interval = setInterval(function () {
                                seconds--;
                                el.textContent = seconds;
                                if (seconds <= 0) {
                                    clearInterval(interval);
                                    wrapper.innerHTML = '<form method="POST" action="{{ route('verification.resend') }}" class="inline">' +
                                        '@csrf' +
                                        '<button type="submit" class="text-raflora-primary-600 hover:text-raflora-primary-700 font-bold transition text-sm focus-visible:outline-none hover:underline">' +
                                        'Resend verification code' +
                                        '</button></form>';
                                }
                            }, 1000);
                        })();
                    </script>
                @else
                    <form method="POST" action="{{ route('verification.resend') }}" class="inline">
                        @csrf
                        <button
                            type="submit"
                            class="text-raflora-primary-600 hover:text-raflora-primary-700 font-bold transition text-sm focus-visible:outline-none hover:underline"
                        >
                            Resend verification code
                        </button>
                    </form>
                @endif
            </div>

            <div class="mt-6 text-center">
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button
                        type="submit"
                        class="text-raflora-muted hover:text-raflora-heading transition text-sm font-medium hover:underline focus-visible:outline-none"
                    >
                        Log Out / Use Different Account
                    </button>
                </form>
            </div>
        </div>
    </x-auth-layout>
</x-app-layout>
