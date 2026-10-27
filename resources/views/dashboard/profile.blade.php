@extends('layouts.dashboard')

@section('title', 'Edit Profile')

@section('content')
<div class="max-w-4xl space-y-6">
    <!-- Top Hero Banner -->
    <div class="bg-white dark:bg-[#121622] border border-zinc-200/90 dark:border-white/[0.08] rounded-2xl p-6 shadow-sm flex items-center justify-between gap-5 flex-wrap">
        <div class="flex items-center gap-4 min-w-0">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-emerald-500/20 to-teal-500/20 text-emerald-500 dark:text-emerald-400 border border-emerald-500/30 flex items-center justify-center font-bold text-2xl shadow-sm flex-shrink-0">
                {{ strtoupper(substr($user->name, 0, 2)) }}
            </div>
            <div class="min-w-0">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <h2 class="text-2xl font-black text-zinc-900 dark:text-white tracking-tight">{{ $user->name }}</h2>
                    <span class="text-xs font-mono font-bold px-2.5 py-1 rounded-lg uppercase border bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20">
                        Passwire Admin
                    </span>
                </div>
                <div class="flex items-center gap-3 mt-1.5 text-xs text-zinc-500 dark:text-zinc-400 flex-wrap">
                    <span class="font-mono">{{ $user->email }}</span>
                    <span>&bull;</span>
                    <span>Member since {{ $user->created_at?->format('M Y') ?? 'Recently' }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Profile Form -->
    <form action="{{ route('dashboard.profile.update') }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

        <!-- Card 1: Account Information -->
        <div class="bg-white dark:bg-[#121622] border border-zinc-200/90 dark:border-white/[0.08] rounded-2xl p-6 shadow-sm space-y-5">
            <div class="flex items-center gap-2.5 pb-3.5 border-b border-zinc-100 dark:border-white/[0.06]">
                <div class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400 flex-shrink-0">
                    <i data-lucide="user" class="w-4 h-4"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">Account Information</h3>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Update your display name and login email address.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 mb-1.5">Admin Name</label>
                    <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                        class="w-full bg-zinc-50 dark:bg-[#09090b] border border-zinc-200/90 dark:border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500 transition-colors">
                    @error('name')
                        <p class="text-xs text-red-500 dark:text-red-400 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 mb-1.5">Email Address</label>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                        class="w-full bg-zinc-50 dark:bg-[#09090b] border border-zinc-200/90 dark:border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500 transition-colors">
                    @error('email')
                        <p class="text-xs text-red-500 dark:text-red-400 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <!-- Card 2: Security & Password -->
        <div class="bg-white dark:bg-[#121622] border border-zinc-200/90 dark:border-white/[0.08] rounded-2xl p-6 shadow-sm space-y-5">
            <div class="flex items-center justify-between pb-3.5 border-b border-zinc-100 dark:border-white/[0.06] flex-wrap gap-2">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400 flex-shrink-0">
                        <i data-lucide="lock" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">Security & Password</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Ensure your account uses a strong, secure password.</p>
                    </div>
                </div>
                <span class="text-xs text-zinc-400 dark:text-zinc-500 font-mono">Leave blank to keep existing password</span>
            </div>

            <div class="space-y-4 max-w-xl">
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

        <!-- Submit Button Row -->
        <div class="flex items-center justify-end gap-3 pt-2">
            <button type="submit" class="btn-primary px-6 py-2.5 text-white font-semibold text-sm rounded-xl transition-all shadow-md cursor-pointer flex items-center gap-2">
                <i data-lucide="check" class="w-4 h-4"></i>
                <span>Save Changes</span>
            </button>
        </div>
    </form>
</div>
@endsection
