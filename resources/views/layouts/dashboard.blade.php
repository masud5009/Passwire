<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php($brandTitle = $globalSiteSettings->site_title ?? 'Passwire')
    @php($brandVersion = $globalSiteSettings->version ?? 'v1.0')
    @php($brandFavicon = isset($globalSiteSettings) ? $globalSiteSettings->faviconUrl() : null)
    <title>{{ $brandTitle }} - @yield('title', 'AI Gateway Console')</title>
    <link rel="icon" type="image/x-icon" href="{{ $brandFavicon ?? asset('favicon.ico') }}">

    <!-- Theme Initializer (Prevents Theme Flash / FOUC) -->
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
                console.error('Theme detection error:', e);
            }
        })();
    </script>
    <style>
        /* Brand logo visibility follows the active theme (default: dark) */
        html:not(.dark) .brand-logo-dark { display: none !important; }
        html:not(.dark) .brand-logo-light { display: block !important; }
        html.dark .brand-logo-dark { display: block !important; }
        html.dark .brand-logo-light { display: none !important; }
    </style>

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        zinc: {
                            850: '#1a1a1e',
                            900: '#121215',
                            925: '#0e0e11',
                            950: '#09090b',
                        }
                    },
                    fontSize: {
                        '2xs': ['0.6875rem', { lineHeight: '1rem' }],       /* 11px */
                        'xs': ['0.75rem', { lineHeight: '1.125rem' }],       /* 12px - exactly: if 10 was before, now 12px! */
                        'sm': ['0.8125rem', { lineHeight: '1.25rem' }],     /* 13px */
                        'base': ['0.875rem', { lineHeight: '1.375rem' }],   /* 14px */
                        'md': ['0.9375rem', { lineHeight: '1.45rem' }],     /* 15px */
                        'lg': ['1.0625rem', { lineHeight: '1.55rem' }],     /* 17px */
                        'xl': ['1.25rem', { lineHeight: '1.75rem' }],       /* 20px */
                        '2xl': ['1.5rem', { lineHeight: '1.875rem' }],      /* 24px */
                        '3xl': ['1.875rem', { lineHeight: '2.25rem' }],     /* 30px */
                        '4xl': ['2.25rem', { lineHeight: '2.5rem' }],       /* 36px */
                    }
                }
            }
        }
    </script>
    <!-- Alpine.js CDN -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- ApexCharts CDN -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <!-- Passwire Admin JS -->
    <script src="{{ asset('assets/admin/js/main.js') }}?v={{ file_exists(public_path('assets/admin/js/main.js')) ? filemtime(public_path('assets/admin/js/main.js')) : time() }}"></script>
    <!-- Google Fonts Inter & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Passwire Admin CSS -->
    <link rel="stylesheet" href="{{ asset('assets/admin/css/main.css') }}?v={{ file_exists(public_path('assets/admin/css/main.css')) ? filemtime(public_path('assets/admin/css/main.css')) : time() }}">
    @stack('styles')
