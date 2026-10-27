@extends('layouts.dashboard')

@section('title', 'Edit Profile')

@section('content')
<div class="flex justify-center w-full py-4 sm:py-6">
    <div class="w-full max-w-2xl">
        <!-- Edit Profile Form -->
        <form action="{{ route('dashboard.profile.update') }}" method="POST">
            @csrf
            @method('PUT')

            <!-- Card: Account Information -->
            <div class="bg-white dark:bg-[#121622] border border-zinc-200/90 dark:border-white/[0.08] rounded-2xl p-6 sm:p-7 shadow-sm space-y-6">
                <div class="flex items-center gap-3 pb-4 border-b border-zinc-100 dark:border-white/[0.06]">
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400 flex-shrink-0">
                        <i data-lucide="user" class="w-4.5 h-4.5"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">Account Information</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Update your display name and login email address.</p>
                    </div>
                </div>

                <div class="space-y-4">
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

                <!-- Submit Button Row -->
                <div class="pt-3 border-t border-zinc-100 dark:border-white/[0.06] flex items-center justify-end">
                    <button type="submit" class="btn-primary px-6 py-2.5 text-white font-semibold text-sm rounded-xl transition-all shadow-md cursor-pointer flex items-center gap-2">
                        <i data-lucide="check" class="w-4 h-4"></i>
                        <span>Save Changes</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
