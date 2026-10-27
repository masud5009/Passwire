@extends('layouts.dashboard')

@section('title', 'AI Providers & Key Pool')

@section('content')
@php
    $freeSlugs = ['gemini', 'groq', 'cerebras', 'openrouter', 'mistral'];
    $connectedCount = $providers->filter(fn($p) => $p->accounts->count() > 0)->count();
    $freeTierCount = $providers->filter(fn($p) => in_array($p->slug, $freeSlugs))->count();
    $openaiCount = $providers->filter(fn($p) => $p->format === 'openai')->count();
    $anthropicCount = $providers->filter(fn($p) => $p->format === 'anthropic')->count();
    $geminiCount = $providers->filter(fn($p) => $p->format === 'gemini')->count();

    $providersList = $providers->map(fn($p) => [
        'id' => $p->id,
        'name' => $p->name,
        'slug' => $p->slug,
        'format' => $p->format,
        'base_url' => $p->base_url,
        'description' => $p->description ?? '',
        'hasKeys' => $p->accounts->count() > 0,
        'isFree' => in_array($p->slug, $freeSlugs),
        'models' => $p->models_catalog ?? [],
    ])->values();
@endphp

<script>
    window.__initialProvidersList = @json($providersList);
</script>

<div class="space-y-6" x-data="providersManager(window.__initialProvidersList)">
    @if($providers->isEmpty())
        <!-- ============================================================== -->
        <!-- ZERO PROVIDERS CONFIGURED STATE (NO SEARCH/FILTERS)            -->
        <!-- ============================================================== -->

        <div class="border border-dashed border-zinc-200 dark:border-white/10 rounded-2xl p-12 text-center text-zinc-500 text-base flex flex-col items-center justify-center gap-3.5 bg-zinc-50/50 dark:bg-[#121622]/40">
            <div class="w-14 h-14 rounded-2xl bg-zinc-100 dark:bg-white/5 border border-zinc-200 dark:border-white/10 flex items-center justify-center">
                <i data-lucide="box" class="w-7 h-7 text-zinc-400 dark:text-zinc-500"></i>
            </div>
            <span class="text-zinc-900 dark:text-zinc-200 font-semibold text-lg">No AI providers configured yet</span>
            <span class="text-zinc-500 dark:text-zinc-400 text-sm max-w-md">Connect an upstream AI provider (OpenAI, Anthropic, Gemini, Groq, etc.) to start routing requests with load balancing and failover.</span>
            <button type="button" @click="openAddCustomProviderModal('openai')"
                class="btn-primary mt-2 px-4 py-2 text-xs sm:text-sm font-semibold text-white rounded-xl transition-all shadow-md flex items-center gap-2 cursor-pointer">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Add Custom Provider</span>
            </button>
        </div>
    @else
        <!-- ============================================================== -->
        <!-- TOP TOOLBAR: SEARCH, VIEWS & QUICK ACTIONS                     -->
        <!-- ============================================================== -->
        <div class="bg-[#121622]/90 border border-white/[0.07] rounded-2xl p-4 sm:p-5 shadow-lg space-y-3.5">
            <!-- Row 1: Search Inputs & Action Buttons -->
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-3 flex-1 min-w-[280px] flex-wrap">
                    <!-- Search Providers Input -->
                    <div class="relative flex-1 min-w-[180px] max-w-xs">
                        <i data-lucide="search" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500 pointer-events-none"></i>
                        <input type="text" x-model="searchQuery" placeholder="Search Providers"
                            class="w-full bg-[#161c2c] border border-white/10 rounded-xl pl-10 pr-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 transition-colors">
                    </div>

                    <!-- Search by Model Input -->
                    <div class="relative flex-1 min-w-[180px] max-w-xs">
                        <i data-lucide="sparkles" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-500 pointer-events-none"></i>
                        <input type="text" x-model="modelQuery" placeholder="Search by model..."
                            class="w-full bg-[#161c2c] border border-white/10 rounded-xl pl-10 pr-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 transition-colors">
                    </div>

                    <!-- Segmented View Filter -->
                    <div class="flex items-center bg-[#161c2c] p-1 rounded-xl border border-white/10 text-sm">
                        <button type="button" @click="providerFilter = 'all'"
                            :class="providerFilter === 'all' ? 'bg-zinc-800 text-white font-semibold shadow-sm' : 'text-zinc-400 hover:text-white'"
                            class="px-3.5 py-1.5 rounded-lg transition-colors flex items-center gap-1.5">
                            <i data-lucide="layout-grid" class="w-4 h-4"></i>
                            <span>All</span>
                        </button>
                        <button type="button" @click="providerFilter = 'connected'"
                            :class="providerFilter === 'connected' ? 'bg-zinc-800 text-emerald-400 font-semibold shadow-sm' : 'text-zinc-400 hover:text-white'"
                            class="px-3.5 py-1.5 rounded-lg transition-colors flex items-center gap-1.5">
                            <i data-lucide="check-circle-2" class="w-4 h-4"></i>
                            <span>Configured</span>
                        </button>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2 flex-wrap">
                    <button type="button" @click="openAddCustomProviderModal('openai')"
                        class="btn-primary px-3.5 py-2 text-xs sm:text-sm font-semibold text-white rounded-xl transition-all shadow-md flex items-center gap-2">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        <span>Add Custom Provider</span>
                    </button>
                </div>
            </div>

            <!-- Row 2: Category Filter Chips -->
            <div class="flex items-center gap-2 flex-wrap pt-2.5 border-t border-white/[0.05] text-xs">
                <button type="button" @click="providerFilter = 'all'"
                    :class="providerFilter === 'all' ? 'bg-rose-600 text-white shadow-sm' : 'bg-zinc-900/80 hover:bg-zinc-800 text-zinc-300 border border-white/5'"
                    class="px-3 py-1 rounded-full font-semibold transition-colors flex items-center gap-1.5"
                    :style="providerFilter === 'all' ? 'color: #ffffff !important;' : ''">
                    <span :style="providerFilter === 'all' ? 'color: #ffffff !important;' : ''">Total</span>
                    <span class="opacity-90 font-mono" :style="providerFilter === 'all' ? 'color: #ffffff !important;' : ''">{{ $providers->count() }}</span>
                </button>

                <button type="button" @click="providerFilter = 'connected'"
                    :class="providerFilter === 'connected' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 font-semibold' : 'bg-zinc-900/80 hover:bg-zinc-800 text-zinc-300 border border-white/5'"
                    class="px-3 py-1 rounded-full transition-colors flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                    <span>Connected</span>
                    <span class="text-zinc-400 font-mono">{{ $connectedCount }}</span>
                </button>

                <button type="button" @click="providerFilter = 'free'"
                    :class="providerFilter === 'free' ? 'bg-teal-500/20 text-teal-300 border border-teal-500/30 font-semibold' : 'bg-zinc-900/80 hover:bg-zinc-800 text-zinc-300 border border-white/5'"
                    class="px-3 py-1 rounded-full transition-colors flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-teal-400"></span>
                    <span>Free Tier</span>
                    <span class="text-zinc-400 font-mono">{{ $freeTierCount }}</span>
                </button>

                <button type="button" @click="providerFilter = 'openai'"
                    :class="providerFilter === 'openai' ? 'bg-blue-500/20 text-blue-300 border border-blue-500/30 font-semibold' : 'bg-zinc-900/80 hover:bg-zinc-800 text-zinc-300 border border-white/5'"
                    class="px-3 py-1 rounded-full transition-colors flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-blue-400"></span>
                    <span>OpenAI</span>
                    <span class="text-zinc-400 font-mono">{{ $openaiCount }}</span>
                </button>

                <button type="button" @click="providerFilter = 'anthropic'"
                    :class="providerFilter === 'anthropic' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30 font-semibold' : 'bg-zinc-900/80 hover:bg-zinc-800 text-zinc-300 border border-white/5'"
                    class="px-3 py-1 rounded-full transition-colors flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                    <span>Anthropic</span>
                    <span class="text-zinc-400 font-mono">{{ $anthropicCount }}</span>
                </button>

                <button type="button" @click="providerFilter = 'gemini'"
                    :class="providerFilter === 'gemini' ? 'bg-blue-500/20 text-blue-300 border border-blue-500/30 font-semibold' : 'bg-zinc-900/80 hover:bg-zinc-800 text-zinc-300 border border-white/5'"
                    class="px-3 py-1 rounded-full transition-colors flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-blue-400"></span>
                    <span>Gemini</span>
                    <span class="text-zinc-400 font-mono">{{ $geminiCount }}</span>
                </button>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- UNIFIED AI PROVIDERS SECTION                                   -->
        <!-- ============================================================== -->
        <div class="space-y-4" x-show="hasMatches()">
            <div class="flex items-center justify-between gap-4 flex-wrap pb-1">
                <div>
                    <h3 class="text-base font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                        <span>AI Providers</span>
                        <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span>
                        <span class="text-xs font-mono font-medium text-zinc-500 dark:text-zinc-400">({{ $connectedCount }}/{{ $providers->count() }})</span>
                    </h3>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Configured upstream AI providers with failover, load balancing, and rate limit cooldowns.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($providers as $provider)
                    @php
                        $hasKeys = $provider->accounts->count() > 0;
                        $brandGradients = [
                            'gemini' => 'from-blue-500/20 to-indigo-500/20 text-blue-400 border-blue-500/30',
                            'groq' => 'from-orange-500/20 to-amber-500/20 text-orange-400 border-orange-500/30',
                            'openrouter' => 'from-purple-500/20 to-indigo-500/20 text-purple-400 border-purple-500/30',
                            'deepseek' => 'from-cyan-500/20 to-blue-500/20 text-cyan-400 border-cyan-500/30',
                            'cerebras' => 'from-emerald-500/20 to-teal-500/20 text-emerald-400 border-emerald-500/30',
                            'mistral' => 'from-amber-500/20 to-red-500/20 text-amber-400 border-amber-500/30',
                            'openai' => 'from-emerald-500/20 to-zinc-700/20 text-emerald-400 border-emerald-500/30',
                            'anthropic' => 'from-orange-500/20 to-amber-700/20 text-amber-300 border-amber-500/30',
                        ];
                        $brandColor = $brandGradients[$provider->slug] ?? 'from-purple-900/40 to-pink-900/40 text-purple-300 border-purple-500/30';
                    @endphp
                    <div x-show="matchesProviderBySlug('{{ $provider->slug }}')"
                        @click="window.location.href = '{{ route('dashboard.providers.show', $provider->slug) }}'"
                        class="bg-white hover:bg-zinc-50/90 dark:bg-[#121622] dark:hover:bg-[#151b2a] border border-zinc-200/90 hover:border-zinc-300 dark:border-white/[0.08] dark:hover:border-white/[0.18] rounded-2xl p-5 flex flex-col justify-between transition-all duration-200 group min-h-[145px] shadow-sm hover:shadow-md cursor-pointer relative">
                        
                        <!-- Card Header -->
                        <div>
                            <div class="flex items-start justify-between gap-3 mb-2.5">
                                <a href="{{ route('dashboard.providers.show', $provider->slug) }}" class="flex items-center gap-3 min-w-0 flex-1 group/link">
                                    <div class="w-11 h-11 rounded-xl bg-gradient-to-tr {{ $brandColor }} border flex items-center justify-center font-bold text-sm flex-shrink-0 group-hover/link:scale-105 transition-transform shadow-sm">
                                        {{ strtoupper(substr($provider->slug, 0, 2)) }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <h4 class="font-bold text-base text-zinc-900 dark:text-white group-hover/link:text-emerald-500 transition-colors leading-snug truncate">
                                                {{ $provider->name }}
                                            </h4>
                                            <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-md border {{ $provider->format === 'anthropic' ? 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/20' : ($provider->format === 'gemini' ? 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:border-blue-500/20' : 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20') }}">
                                                {{ $provider->format }}
                                            </span>
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400 font-mono truncate mt-0.5">{{ $provider->slug }}</div>
                                    </div>
                                </a>

                                <!-- Action Toolbar Pill (Edit & Delete) -->
                                <div class="flex items-center bg-zinc-100/90 dark:bg-white/[0.04] p-1 rounded-xl border border-zinc-200/80 dark:border-white/[0.06] flex-shrink-0 shadow-sm" @click.stop>
                                    <button type="button"
                                        @click.stop="openEditProviderById({{ $provider->id }})"
                                        class="w-7 h-7 rounded-lg flex items-center justify-center text-zinc-500 hover:text-emerald-600 dark:text-zinc-400 dark:hover:text-emerald-400 hover:bg-white dark:hover:bg-white/10 transition-all cursor-pointer"
                                        title="Edit Provider">
                                        <i data-lucide="pencil" class="w-3.5 h-3.5 pointer-events-none"></i>
                                    </button>
                                    <div class="w-px h-3.5 bg-zinc-200 dark:bg-white/10 mx-0.5"></div>
                                    <form action="{{ route('dashboard.providers.delete', $provider) }}" method="POST" @click.stop class="inline-flex">
                                        @csrf
                                        @method('DELETE')
                                        <button type="button"
                                            @click.stop="confirmDelete($el.closest('form'), 'Delete Provider', 'Delete &quot;{{ $provider->name }}&quot; and all associated keys?', 'Yes, Delete Provider')"
                                            class="w-7 h-7 rounded-lg flex items-center justify-center text-zinc-500 hover:text-rose-600 dark:text-zinc-400 dark:hover:text-rose-400 hover:bg-white dark:hover:bg-white/10 transition-all cursor-pointer"
                                            title="Delete Provider">
                                            <i data-lucide="trash-2" class="w-3.5 h-3.5 pointer-events-none"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Card Middle: Endpoint Pill -->
                        <div class="my-2.5 px-3 py-1.5 rounded-lg bg-zinc-50 dark:bg-black/20 border border-zinc-200/60 dark:border-white/5 flex items-center justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400 font-mono">
                            <span class="truncate">{{ $provider->base_url }}</span>
                            @if(!empty($provider->description))
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 flex-shrink-0" title="Has Notes & Credentials"></span>
                            @endif
                        </div>

                        <!-- Card Footer -->
                        <div class="pt-3 border-t border-zinc-100 dark:border-white/[0.06] flex items-center justify-between text-xs">
                            <div>
                                @if($hasKeys)
                                    <div class="font-semibold text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5 text-xs">
                                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                        <span>{{ $provider->accounts->count() }} Connected</span>
                                    </div>
                                @else
                                    <span class="text-zinc-400 dark:text-zinc-500 text-xs flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-zinc-300 dark:bg-zinc-600"></span>
                                        No connections
                                    </span>
                                @endif
                            </div>

                            <div class="flex items-center gap-2">
                                @if($hasKeys)
                                    <button type="button" @click.stop="pingAccount({{ $provider->accounts->first()->id }})"
                                        :disabled="testingAccountId === {{ $provider->accounts->first()->id }}"
                                        class="px-3 py-1.5 text-xs font-semibold text-zinc-700 dark:text-zinc-200 hover:text-zinc-900 dark:hover:text-white bg-zinc-100 hover:bg-zinc-200/80 dark:bg-zinc-800/90 dark:hover:bg-zinc-700 rounded-xl border border-zinc-200/80 dark:border-white/5 transition-colors flex items-center gap-1.5 cursor-pointer shadow-none hover:shadow-sm">
                                        <i data-lucide="play" class="w-3 h-3 text-emerald-500 dark:text-zinc-400"></i>
                                        <span x-text="testingAccountId === {{ $provider->accounts->first()->id }} ? 'Testing...' : 'Test'"></span>
                                    </button>
                                @else
                                    <button type="button" @click.stop="openAddKeyModal({{ $provider->id }})"
                                        class="px-3.5 py-1.5 text-xs font-semibold text-white bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 rounded-xl shadow-sm transition-all flex items-center gap-1.5 cursor-pointer">
                                        <i data-lucide="plus" class="w-3 h-3"></i>
                                        <span>Connect</span>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- ZERO SEARCH MATCHES EMPTY STATE                                -->
        <!-- ============================================================== -->
        <div x-show="!hasMatches()"
            class="border border-dashed border-zinc-200 dark:border-white/10 rounded-2xl p-12 text-center text-zinc-500 text-sm flex flex-col items-center justify-center gap-2 bg-zinc-50/50 dark:bg-[#121622]/40">
            <i data-lucide="search-x" class="w-9 h-9 text-zinc-400 dark:text-zinc-600"></i>
            <span class="text-zinc-900 dark:text-zinc-200 font-semibold text-base">No matching providers found</span>
            <span class="text-zinc-500 dark:text-zinc-400 text-xs">Try adjusting your provider name or model search filter.</span>
        </div>
    @endif
</div>

@endsection
