@extends('layouts.dashboard')

@section('title', 'Change Password')

@section('content')
<div class="flex justify-center w-full py-4 sm:py-6">
    <div class="w-full max-w-2xl">
        <!-- Password & Security Form -->
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
                    <span>Save Changes</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
