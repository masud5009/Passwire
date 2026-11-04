@extends('layouts.dashboard')

@section('title', 'Change Password & Security')

@section('content')
<div class="flex justify-center w-full py-4 sm:py-6" x-data="{
    disableModalOpen: false,
    regenModalOpen: false,
    showRecoveryCodes: {{ session('recovery_codes_generated') ? 'true' : 'false' }}
}">
    <div class="w-full max-w-2xl space-y-6">

        <!-- Form 1: Password & Display Preferences -->
        <form action="{{ route('dashboard.password.update') }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Card 1: Change Password -->
            <div class="bg-white dark:bg-[#121622] border border-zinc-200/90 dark:border-white/[0.08] rounded-2xl p-6 sm:p-7 shadow-sm space-y-6">
                <div class="flex items-center justify-between pb-4 border-b border-zinc-100 dark:border-white/[0.06] flex-wrap gap-2">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400 flex-shrink-0">
                            <i data-lucide="lock" class="w-4.5 h-4.5"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-zinc-900 dark:text-white">Change Password</h3>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Ensure your account uses a strong, unique password.</p>
                        </div>
                    </div>
                    <span class="text-xs text-zinc-400 dark:text-zinc-500 font-mono">Leave blank to keep existing</span>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 mb-1.5">Current Password</label>
                        <input type="password" name="current_password" placeholder="Required only if changing password" autocomplete="current-password"
                            class="w-full bg-zinc-50 dark:bg-[#09090b] border border-zinc-200/90 dark:border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500 transition-colors">
                        @error('current_password')
                            <p class="text-xs text-red-500 dark:text-red-400 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 mb-1.5">New Password</label>
                            <input type="password" name="password" placeholder="Minimum 8 characters" autocomplete="new-password"
                                class="w-full bg-zinc-50 dark:bg-[#09090b] border border-zinc-200/90 dark:border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500 transition-colors">
                            @error('password')
                                <p class="text-xs text-red-500 dark:text-red-400 mt-1.5">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 mb-1.5">Confirm New Password</label>
                            <input type="password" name="password_confirmation" placeholder="Repeat new password" autocomplete="new-password"
                                class="w-full bg-zinc-50 dark:bg-[#09090b] border border-zinc-200/90 dark:border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500 transition-colors">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 2: Sign-in Security Preferences -->
            <div class="bg-white dark:bg-[#121622] border border-zinc-200/90 dark:border-white/[0.08] rounded-2xl p-6 sm:p-7 shadow-sm space-y-5">
                <div class="flex items-center gap-3 pb-4 border-b border-zinc-100 dark:border-white/[0.06]">
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400 flex-shrink-0">
                        <i data-lucide="shield-alert" class="w-4.5 h-4.5"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">Sign-in Security Preferences</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Control the visibility of default credentials on the public login page.</p>
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-[#09090b] border border-zinc-200/90 dark:border-white/10 transition-colors">
                    <label class="flex items-start gap-3.5 cursor-pointer select-none">
                        <input type="checkbox" name="hide_demo_credentials" value="1"
                            {{ !($siteSetting->show_demo_credentials ?? $globalSiteSettings->show_demo_credentials ?? true) ? 'checked' : '' }}
                            class="mt-1 w-4 h-4 rounded border-zinc-300 dark:border-white/10 text-emerald-600 focus:ring-emerald-500/30">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-semibold text-zinc-900 dark:text-white">Hide default password & credentials on login page</span>
                                <span class="text-2xs font-mono font-bold px-2 py-0.5 rounded uppercase border bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/20">
                                    Recommended for self-hosted
                                </span>
                            </div>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
                                When enabled, the demo email/password badge, auto-fill button, and default placeholder will be hidden from the sign-in screen (<code class="font-mono text-zinc-700 dark:text-zinc-300">/login</code>) to prevent unauthorized guests from seeing default access hints.
                            </p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Submit Button Row -->
            <div class="flex items-center justify-end gap-3 pt-1">
                <button type="submit" class="btn-primary px-6 py-2.5 text-white font-semibold text-sm rounded-xl transition-all shadow-md cursor-pointer flex items-center gap-2">
                    <i data-lucide="check" class="w-4 h-4"></i>
                    <span>Save Password & Preferences</span>
                </button>
            </div>
        </form>

        <!-- Card 3: Two-Factor Authentication (2FA) -->
        <div class="bg-white dark:bg-[#121622] border border-zinc-200/90 dark:border-white/[0.08] rounded-2xl p-6 sm:p-7 shadow-sm space-y-6">
            <div class="flex items-center justify-between pb-4 border-b border-zinc-100 dark:border-white/[0.06] flex-wrap gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400 flex-shrink-0">
                        <i data-lucide="shield-check" class="w-4.5 h-4.5"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">Two-Factor Authentication (2FA)</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Protect against brute-force attacks by requiring an authenticator app code on login.</p>
                    </div>
                </div>

                @if($user->hasEnabledTwoFactorAuthentication())
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                        <span>Active & Enforced</span>
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-zinc-800 text-zinc-400 border border-white/5">
                        <span class="w-1.5 h-1.5 rounded-full bg-zinc-500"></span>
                        <span>Disabled</span>
                    </span>
                @endif
            </div>

            @if($user->hasEnabledTwoFactorAuthentication())
                <!-- 2FA is Active State -->
                <div class="space-y-5">
                    <div class="p-4 rounded-xl bg-emerald-500/[0.06] dark:bg-emerald-500/10 border border-emerald-500/20 text-xs text-emerald-300 flex items-start gap-3">
                        <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-400 shrink-0 mt-0.5"></i>
                        <div class="space-y-1">
                            <div class="font-semibold text-emerald-200">Two-factor authentication is currently active</div>
                            <div class="text-emerald-300/80 leading-relaxed">
                                Your account is guarded with TOTP authentication. Each login attempt requires your password and a dynamic 6-digit verification code.
                            </div>
                        </div>
                    </div>

                    <!-- Recovery Codes Section -->
                    @if(session('recovery_codes_generated') || !empty($user->two_factor_recovery_codes))
                        <div class="p-4 rounded-xl bg-zinc-50 dark:bg-[#09090b] border border-zinc-200/90 dark:border-white/10 space-y-3">
                            <div class="flex items-center justify-between">
                                <div class="text-xs font-semibold uppercase tracking-wider text-zinc-400 flex items-center gap-1.5">
                                    <i data-lucide="life-buoy" class="w-3.5 h-3.5 text-zinc-400"></i>
                                    <span>Emergency Recovery Codes</span>
                                </div>
                                <span class="text-2xs font-mono text-zinc-500">
                                    {{ count($user->two_factor_recovery_codes ?? []) }} codes remaining
                                </span>
                            </div>

                            <p class="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
                                Store these recovery codes in a secure password manager. Each code may only be used once if you lose your phone or authenticator app.
                            </p>

                            @if(session('recovery_codes_generated'))
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 font-mono text-xs pt-1">
                                    @foreach(session('recovery_codes_generated') as $rCode)
                                        <div class="bg-[#121215] border border-white/10 rounded-lg p-2 text-center text-emerald-300 font-semibold select-all">
                                            {{ $rCode }}
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <div class="pt-2 flex items-center gap-3">
                                <button type="button" @click="regenModalOpen = true"
                                    class="text-xs font-semibold text-zinc-400 hover:text-white transition-colors cursor-pointer flex items-center gap-1.5">
                                    <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                                    <span>Regenerate Recovery Codes</span>
                                </button>
                            </div>
                        </div>
                    @endif

                    <!-- Disable 2FA Action Button -->
                    <div class="pt-2 flex items-center justify-between border-t border-zinc-100 dark:border-white/[0.06]">
                        <span class="text-xs text-zinc-500">Disable 2FA if you want to turn off extra verification.</span>
                        <button type="button" @click="disableModalOpen = true"
                            class="px-4 py-2 text-xs font-semibold bg-rose-600/10 hover:bg-rose-600/20 text-rose-400 border border-rose-500/20 rounded-xl transition-all cursor-pointer flex items-center gap-1.5">
                            <i data-lucide="shield-off" class="w-3.5 h-3.5"></i>
                            <span>Disable 2FA</span>
                        </button>
                    </div>
                </div>

            @elseif(session('two_factor_setup_started') || (!empty($user->two_factor_secret) && is_null($user->two_factor_confirmed_at)))
                <!-- 2FA Setup Flow (QR Code & Verification Code Input) -->
                <div class="space-y-6">
                    <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-200 flex items-start gap-3">
                        <i data-lucide="smartphone" class="w-4 h-4 text-amber-400 shrink-0 mt-0.5"></i>
                        <div class="space-y-1">
                            <div class="font-semibold text-amber-300">Complete 2FA Setup</div>
                            <div class="text-amber-200/80 leading-relaxed">
                                Scan the QR code with your authenticator app (e.g. Google Authenticator, Authy, Apple Passwords) or manually enter the key, then provide the 6-digit code below to activate.
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 items-center">
                        <!-- QR Code Canvas -->
                        <div class="bg-white p-4 rounded-2xl flex flex-col items-center justify-center shadow-lg border border-white/10 mx-auto">
                            <canvas id="twoFactorQrCanvas"></canvas>
                            <span class="text-[10px] text-zinc-800 font-mono mt-1 font-semibold">Scan with Authenticator</span>
                        </div>

                        <!-- Manual Secret & Input -->
                        <div class="space-y-4">
                            <div>
                                <label class="block text-2xs uppercase tracking-wider font-semibold text-zinc-400 mb-1">Manual Setup Key</label>
                                <div class="flex items-center gap-2 bg-zinc-50 dark:bg-[#09090b] border border-white/10 rounded-xl p-2.5">
                                    <span class="font-mono text-xs text-emerald-400 select-all truncate">{{ $user->two_factor_secret }}</span>
                                    <button type="button" @click="copyText('{{ $user->two_factor_secret }}', 'Secret key copied!')"
                                        class="p-1 text-zinc-400 hover:text-white cursor-pointer ml-auto shrink-0" title="Copy Key">
                                        <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                                    </button>
                                </div>
                            </div>

                            <form action="{{ route('dashboard.two-factor.confirm') }}" method="POST" class="space-y-3">
                                @csrf
                                <div>
                                    <label class="block text-2xs uppercase tracking-wider font-semibold text-zinc-400 mb-1">Verification Code</label>
                                    <input type="text" name="code" inputmode="numeric" placeholder="123456" maxlength="6" autofocus required
                                        class="w-full bg-zinc-50 dark:bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-center font-mono text-base tracking-[0.2em] text-white focus:outline-none focus:border-emerald-500">
                                    @error('two_factor_code')
                                        <p class="text-xs text-rose-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                <button type="submit"
                                    class="btn-primary w-full py-2.5 text-xs font-semibold text-white rounded-xl transition-all shadow-md flex items-center justify-center gap-2 cursor-pointer">
                                    <i data-lucide="check" class="w-3.5 h-3.5"></i>
                                    <span>Activate & Confirm 2FA</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Load QR Code Generator -->
                <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const canvas = document.getElementById('twoFactorQrCanvas');
                        if (canvas && window.QRCode) {
                            QRCode.toCanvas(canvas, @json($twoFactorQrUri), {
                                width: 160,
                                margin: 1,
                                color: { dark: '#09090b', light: '#ffffff' }
                            });
                        }
                    });
                </script>

            @else
                <!-- 2FA is Disabled State -->
                <div class="space-y-4">
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
                        When two-factor authentication is enabled, you will be prompted for a secure, random one-time token from your mobile device during authentication. This stops automated brute-force attacks even if your admin password is compromised.
                    </p>

                    <form action="{{ route('dashboard.two-factor.enable') }}" method="POST">
                        @csrf
                        <button type="submit"
                            class="btn-primary px-5 py-2.5 text-xs font-semibold text-white rounded-xl transition-all shadow-md flex items-center gap-2 cursor-pointer">
                            <i data-lucide="shield-plus" class="w-4 h-4"></i>
                            <span>Enable Two-Factor Authentication</span>
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <!-- Modal: Disable 2FA Password Confirmation -->
    <div x-show="disableModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80" x-cloak>
        <div class="bg-[#121215] border border-white/10 rounded-2xl w-full max-w-md p-6 space-y-4 shadow-2xl" @click.away="disableModalOpen = false">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-rose-500/10 text-rose-400 flex items-center justify-center">
                    <i data-lucide="shield-alert" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white">Disable Two-Factor Auth</h3>
                    <p class="text-xs text-zinc-400">Confirm your current password to proceed.</p>
                </div>
            </div>

            <form action="{{ route('dashboard.two-factor.disable') }}" method="POST" class="space-y-4">
                @csrf
                @method('DELETE')

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Current Password</label>
                    <input type="password" name="current_password" required placeholder="Enter your current password"
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-rose-500">
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <button type="button" @click="disableModalOpen = false" class="px-4 py-2 text-xs font-semibold text-zinc-400 hover:text-white rounded-xl">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-semibold bg-rose-600 hover:bg-rose-500 text-white rounded-xl shadow-md">Disable 2FA</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Regenerate Recovery Codes Password Confirmation -->
    <div x-show="regenModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80" x-cloak>
        <div class="bg-[#121215] border border-white/10 rounded-2xl w-full max-w-md p-6 space-y-4 shadow-2xl" @click.away="regenModalOpen = false">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                    <i data-lucide="refresh-cw" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white">Regenerate Recovery Codes</h3>
                    <p class="text-xs text-zinc-400">Confirm your password to replace existing recovery codes.</p>
                </div>
            </div>

            <form action="{{ route('dashboard.two-factor.recovery-codes') }}" method="POST" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Current Password</label>
                    <input type="password" name="current_password" required placeholder="Enter your current password"
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-amber-500">
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <button type="button" @click="regenModalOpen = false" class="px-4 py-2 text-xs font-semibold text-zinc-400 hover:text-white rounded-xl">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-semibold bg-amber-600 hover:bg-amber-500 text-white rounded-xl shadow-md">Regenerate Codes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
