@extends('layouts.dashboard')

@section('title', 'Live AI Studio Playground')

@php
    $configuredProviders = $providers->filter(fn($p) => $p->accounts->where('is_active', true)->count() > 0);
    $configuredSlugs = $configuredProviders->pluck('slug')->all();

    // Smart Combos that have at least 1 active configured provider in their fallback pipeline
    $readyCombos = $combos->map(function ($combo) use ($configuredSlugs) {
        $total = count($combo->models ?? []);
        $ready = 0;
        foreach ($combo->models ?? [] as $mEntry) {
            $slug = str_contains($mEntry, '/') ? explode('/', $mEntry, 2)[0] : 'openai';
            if (in_array($slug, $configuredSlugs)) {
                $ready++;
            }
        }
        return [
            'combo' => $combo,
            'total' => $total,
            'ready' => $ready,
            'is_ready' => $ready > 0,
        ];
    })->filter(fn($item) => $item['is_ready']);

    // Helper to group and prioritize catalog models (agy first, then auto, then others)
    $prioritizeCatalog = function(array $catalog) {
        $agy = [];
        $auto = [];
        $other = [];
        foreach ($catalog as $m) {
            if (str_starts_with($m, 'agy/')) {
                $agy[] = $m;
            } elseif (str_starts_with($m, 'auto/')) {
                $auto[] = $m;
            } else {
                $other[] = $m;
            }
        }
        sort($agy);
        sort($auto);
        sort($other);
        return [
            'agy' => $agy,
            'auto' => $auto,
            'other' => $other,
            'all' => array_merge($agy, $auto, $other)
        ];
    };

    // Build structured catalog map for client-side search modal
    $allCatalogsByProvider = [];
    foreach ($configuredProviders as $cp) {
        $cat = $prioritizeCatalog($cp->models_catalog ?? []);
        $allCatalogsByProvider[$cp->slug] = [
            'name' => $cp->name,
            'slug' => $cp->slug,
            'models' => $cat['all'],
            'agy' => $cat['agy'],
            'auto' => $cat['auto'],
            'other' => $cat['other'],
        ];
    }

    // Default target model: First ready combo OR first model of first configured provider
    $defaultPlayModel = null;
    if ($readyCombos->isNotEmpty()) {
        $defaultPlayModel = $readyCombos->first()['combo']->name;
    } elseif ($configuredProviders->isNotEmpty()) {
        foreach ($configuredProviders as $cp) {
            $cat = $prioritizeCatalog($cp->models_catalog ?? []);
            if (!empty($cat['agy'])) {
                $defaultPlayModel = $cp->slug . '/' . $cat['agy'][0];
                break;
            } elseif (!empty($cat['auto'])) {
                $defaultPlayModel = $cp->slug . '/' . $cat['auto'][0];
                break;
            } elseif (!empty($cat['all'])) {
                $defaultPlayModel = $cp->slug . '/' . $cat['all'][0];
                break;
            }
        }
        if (!$defaultPlayModel && $configuredProviders->first()) {
            $defaultPlayModel = $configuredProviders->first()->slug . '/default';
        }
    }
    if (!$defaultPlayModel) {
        $defaultPlayModel = 'auto';
    }
@endphp

