<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwire - Two-Factor Authentication</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">

    <!-- Theme Initializer -->
    <script>
        (function() {
            try {
                const savedTheme = localStorage.getItem('omni_theme');
                if (savedTheme === 'light' || (!savedTheme && window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches)) {
                    document.documentElement.classList.remove('dark');
                } else {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {
                console.error(e);
            }
        })();
    </script>

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        zinc: {
                            850: '#1f1f23',
                            925: '#121215',
                            950: '#09090b',
                        }
                    }
                }
            }
        }
    </script>
    <!-- Alpine.js CDN -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- Google Fonts Inter & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; font-size: 0.875rem; line-height: 1.5; }
        code, pre { font-family: 'JetBrains Mono', monospace; }
        [x-cloak] { display: none !important; }

        html:not(.dark) body {
            background-color: #f8fafc !important;
            color: #0f172a !important;
        }
        html:not(.dark) .bg-\[\#121215\] {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05) !important;
        }
        html:not(.dark) h1, html:not(.dark) .text-white {
            color: #0f172a !important;
        }
        html:not(.dark) .text-zinc-100, html:not(.dark) .text-zinc-200 {
            color: #1e293b !important;
        }
        html:not(.dark) .text-zinc-400 {
            color: #64748b !important;
        }
        html:not(.dark) input {
            background-color: #ffffff !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
        }
    </style>