</head>
<body class="bg-[#09090b] text-zinc-100 antialiased selection:bg-emerald-500 selection:text-white"
    x-data="dashboardApp({{ $modalProviders->first()?->id ?? 1 }})"
    x-init="lucide.createIcons()">

    <!-- Copy Toast Floating Notification -->
    <div x-show="copyToast" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-2"
        class="fixed bottom-6 right-6 z-50 bg-emerald-500 text-zinc-950 font-semibold px-4 py-2.5 rounded-xl shadow-2xl flex items-center gap-2 text-xs" x-cloak>
        <i data-lucide="check" class="w-4 h-4 text-zinc-950"></i>
        <span x-text="copyToastText"></span>
    </div>

    <!-- App Shell Layout: Sidebar + Main Content -->
    <div class="flex h-screen overflow-hidden">

        <!-- ============================================================== -->
        <!-- FIXED LEFT SIDEBAR (w-64)                                      -->
        <!-- ============================================================== -->
        <aside class="w-64 h-screen bg-[#10141e] border-r border-white/[0.07] flex flex-col justify-between flex-shrink-0 z-30 select-none">
            <!-- Sidebar Top: Brand -->
            <div>
                @php($brandLogoDark = isset($globalSiteSettings) ? $globalSiteSettings->logoDarkUrl() : null)
                @php($brandLogoLight = isset($globalSiteSettings) ? $globalSiteSettings->logoLightUrl() : null)
                @php($brandLogo = $brandLogoDark ?? $brandLogoLight ?? (isset($globalSiteSettings) ? $globalSiteSettings->logoUrl() : null))
                <a href="{{ route('dashboard.overview') }}" class="px-4 py-3 border-b border-white/[0.07] flex items-center justify-center hover:bg-white/[0.02] transition-colors">
                    @if($brandLogoDark || $brandLogoLight)
                        @if($brandLogoDark)
                            <img src="{{ $brandLogoDark }}" alt="{{ $brandTitle ?? 'Passwire' }} logo" class="brand-logo-dark w-full max-w-[190px] h-11 object-contain object-center">
                        @endif
                        @if($brandLogoLight)
                            <img src="{{ $brandLogoLight }}" alt="{{ $brandTitle ?? 'Passwire' }} logo" class="brand-logo-light w-full max-w-[190px] h-11 object-contain object-center">
                        @endif
                    @elseif($brandLogo)
                        <img src="{{ $brandLogo }}" alt="{{ $brandTitle ?? 'Passwire' }} logo" class="w-full max-w-[190px] h-11 object-contain object-center">
                    @else
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-tr from-zinc-800 to-zinc-900 border border-white/10 flex items-center justify-center shadow-lg">
                            <i data-lucide="zap" class="w-4 h-4 text-emerald-400"></i>
                        </div>
                    @endif
                </a>

                <!-- Navigation Links with Dedicated Routes -->
                <nav class="p-3 space-y-1">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 px-3 pt-2 pb-1">Navigation</div>

                    <!-- 1. Overview -->
                    <a href="{{ route('dashboard.overview') }}"
                        @class([
                            'w-full py-2 px-3 rounded-xl text-xs sm:text-sm flex items-center justify-between transition-all group',
                            'bg-white/[0.08] text-white font-semibold border-l-2 border-emerald-400 pl-2.5' => request()->routeIs('dashboard.index') || request()->routeIs('dashboard.overview') || request()->routeIs('dashboard'),
                            'text-zinc-400 hover:text-zinc-200 hover:bg-white/[0.03]' => !(request()->routeIs('dashboard.index') || request()->routeIs('dashboard.overview') || request()->routeIs('dashboard')),
                        ])>
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="layout-dashboard" class="w-4 h-4 transition-colors {{ (request()->routeIs('dashboard.index') || request()->routeIs('dashboard.overview') || request()->routeIs('dashboard')) ? 'text-emerald-400' : 'text-zinc-500 group-hover:text-zinc-300' }}"></i>
                            <span>Overview & Analytics</span>
                        </div>
                    </a>

                    <!-- 2. AI Providers -->
                    <a href="{{ route('dashboard.providers') }}"
                        @class([
                            'w-full py-2 px-3 rounded-xl text-xs sm:text-sm flex items-center justify-between transition-all group',
                            'bg-white/[0.08] text-white font-semibold border-l-2 border-emerald-400 pl-2.5' => request()->routeIs('dashboard.providers'),
                            'text-zinc-400 hover:text-zinc-200 hover:bg-white/[0.03]' => !request()->routeIs('dashboard.providers'),
                        ])>
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="cpu" class="w-4 h-4 transition-colors {{ request()->routeIs('dashboard.providers') ? 'text-emerald-400' : 'text-zinc-500 group-hover:text-zinc-300' }}"></i>
                            <span>AI Providers</span>
                        </div>
                        <span class="text-[11px] px-1.5 py-0.5 rounded font-mono {{ request()->routeIs('dashboard.providers') ? 'bg-emerald-500/20 text-emerald-300 font-semibold' : 'bg-zinc-800 text-zinc-400' }}">
                            {{ $sidebarProvidersCount }}
                        </span>
                    </a>

                    <!-- 3. Smart Combos -->
                    <a href="{{ route('dashboard.combos') }}"
                        @class([
                            'w-full py-2 px-3 rounded-xl text-xs sm:text-sm flex items-center justify-between transition-all group',
                            'bg-white/[0.08] text-white font-semibold border-l-2 border-emerald-400 pl-2.5' => request()->routeIs('dashboard.combos'),
                            'text-zinc-400 hover:text-zinc-200 hover:bg-white/[0.03]' => !request()->routeIs('dashboard.combos'),
                        ])>
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="git-branch" class="w-4 h-4 transition-colors {{ request()->routeIs('dashboard.combos') ? 'text-emerald-400' : 'text-zinc-500 group-hover:text-zinc-300' }}"></i>
                            <span>Smart Combos</span>
                        </div>
                        <span class="text-[11px] px-1.5 py-0.5 rounded font-mono bg-zinc-800 text-zinc-400">{{ $sidebarCombosCount }}</span>
                    </a>

                    <!-- 4. Gateway Client Keys -->
                    <a href="{{ route('dashboard.gateway-keys') }}"
                        @class([
                            'w-full py-2 px-3 rounded-xl text-xs sm:text-sm flex items-center justify-between transition-all group',
                            'bg-white/[0.08] text-white font-semibold border-l-2 border-emerald-400 pl-2.5' => request()->routeIs('dashboard.gateway-keys'),
                            'text-zinc-400 hover:text-zinc-200 hover:bg-white/[0.03]' => !request()->routeIs('dashboard.gateway-keys'),
                        ])>
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="key-round" class="w-4 h-4 transition-colors {{ request()->routeIs('dashboard.gateway-keys') ? 'text-emerald-400' : 'text-zinc-500 group-hover:text-zinc-300' }}"></i>
                            <span>Gateway Keys</span>
                        </div>
                        <span class="text-[11px] px-1.5 py-0.5 rounded font-mono bg-zinc-800 text-zinc-400">{{ $sidebarKeysCount }}</span>
                    </a>

                    <div class="text-[11px] font-bold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 px-3 pt-3 pb-1">Studio & Inspector</div>

                    <!-- 5. Live Playground -->
                    <a href="{{ route('dashboard.playground') }}"
                        @class([
                            'w-full py-2 px-3 rounded-xl text-xs sm:text-sm flex items-center justify-between transition-all group',
                            'bg-white/[0.08] text-white font-semibold border-l-2 border-emerald-400 pl-2.5' => request()->routeIs('dashboard.playground'),
                            'text-zinc-400 hover:text-zinc-200 hover:bg-white/[0.03]' => !request()->routeIs('dashboard.playground'),
                        ])>
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="terminal" class="w-4 h-4 transition-colors {{ request()->routeIs('dashboard.playground') ? 'text-emerald-400' : 'text-zinc-500 group-hover:text-zinc-300' }}"></i>
                            <span>Live Playground</span>
                        </div>
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    </a>

                    <!-- 6. Telemetry Logs -->
                    <a href="{{ route('dashboard.logs') }}"
                        @class([
                            'w-full py-2 px-3 rounded-xl text-xs sm:text-sm flex items-center justify-between transition-all group',
                            'bg-white/[0.08] text-white font-semibold border-l-2 border-emerald-400 pl-2.5' => request()->routeIs('dashboard.logs'),
                            'text-zinc-400 hover:text-zinc-200 hover:bg-white/[0.03]' => !request()->routeIs('dashboard.logs'),
                        ])>
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="activity" class="w-4 h-4 transition-colors {{ request()->routeIs('dashboard.logs') ? 'text-emerald-400' : 'text-zinc-500 group-hover:text-zinc-300' }}"></i>
                            <span>Telemetry Logs</span>
                        </div>
                        <span class="text-[11px] px-1.5 py-0.5 rounded font-mono bg-zinc-800 text-zinc-400">{{ $sidebarLogsCount }}</span>
                    </a>

                    <!-- 7. Alerts & Webhooks -->
                    <a href="{{ route('dashboard.alerts') }}"
                        @class([
                            'w-full py-2 px-3 rounded-xl text-xs sm:text-sm flex items-center justify-between transition-all group',
                            'bg-white/[0.08] text-white font-semibold border-l-2 border-emerald-400 pl-2.5' => request()->routeIs('dashboard.alerts', 'dashboard.settings.alerts.*'),
                            'text-zinc-400 hover:text-zinc-200 hover:bg-white/[0.03]' => !request()->routeIs('dashboard.alerts', 'dashboard.settings.alerts.*'),
                        ])>
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="bell-ring" class="w-4 h-4 transition-colors {{ request()->routeIs('dashboard.alerts', 'dashboard.settings.alerts.*') ? 'text-emerald-400' : 'text-zinc-500 group-hover:text-zinc-300' }}"></i>
                            <span>Alerts & Webhooks</span>
                        </div>
                    </a>

                    <!-- 8. Settings -->
                    <a href="{{ route('dashboard.settings') }}"
                        @class([
                            'w-full py-2 px-3 rounded-xl text-xs sm:text-sm flex items-center justify-between transition-all group',
                            'bg-white/[0.08] text-white font-semibold border-l-2 border-emerald-400 pl-2.5' => request()->routeIs('dashboard.settings', 'dashboard.settings.site.*'),
                            'text-zinc-400 hover:text-zinc-200 hover:bg-white/[0.03]' => !request()->routeIs('dashboard.settings', 'dashboard.settings.site.*'),
                        ])>
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="settings" class="w-4 h-4 transition-colors {{ request()->routeIs('dashboard.settings', 'dashboard.settings.site.*') ? 'text-emerald-400' : 'text-zinc-500 group-hover:text-zinc-300' }}"></i>
                            <span>Settings</span>
                        </div>
                    </a>
                </nav>
            </div>

            <!-- Sidebar Bottom: User Profile -->
            <div class="p-3.5 border-t border-white/[0.07] bg-[#0c101a]">
                <!-- Admin Profile Card -->
                <div class="flex items-center justify-between px-1.5 py-1">
                    <div class="flex items-center gap-2.5 truncate">
                        <div class="w-8 h-8 rounded-lg bg-zinc-800 border border-white/10 flex items-center justify-center font-bold text-sm text-zinc-300">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </div>
                        <div class="truncate">
                            <div class="text-sm font-semibold text-white truncate">{{ auth()->user()->name }}</div>
                            <div class="text-xs text-zinc-400 truncate">{{ auth()->user()->email }}</div>
                        </div>
                    </div>

                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="p-2 text-zinc-400 hover:text-red-400 hover:bg-red-500/10 rounded-xl transition-colors" title="Sign Out">
                            <i data-lucide="log-out" class="w-4.5 h-4.5"></i>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <!-- ============================================================== -->
        <!-- MAIN CONTENT AREA                                              -->
        <!-- ============================================================== -->
        <main class="flex-1 flex flex-col h-screen overflow-hidden bg-[#09090b]">
            <!-- Top Sticky Glass Header -->
            <header class="h-16 border-b border-white/[0.07] bg-[#09090b]/80 backdrop-blur-md px-6 flex items-center justify-between z-20 flex-shrink-0">
                <!-- Page Title -->
                <div class="flex items-center gap-3">
                    <h1 class="text-lg sm:text-xl font-bold text-white tracking-tight">@yield('title', 'Overview & Live Analytics')</h1>
                </div>

                <!-- Right Header Actions -->
                <div class="flex items-center gap-3">
                    <!-- Quick Theme Toggle Switcher -->
                    <button type="button" @click="toggleTheme()"
                        class="p-2 rounded-xl border border-white/10 bg-[#141926] hover:bg-[#1a2133] text-zinc-300 transition-all shadow-sm flex items-center justify-center cursor-pointer"
                        :title="currentTheme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode'"
                        aria-label="Toggle Theme">
                        <span x-show="currentTheme === 'dark'" class="flex items-center">
                            <i data-lucide="sun" class="w-4 h-4 text-amber-400"></i>
                        </span>
                        <span x-show="currentTheme !== 'dark'" class="flex items-center" x-cloak>
                            <i data-lucide="moon" class="w-4 h-4 text-indigo-600"></i>
                        </span>
                    </button>
                </div>
            </header>

            <!-- Scrollable Content Body -->
            <div class="flex-1 overflow-y-auto p-6 space-y-6 bg-[#090b10] bg-[radial-gradient(rgba(255,255,255,0.035)_1px,transparent_1px)] [background-size:24px_24px]">

                <!-- Global Success Alert -->
                @if(session('success'))
                    <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm flex items-center justify-between shadow-lg">
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-400 flex-shrink-0"></i>
                            <span>{{ session('success') }}</span>
                        </div>
                        <button type="button" @click="$el.parentElement.remove()" class="text-emerald-500 hover:text-emerald-300 text-base">&times;</button>
                    </div>
                @endif

                @if(isset($errors) && $errors->any())
                    <div class="p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm flex items-center justify-between shadow-lg">
                        <div class="flex items-center gap-2.5">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-red-400 flex-shrink-0"></i>
                            <span>{{ $errors->first() }}</span>
                        </div>
                        <button type="button" @click="$el.parentElement.remove()" class="text-red-500 hover:text-red-300 text-base">&times;</button>
                    </div>
                @endif

                <!-- Content Slot for Dedicated Route Views -->
                @yield('content')

            </div>
        </main>
    </div>

    <!-- ============================================================== -->
    <!-- SLIDE-OVER / MODAL: ADD PROVIDER API KEY                       -->
    <!-- ============================================================== -->
    <!-- ============================================================== -->
    <!-- SLIDE-OVER / MODAL: ADD PROVIDER API KEY                       -->
    <!-- ============================================================== -->
    <div x-show="showAccountModal"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 overflow-y-auto bg-black/75 flex items-center justify-center p-4" x-cloak>
        <div x-show="showAccountModal"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="bg-[#121215] border border-white/[0.08] rounded-2xl w-full max-w-md p-6 shadow-2xl relative" @click.away="showAccountModal = false">
            <div class="flex items-center justify-between pb-3.5 border-b border-white/[0.06] mb-4">
                <div class="flex items-center gap-2.5">
                    <i data-lucide="key" class="w-5 h-5 text-emerald-400"></i>
                    <h3 class="text-base font-bold text-white">Add Provider API Key</h3>
                </div>
                <button type="button" @click="showAccountModal = false" class="text-zinc-500 hover:text-white text-xl leading-none">&times;</button>
            </div>

            <form action="{{ route('dashboard.accounts.store') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Target AI Provider</label>
                    <select name="ai_provider_id" x-model="selectedProviderId" class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500">
                        @foreach($modalProviders as $provider)
                            <option value="{{ $provider->id }}">{{ $provider->name }} ({{ $provider->slug }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Key Label / Identifier</label>
                    <input type="text" name="label" placeholder="e.g. Free Tier Account 1" required
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Raw Provider Secret API Key</label>
                    <input type="password" name="api_key" placeholder="Will be encrypted in database with AES-256" required
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 font-mono">
                </div>

                <div class="grid grid-cols-2 gap-3.5">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Priority (1 = Highest)</label>
                        <input type="number" name="priority" value="1" min="1"
                            class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Daily Token Limit</label>
                        <input type="number" name="daily_token_limit" placeholder="Optional" min="1"
                            class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500">
                    </div>
                </div>

                <div class="pt-4 border-t border-white/[0.06] flex items-center justify-end gap-2.5">
                    <button type="button" @click="showAccountModal = false" class="px-4 py-2.5 text-sm font-semibold bg-rose-600 hover:bg-rose-500 text-white rounded-xl transition-all shadow-md cursor-pointer">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 text-white font-semibold text-sm rounded-xl transition-all shadow-md cursor-pointer">
                        Save Encrypted Key
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- MODAL: ADD CUSTOM AI PROVIDER                                  -->
    <!-- ============================================================== -->
    <div x-show="showProviderModal"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 overflow-y-auto bg-black/75 flex items-center justify-center p-4" x-cloak>
        <div x-show="showProviderModal"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="bg-[#121215] border border-white/[0.08] rounded-2xl w-full max-w-md p-6 shadow-2xl relative" @click.away="showProviderModal = false">
            <div class="flex items-center justify-between pb-3.5 border-b border-white/[0.06] mb-4">
                <div class="flex items-center gap-2.5">
                    <i data-lucide="cpu" class="w-5 h-5 text-emerald-400"></i>
                    <h3 class="text-base font-bold text-white">Add Custom AI Provider</h3>
                </div>
                <button type="button" @click="showProviderModal = false" class="text-zinc-500 hover:text-white text-xl leading-none">&times;</button>
            </div>

            <form action="{{ route('dashboard.providers.store') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Provider Name</label>
                    <input type="text" name="name" placeholder="e.g. Together AI, vLLM Local, Perplexity" required
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Protocol</label>
                    <select name="format" class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500">
                        <option value="openai">OpenAI (/chat/completions)</option>
                        <option value="anthropic">Anthropic (/messages)</option>
                        <option value="gemini">Google Gemini (:generateContent)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Base URL</label>
                    <input type="url" name="base_url" placeholder="https://api.together.xyz/v1" required
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Description & Notes (Optional)</label>
                    <textarea name="description" rows="3" placeholder="e.g. Website link, account email, login info, billing note, or documentation URL..."
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl p-3.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 resize-none"></textarea>
                </div>

                <div class="pt-4 border-t border-white/[0.06] flex items-center justify-end gap-2.5">
                    <button type="button" @click="showProviderModal = false" class="px-4 py-2.5 text-sm font-semibold bg-rose-600 hover:bg-rose-500 text-white rounded-xl transition-all shadow-md cursor-pointer flex items-center gap-1.5"><i data-lucide="x" class="w-4 h-4"></i>Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 text-white font-semibold text-sm rounded-xl transition-all shadow-md cursor-pointer flex items-center gap-1.5">
                        <i data-lucide="plus" class="w-4 h-4"></i>Add Provider
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- MODAL: EDIT AI PROVIDER                                        -->
    <!-- ============================================================== -->
    <div x-show="showEditProviderModal"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 overflow-y-auto bg-black/75 flex items-center justify-center p-4" x-cloak>
        <div x-show="showEditProviderModal"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="bg-[#121215] border border-white/[0.08] rounded-2xl w-full max-w-md p-6 shadow-2xl relative" @click.away="showEditProviderModal = false">
            <div class="flex items-center justify-between pb-3.5 border-b border-white/[0.06] mb-4">
                <div class="flex items-center gap-2.5">
                    <i data-lucide="edit-3" class="w-5 h-5 text-emerald-400"></i>
                    <h3 class="text-base font-bold text-white">Edit AI Provider</h3>
                </div>
                <button type="button" @click="showEditProviderModal = false" class="text-zinc-500 hover:text-white text-xl leading-none">&times;</button>
            </div>

            <form :action="'/dashboard/providers/' + editProvider.id" method="POST" class="space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Provider Name</label>
                    <input type="text" name="name" x-model="editProvider.name" required
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Protocol</label>
                    <select name="format" x-model="editProvider.format" class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500">
                        <option value="openai">OpenAI (/chat/completions)</option>
                        <option value="anthropic">Anthropic (/messages)</option>
                        <option value="gemini">Google Gemini (:generateContent)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Base URL</label>
                    <input type="url" name="base_url" x-model="editProvider.base_url" required
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Description & Notes (Optional)</label>
                    <textarea name="description" x-model="editProvider.description" rows="3" placeholder="e.g. Website link, account email, login info, billing note, or documentation URL..."
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl p-3.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 resize-none"></textarea>
                </div>

                <div class="pt-4 border-t border-white/[0.06] flex items-center justify-end gap-2.5">
                    <button type="button" @click="showEditProviderModal = false" class="px-4 py-2.5 text-sm font-medium text-zinc-400 hover:text-white rounded-xl">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-semibold text-sm rounded-xl transition-all shadow-md">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