@section('content')
<div x-data="{
        playModel: '{{ $defaultPlayModel }}',
        customPlayModel: '',
        useCustomModel: false,
        playTemperature: 0.7,
        playSystem: 'You are a helpful and concise AI assistant.',
        playInput: '',
        playLoading: false,
        playMessages: [
            { role: 'assistant', content: 'Passwire live gateway playground is ready. Select a model or smart combo and send a prompt!', isWelcome: true }
        ],
        playMeta: null,
        catalogModalOpen: false,
        catalogModalProvider: '',
        catalogModalProviderName: '',
        catalogModalSearch: '',
        catalogModalFilter: 'all',
        catalogs: @js($allCatalogsByProvider),

        selectPlayModel(modelStr) {
            this.playModel = modelStr;
            this.customPlayModel = modelStr;
            this.useCustomModel = false;
            this.catalogModalOpen = false;
        },

        openCatalogModal(providerSlug, providerName) {
            this.catalogModalProvider = providerSlug;
            this.catalogModalProviderName = providerName;
            this.catalogModalSearch = '';
            this.catalogModalFilter = 'all';
            this.catalogModalOpen = true;
        },

        getFilteredCatalogModels() {
            if (!this.catalogModalProvider || !this.catalogs[this.catalogModalProvider]) {
                return [];
            }
            const data = this.catalogs[this.catalogModalProvider];
            let list = data.models || [];
            if (this.catalogModalFilter === 'agy') list = data.agy || [];
            else if (this.catalogModalFilter === 'auto') list = data.auto || [];
            else if (this.catalogModalFilter === 'other') list = data.other || [];

            const q = this.catalogModalSearch.toLowerCase().trim();
            if (!q) return list;
            return list.filter(m => m.toLowerCase().includes(q));
        },

        async resetAccountCooldown(actId) {
            try {
                const res = await fetch(`/dashboard/accounts/${actId}/clear-cooldown`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json'
                    }
                });
                if (res.ok) {
                    window.location.reload();
                }
            } catch (e) {
                console.error('Failed to reset cooldown', e);
            }
        },

        async sendPlayground() {
            let targetModel = this.playModel;
            if (this.useCustomModel || this.playModel === 'custom') {
                targetModel = this.customPlayModel.trim();
            }
            if (!targetModel) {
                this.playMessages.push({
                    role: 'assistant',
                    content: '⚠️ Please select or enter a valid target model first.',
                    isError: true
                });
                return;
            }

            const text = this.playInput.trim();
            if (!text || this.playLoading) return;
            
            this.playMessages.push({ role: 'user', content: text });
            this.playInput = '';
            this.playLoading = true;
            this.playMeta = null;

            const msgs = [];
            if (this.playSystem.trim()) {
                msgs.push({ role: 'system', content: this.playSystem.trim() });
            }
            const chatHistory = this.playMessages.filter(m => !m.isWelcome && !m.isError);
            msgs.push(...chatHistory.slice(-6));

            try {
                const res = await fetch('{{ route('dashboard.playground.chat') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        model: targetModel,
                        temperature: parseFloat(this.playTemperature),
                        messages: msgs
                    })
                });

                const data = await res.json();
                const choices = data.choices || data.response?.choices;
                const meta = data._passwire_meta || data._omniroute_meta || data.meta || data.response?._passwire_meta || data.response?._omniroute_meta;

                if (res.ok && choices && choices[0]) {
                    const assistantText = choices[0].message?.content || choices[0].text || '';
                    this.playMessages.push({
                        role: 'assistant',
                        content: assistantText,
                        meta: meta || null
                    });
                    this.playMeta = meta || null;
                } else {
                    const errMsg = data.error?.message 
                        || (typeof data.error === 'string' ? data.error : null) 
                        || data.message 
                        || 'Error communicating with AI Gateway';
                    this.playMessages.push({
                        role: 'assistant',
                        content: `⚠️ Error: ${errMsg}`,
                        isError: true
                    });
                }
            } catch (err) {
                this.playMessages.push({
                    role: 'assistant',
                    content: `⚠️ Network error: ${err.message}`,
                    isError: true
                });
            } finally {
                this.playLoading = false;
                this.$nextTick(() => {
                    const chatBox = document.getElementById('play-chat-box');
                    if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
                    lucide.createIcons();
                });
            }
        }
    }" 
    class="space-y-6">

    <!-- Split Studio Layout -->
    <div class="bg-[#121215] border border-white/[0.07] rounded-2xl p-5 sm:p-6 shadow-lg grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Left Panel: Configuration -->
        <div class="lg:col-span-1 space-y-5">
            <!-- 1. Target Model / Smart Combo Selector -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold uppercase tracking-wider text-zinc-400">Target Model / Smart Combo</label>
                    <button type="button" @click="useCustomModel = !useCustomModel" class="text-xs text-emerald-400 hover:text-emerald-300 font-mono transition-colors">
                        <span x-text="useCustomModel ? 'Select Dropdown' : '+ Custom Model'"></span>
                    </button>
                </div>

                <div x-show="!useCustomModel">
                    <select x-model="playModel" class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500 font-mono">
                        @if($readyCombos->isNotEmpty())
                            <optgroup label="⚡ Ready Smart Routing Combos">
                                @foreach($readyCombos as $rc)
                                    <option value="{{ $rc['combo']->name }}">
                                        {{ $rc['combo']->name }} ({{ $rc['ready'] }}/{{ $rc['total'] }} ready • {{ $rc['combo']->strategy }})
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif

                        @if($configuredProviders->isNotEmpty())
                            @foreach($configuredProviders as $p)
                                @php
                                    $cat = $prioritizeCatalog($p->models_catalog ?? []);
                                @endphp

                                @if(!empty($cat['agy']))
                                    <optgroup label="{{ $p->name }} • Antigravity (agy/*) [{{ count($cat['agy']) }}]">
                                        @foreach($cat['agy'] as $m)
                                            <option value="{{ $p->slug }}/{{ $m }}">{{ $p->slug }}/{{ $m }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif

                                @if(!empty($cat['auto']))
                                    <optgroup label="{{ $p->name }} • Auto Smart Routing (auto/*) [{{ count($cat['auto']) }}]">
                                        @foreach($cat['auto'] as $m)
                                            <option value="{{ $p->slug }}/{{ $m }}">{{ $p->slug }}/{{ $m }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif

                                @if(!empty($cat['other']))
                                    <optgroup label="{{ $p->name }} • Extended Models [{{ count($cat['other']) }}]">
                                        @foreach($cat['other'] as $m)
                                            <option value="{{ $p->slug }}/{{ $m }}">{{ $p->slug }}/{{ $m }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif

                                @if(empty($cat['all']))
                                    <optgroup label="{{ $p->name }}">
                                        <option value="{{ $p->slug }}/default">{{ $p->slug }} (Custom model required)</option>
                                    </optgroup>
                                @endif
                            @endforeach
                        @endif

                        @if($readyCombos->isEmpty() && $configuredProviders->isEmpty())
                            <option value="auto" disabled>No active provider keys connected</option>
                        @endif

                        <option value="custom">✏️ Type Custom Model Identifier...</option>
                    </select>
                </div>

                <!-- Custom Model Input Option -->
                <div x-show="useCustomModel || playModel === 'custom'" class="space-y-1.5" x-cloak>
                    <div class="relative">
                        <input type="text" x-model="customPlayModel" placeholder="provider_slug/model_name (e.g. my-local-provider/agy/claude-opus-4-6-thinking)"
                            class="w-full bg-[#09090b] border border-emerald-500/50 rounded-xl px-3.5 py-2.5 text-sm text-white font-mono placeholder-zinc-600 focus:outline-none focus:border-emerald-400">
                        <button type="button" x-show="customPlayModel" @click="customPlayModel = ''" class="absolute right-3 top-2.5 text-zinc-500 hover:text-white text-sm">&times;</button>
                    </div>
                    <p class="text-xs text-zinc-400 font-mono">Format: <code class="text-emerald-400">provider_slug/model_name</code></p>
                </div>
            </div>

            <!-- 2. System Prompt -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-zinc-400 mb-2">System Prompt</label>
                <textarea x-model="playSystem" rows="3" placeholder="You are a helpful and concise AI assistant."
                    class="w-full bg-[#09090b] border border-white/10 rounded-xl p-3.5 text-sm text-white placeholder-zinc-600 focus:outline-none focus:border-emerald-500 resize-none font-mono"></textarea>
            </div>

            <!-- 3. Temperature Slider -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-xs font-bold uppercase tracking-wider text-zinc-400">Temperature</label>
                    <span class="text-sm font-mono text-emerald-400 font-bold" x-text="playTemperature"></span>
                </div>
                <input type="range" x-model="playTemperature" min="0" max="2" step="0.1"
                    class="w-full accent-emerald-500 bg-zinc-800 rounded-lg h-2 cursor-pointer">
                <div class="flex justify-between text-xs text-zinc-500 font-mono mt-1.5">
                    <span>0.0 Precise</span>
                    <span>1.0 Balanced</span>
                    <span>2.0 Creative</span>
                </div>
            </div>



            <!-- Live Telemetry Card -->
            <template x-if="playMeta">
                <div class="p-4 rounded-xl bg-[#09090b] border border-emerald-500/20 text-xs sm:text-sm space-y-2.5">
                    <div class="flex items-center justify-between">
                        <div class="font-bold text-emerald-400 uppercase text-xs tracking-wider flex items-center gap-2">
                            <i data-lucide="zap" class="w-3.5 h-3.5 text-emerald-400"></i>
                            <span>Routing Telemetry</span>
                        </div>
                        <template x-if="playMeta.cached">
                            <span class="text-[10px] font-mono px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 font-bold animate-pulse">
                                ⚡ CACHE HIT
                            </span>
                        </template>
                    </div>
                    <div class="flex items-center justify-between text-zinc-400">
                        <span>Provider:</span>
                        <span class="font-bold text-white font-mono" x-text="playMeta.provider"></span>
                    </div>
                    <div class="flex items-center justify-between text-zinc-400">
                        <span>Model:</span>
                        <span class="font-mono text-emerald-300 truncate max-w-[140px]" x-text="playMeta.model"></span>
                    </div>
                    <div class="flex items-center justify-between text-zinc-400">
                        <span>Account:</span>
                        <span class="text-zinc-200 truncate max-w-[140px]" x-text="playMeta.account"></span>
                    </div>
                    <div class="flex items-center justify-between text-zinc-400">
                        <span>Tokens Saved:</span>
                        <span class="font-bold text-emerald-400" x-text="`+${playMeta.saved_tokens}`"></span>
                    </div>
                    <div class="flex items-center justify-between text-zinc-400">
                        <span>Est. Cost:</span>
                        <div class="text-right">
                            <span class="font-bold text-white font-mono" x-text="`$${Number(playMeta.cost_usd || 0).toFixed(5)}`"></span>
                            <template x-if="playMeta.cost_saved_usd > 0">
                                <span class="block text-[11px] text-emerald-400 font-mono" x-text="`+$${Number(playMeta.cost_saved_usd).toFixed(5)} saved`"></span>
                            </template>
                        </div>
                    </div>
                    <div class="flex items-center justify-between text-zinc-400">
                        <span>Latency:</span>
                        <span class="font-mono text-amber-400" x-text="`${playMeta.latency_ms}ms`"></span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Right Panel: Chat Studio Window -->
        <div class="lg:col-span-3 flex flex-col h-[600px] bg-[#09090b] border border-white/[0.07] rounded-xl overflow-hidden shadow-inner">
            <!-- Chat Studio Window Header -->
            <div class="px-4 py-2.5 border-b border-white/[0.06] bg-[#121215] flex items-center justify-between text-xs text-zinc-400">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                    <span class="font-semibold text-zinc-300 font-mono text-[11px]">Chat Console</span>
                </div>
                <button type="button" @click="playMessages = [{ role: 'assistant', content: 'Chat history cleared. Send a prompt to start fresh!', isWelcome: true }]; playMeta = null;"
                    class="text-xs text-zinc-400 hover:text-white flex items-center gap-1 px-2.5 py-1 rounded-lg hover:bg-white/5 transition-colors cursor-pointer">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5 text-zinc-500"></i>
                    <span>Clear Chat</span>
                </button>
            </div>

            <!-- Messages Area -->
            <div id="play-chat-box" class="flex-1 p-5 overflow-y-auto space-y-4">
                <template x-for="(msg, i) in playMessages" :key="i">
                    <div class="flex flex-col" :class="msg.role === 'user' ? 'items-end' : 'items-start'">
                        <div class="max-w-[85%] rounded-2xl px-5 py-3 text-sm leading-relaxed"
                            :class="msg.role === 'user' 
                                ? 'bg-emerald-500 text-zinc-950 font-medium rounded-br-none shadow-md shadow-emerald-500/10' 
                                : (msg.isError ? 'bg-red-500/10 border border-red-500/20 text-red-300 rounded-bl-none' : 'bg-[#121215] border border-white/[0.08] text-zinc-100 rounded-bl-none shadow-sm')">
                            <p class="whitespace-pre-wrap" x-text="msg.content"></p>
                        </div>
                        
                        <template x-if="msg.meta">
                            <div class="text-xs text-zinc-400 mt-1.5 flex items-center gap-1.5 font-mono">
                                <template x-if="msg.meta.cached">
                                    <span class="px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 font-bold text-[10px] border border-emerald-500/30">CACHE HIT</span>
                                </template>
                                <span class="text-emerald-400 font-semibold" x-text="msg.meta.provider"></span> / 
                                <span x-text="msg.meta.model"></span> • 
                                <span class="text-emerald-400" x-text="`+${msg.meta.saved_tokens} saved`"></span> • 
                                <span class="text-zinc-200" x-text="`$${Number(msg.meta.cost_usd || 0).toFixed(5)}`"></span> • 
                                <span class="text-amber-400" x-text="`${msg.meta.latency_ms}ms`"></span>
                            </div>
                        </template>
                    </div>
                </template>

                <div x-show="playLoading" class="flex items-center gap-2.5 text-sm text-emerald-400 p-2 font-mono">
                    <div class="w-3.5 h-3.5 border-2 border-emerald-400 border-t-transparent rounded-full animate-spin"></div>
                    <span>Compressing prompt & routing to upstream provider...</span>
                </div>
            </div>

            <!-- Input Bar -->
            <div class="p-3.5 bg-[#121215] border-t border-white/[0.07] flex items-center gap-3">
                <input type="text" x-model="playInput" @keydown.enter="sendPlayground()" placeholder="Ask a question or test fallback behavior..."
                    :disabled="playLoading"
                    class="flex-1 bg-[#09090b] border border-white/10 rounded-xl px-4 py-2.5 text-xs sm:text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 disabled:opacity-50 font-sans">
                <button type="button" @click="sendPlayground()" :disabled="playLoading || !playInput.trim()"
                    class="btn-primary px-4 py-2.5 text-white font-semibold text-xs sm:text-sm rounded-xl shadow-md transition-all flex items-center gap-2 disabled:opacity-50 cursor-pointer">
                    <span>Send</span>
                    <i data-lucide="send" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Models Browser / Search Modal -->
    <div x-show="catalogModalOpen"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75" x-cloak>
        <div x-show="catalogModalOpen"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            @click.away="catalogModalOpen = false" class="bg-[#121215] border border-white/10 rounded-2xl w-full max-w-2xl max-h-[85vh] flex flex-col shadow-2xl overflow-hidden">
            <!-- Modal Header -->
            <div class="p-5 border-b border-white/[0.06] flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-white flex items-center gap-2.5">
                        <span>Browse Available Models</span>
                        <span class="text-xs font-mono text-zinc-300 bg-zinc-800 px-2.5 py-1 rounded-md" x-text="catalogModalProviderName"></span>
                    </h3>
                    <p class="text-xs text-zinc-400 mt-1">Click any model to instantly set it as the Playground target model.</p>
                </div>
                <button type="button" @click="catalogModalOpen = false" class="p-2 text-zinc-400 hover:text-white rounded-xl hover:bg-zinc-800 transition-colors">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <!-- Filter Controls -->
            <div class="p-4 border-b border-white/[0.06] space-y-3 bg-[#09090b]/50">
                <div class="relative">
                    <i data-lucide="search" class="w-4 h-4 text-zinc-500 absolute left-3.5 top-3"></i>
                    <input type="text" x-model="catalogModalSearch" placeholder="Filter models by name (e.g. claude, flash, opus, coding)..."
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-sm text-white placeholder-zinc-600 focus:outline-none focus:border-emerald-500 font-mono">
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <button type="button" @click="catalogModalFilter = 'all'"
                        :class="catalogModalFilter === 'all' ? 'bg-emerald-500 text-zinc-950 font-bold' : 'bg-zinc-800 text-zinc-300 hover:text-white'"
                        class="text-xs font-mono px-3 py-1.5 rounded-lg transition-colors">
                        All (<span x-text="catalogs[catalogModalProvider]?.models?.length || 0"></span>)
                    </button>
                    <button type="button" @click="catalogModalFilter = 'agy'"
                        :class="catalogModalFilter === 'agy' ? 'bg-emerald-500 text-zinc-950 font-bold' : 'bg-zinc-800 text-zinc-300 hover:text-white'"
                        class="text-xs font-mono px-3 py-1.5 rounded-lg transition-colors">
                        Antigravity agy/* (<span x-text="catalogs[catalogModalProvider]?.agy?.length || 0"></span>)
                    </button>
                    <button type="button" @click="catalogModalFilter = 'auto'"
                        :class="catalogModalFilter === 'auto' ? 'bg-emerald-500 text-zinc-950 font-bold' : 'bg-zinc-800 text-zinc-300 hover:text-white'"
                        class="text-xs font-mono px-3 py-1.5 rounded-lg transition-colors">
                        Auto Smart Routing auto/* (<span x-text="catalogs[catalogModalProvider]?.auto?.length || 0"></span>)
                    </button>
                    <button type="button" @click="catalogModalFilter = 'other'"
                        :class="catalogModalFilter === 'other' ? 'bg-emerald-500 text-zinc-950 font-bold' : 'bg-zinc-800 text-zinc-300 hover:text-white'"
                        class="text-xs font-mono px-3 py-1.5 rounded-lg transition-colors">
                        Extended (<span x-text="catalogs[catalogModalProvider]?.other?.length || 0"></span>)
                    </button>
                </div>
            </div>

            <!-- Model Grid -->
            <div class="p-5 overflow-y-auto flex-1 max-h-[50vh] space-y-2.5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <template x-for="m in getFilteredCatalogModels()" :key="m">
                        <button type="button"
                            @click="selectPlayModel(`${catalogModalProvider}/${m}`)"
                            :class="playModel === `${catalogModalProvider}/${m}` ? 'border-emerald-500 bg-emerald-500/10 text-emerald-300' : 'border-white/[0.06] bg-[#09090b] text-zinc-300 hover:border-emerald-500/40 hover:text-white hover:bg-zinc-900'"
                            class="p-3 rounded-xl border text-left transition-all flex items-center justify-between group">
                            <span class="text-xs sm:text-sm font-mono truncate" x-text="m"></span>
                            <i data-lucide="arrow-up-right" class="w-4 h-4 text-zinc-600 group-hover:text-emerald-400 transition-colors shrink-0"></i>
                        </button>
                    </template>
                </div>

                <div x-show="getFilteredCatalogModels().length === 0" class="py-10 text-center text-sm text-zinc-500 font-mono">
                    No models matched your search filter.
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="p-4 border-t border-white/[0.06] bg-[#09090b]/40 flex items-center justify-between text-xs text-zinc-400">
                <span>Showing <strong class="text-white" x-text="getFilteredCatalogModels().length"></strong> models</span>
                <button type="button" @click="catalogModalOpen = false" class="px-4 py-1.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-100 rounded-lg transition-colors text-xs font-semibold">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>
@endsection
