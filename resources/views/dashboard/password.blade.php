@extends('layouts.dashboard')

@section('title', 'Change Password & Security')

@section('content')
<div class="flex justify-center w-full py-4 sm:py-6">
    <div class="w-full max-w-2xl space-y-6">

        <!-- Form 1: Password Update -->
        <form action="{{ route('dashboard.password.update') }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Card 1: Change Password -->
            <div class="bg-white dark:bg-[#121622] border border-zinc-200/90 dark:border-white/[0.08] rounded-2xl p-6 sm:p-7 shadow-sm space-y-6">
                <div class="flex items-center gap-3 pb-4 border-b border-zinc-100 dark:border-white/[0.06]">
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400 flex-shrink-0">
                        <i data-lucide="lock" class="w-4.5 h-4.5"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">Change Password</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Ensure your account uses a strong, unique password.</p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 mb-1.5">Current Password</label>
                        <input type="password" name="current_password" required placeholder="Enter your current password" autocomplete="current-password"
                            class="w-full bg-zinc-50 dark:bg-[#09090b] border border-zinc-200/90 dark:border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500 transition-colors">
                        @error('current_password')
                            <p class="text-xs text-red-500 dark:text-red-400 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 mb-1.5">New Password</label>
                            <input type="password" name="password" required placeholder="Minimum 8 characters" autocomplete="new-password"
                                class="w-full bg-zinc-50 dark:bg-[#09090b] border border-zinc-200/90 dark:border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500 transition-colors">
                            @error('password')
                                <p class="text-xs text-red-500 dark:text-red-400 mt-1.5">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 mb-1.5">Confirm New Password</label>
                            <input type="password" name="password_confirmation" required placeholder="Confirm new password" autocomplete="new-password"
                                class="w-full bg-zinc-50 dark:bg-[#09090b] border border-zinc-200/90 dark:border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500 transition-colors">
                        </div>
                    </div>
                </div>

                <!-- Submit Button Row -->
                <div class="flex items-center justify-end gap-3 pt-3 border-t border-zinc-100 dark:border-white/[0.06]">
                    <button type="submit" class="btn-primary px-6 py-2.5 text-white font-semibold text-sm rounded-xl transition-all shadow-md cursor-pointer flex items-center gap-2">
                        <i data-lucide="check" class="w-4 h-4"></i>
                        <span>Update Password</span>
                    </button>
                </div>
            </div>
        </form>

        </div>
    </div>
</div>
@endsection