</head>
<body class="bg-[#09090b] text-zinc-100 min-h-screen flex items-center justify-center p-4 relative overflow-hidden antialiased selection:bg-emerald-500 selection:text-white"
    x-data="{
        usingRecoveryCode: false,
        currentTheme: localStorage.getItem('omni_theme') || (document.documentElement.classList.contains('dark') ? 'dark' : 'light'),
        toggleTheme() {
            if (this.currentTheme === 'dark') {
                this.currentTheme = 'light';
                document.documentElement.classList.remove('dark');
                localStorage.setItem('omni_theme', 'light');
            } else {
                this.currentTheme = 'dark';
                document.documentElement.classList.add('dark');
                localStorage.setItem('omni_theme', 'dark');
            }
            this.$nextTick(() => lucide.createIcons());
        }
    }" x-init="$nextTick(() => lucide.createIcons())">

    <!-- Theme Switcher Top-Right Button -->
    <div class="absolute top-5 right-5 z-20">
        <button type="button" @click="toggleTheme()"
            class="p-2.5 rounded-xl border border-white/10 dark:border-white/10 bg-white/80 dark:bg-zinc-900/80 hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-600 dark:text-zinc-300 transition-colors shadow-sm flex items-center justify-center cursor-pointer"
            :title="currentTheme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode'">
            <span x-show="currentTheme === 'dark'" class="flex items-center">
                <i data-lucide="sun" class="w-4 h-4 text-amber-400"></i>
            </span>
            <span x-show="currentTheme !== 'dark'" class="flex items-center" x-cloak>
                <i data-lucide="moon" class="w-4 h-4 text-indigo-600"></i>
            </span>
        </button>
    </div>

    <!-- Subtle Background Ambient Mesh -->
    <div class="absolute -top-48 -left-48 w-96 h-96 bg-emerald-500/10 rounded-full blur-[128px] pointer-events-none"></div>
    <div class="absolute -bottom-48 -right-48 w-96 h-96 bg-violet-600/10 rounded-full blur-[128px] pointer-events-none"></div>
    <div class="absolute inset-0 bg-[radial-gradient(#ffffff05_1px,transparent_1px)] [background-size:24px_24px] pointer-events-none"></div>

    <div class="w-full max-w-md relative z-10">

        <!-- Brand Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center relative group">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-600 p-[1.5px] shadow-2xl shadow-emerald-500/30 flex items-center justify-center relative overflow-hidden group-hover:scale-105 transition-transform duration-300">
                    <div class="w-full h-full bg-[#0d121c] rounded-[14px] flex items-center justify-center relative">
                        <div class="absolute inset-0 bg-emerald-500/10 rounded-[14px] blur-sm"></div>
                        <i data-lucide="shield-check" class="w-8 h-8 text-emerald-400 relative z-10"></i>
                    </div>
                </div>
            </div>
            <h1 class="text-2xl font-extrabold tracking-tight text-white mt-4 flex items-center justify-center gap-1.5">
                <span>Two-Factor Authentication</span>
            </h1>
            <p class="text-xs text-zinc-400 mt-1.5 font-medium" x-show="!usingRecoveryCode">
                Enter the 6-digit verification code generated by your authenticator app.
            </p>
            <p class="text-xs text-zinc-400 mt-1.5 font-medium" x-show="usingRecoveryCode" x-cloak>
                Enter one of your emergency recovery codes to regain access to your account.
            </p>
        </div>

        <!-- 2FA Verification Card -->
        <div class="bg-[#121215] border border-white/[0.08] shadow-2xl shadow-black/80 rounded-2xl p-6 sm:p-8 backdrop-blur-xl relative">
            <!-- Errors Alert -->
            @if (isset($errors) && $errors->any())
                <div class="mb-5 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs sm:text-sm flex items-start gap-2.5">
                    <i data-lucide="alert-circle" class="w-4.5 h-4.5 text-red-400 flex-shrink-0 mt-0.5"></i>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ url('/login/two-factor') }}" class="space-y-4">
                @csrf

                <!-- 6-digit TOTP Code Input -->
                <div x-show="!usingRecoveryCode">
                    <label for="code" class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Authentication Code</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-zinc-500">
                            <i data-lucide="key" class="w-4 h-4"></i>
                        </div>
                        <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus
                            placeholder="123456" maxlength="6"
                            class="w-full bg-[#09090b] border border-white/10 rounded-xl pl-10 pr-4 py-3 text-lg font-mono text-center tracking-[0.3em] text-zinc-100 placeholder-zinc-600 focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 transition-colors">
                    </div>
                </div>

                <!-- Recovery Code Input -->
                <div x-show="usingRecoveryCode" x-cloak>
                    <label for="recovery_code" class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Emergency Recovery Code</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-zinc-500">
                            <i data-lucide="life-buoy" class="w-4 h-4"></i>
                        </div>
                        <input type="text" id="recovery_code" name="recovery_code" autocomplete="off"
                            placeholder="abcde-12345"
                            class="w-full bg-[#09090b] border border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-sm font-mono text-zinc-100 placeholder-zinc-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 transition-colors">
                    </div>
                </div>

                <button type="submit"
                    style="background-color: #10b981 !important; color: #ffffff !important;"
                    class="btn-primary w-full py-2.5 px-4 text-white font-semibold rounded-xl text-sm tracking-wide shadow-md transition-all duration-150 transform active:scale-[0.99] flex items-center justify-center gap-2 mt-2 cursor-pointer">
                    <span style="color: #ffffff !important;">Verify & Continue</span>
                    <i data-lucide="arrow-right" class="w-4 h-4" style="color: #ffffff !important;"></i>
                </button>
            </form>

            <!-- Toggle Between Authenticator Code and Recovery Code -->
            <div class="mt-5 pt-4 border-t border-white/[0.08] flex items-center justify-between text-xs text-zinc-400">
                <button type="button" @click="usingRecoveryCode = !usingRecoveryCode; $nextTick(() => { (usingRecoveryCode ? document.getElementById('recovery_code') : document.getElementById('code')).focus(); })"
                    class="text-emerald-400 hover:text-emerald-300 font-medium transition-colors cursor-pointer flex items-center gap-1.5">
                    <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                    <span x-text="usingRecoveryCode ? 'Use an authenticator code instead' : 'Use a recovery code instead'"></span>
                </button>

                <a href="{{ route('login') }}" class="text-zinc-500 hover:text-zinc-300 transition-colors">
                    Cancel
                </a>
            </div>
        </div>
    </div>
</body>
</html>

