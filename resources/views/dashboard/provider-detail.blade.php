@extends('layouts.dashboard')

@section('title', $provider->name . ' — Provider Details')

@section('content')
@php
    $freeSlugs = ['gemini', 'groq', 'cerebras', 'openrouter', 'mistral'];
    $isFreeTier = in_array($provider->slug, $freeSlugs);
    $models = $provider->models_catalog ?? [];

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

<!-- Synchronous Alpine Component Definition (Guarantees Instant Availability) -->
<script>
window.providerDetailManager = function() {
    return {
        modelFilterQuery: '',
        isImporting: false,
        testingAccountId: null,
        modelsList: @json(array_values($models)),
        init() {
            this.$nextTick(() => {
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });
        },
        filteredModels() {
            if (!this.modelFilterQuery.trim()) {
                return this.modelsList;
            }
            const q = this.modelFilterQuery.toLowerCase().trim();
            return this.modelsList.filter(m => (m || '').toLowerCase().includes(q));
        },
        async importModels() {
            this.isImporting = true;
            try {
                const res = await fetch(`{{ route('dashboard.providers.import-models', $provider) }}`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json'
                    }
                });
                const data = await res.json();
                if (data.success) {
                    if (Array.isArray(data.models)) {
                        this.modelsList = data.models;
                    }
                    if (typeof copyText === 'function') {
                        copyText('', data.message);
                    } else {
                        alert(data.message);
                    }
                } else {
                    alert(data.message || 'Failed to import models from provider endpoint.');
                }
            } catch (err) {
                alert('Connection error: ' + err.message);
            } finally {
                this.isImporting = false;
                this.$nextTick(() => {
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                });
            }
        },
        async pingAccount(accountId) {
            this.testingAccountId = accountId;
            try {
                const res = await fetch(`/dashboard/accounts/${accountId}/test`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json'
                    }
                });
                const data = await res.json();
                if (Array.isArray(data.models) && data.models.length > 0) {
                    this.modelsList = data.models;
                }
                if (typeof copyText === 'function') {
                    copyText(data.message, data.success ? 'Key Verified OK!' : 'Test Failed');
                }
            } catch (err) {
                alert('Test failed: ' + err.message);
            } finally {
                this.testingAccountId = null;
                this.$nextTick(() => {
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                });
            }
        }
    };
};
</script>

<div class="space-y-6" x-data="providerDetailManager()">
    <!-- ============================================================== -->
    <!-- BREADCRUMB & HEADER                                            -->
    <!-- ============================================================== -->
    <div>
        <!-- Provider Title Header Banner -->
        <div class="bg-white dark:bg-[#121622] border border-zinc-200/90 dark:border-white/[0.08] rounded-2xl p-6 shadow-sm flex items-center justify-between gap-5 flex-wrap">
            <div class="flex items-center gap-4 min-w-0">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr {{ $brandColor }} border flex items-center justify-center font-bold text-2xl shadow-sm flex-shrink-0">
                    {{ strtoupper(substr($provider->slug, 0, 2)) }}
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <h2 class="text-2xl font-black text-zinc-900 dark:text-white tracking-tight">{{ $provider->name }}</h2>
                        <span class="text-xs font-mono font-bold px-2.5 py-1 rounded-lg uppercase border {{ $provider->format === 'anthropic' ? 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/20' : 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20' }}">
                            {{ $provider->format }}
                        </span>
                        @if($isFreeTier)
                            <span class="text-xs font-semibold px-2.5 py-1 rounded-lg bg-teal-50 text-teal-800 border border-teal-200 dark:bg-teal-500/10 dark:text-teal-300 dark:border-teal-500/20">Free Tier</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-3 mt-2 text-xs text-zinc-500 dark:text-zinc-400 flex-wrap">
                        <span class="flex items-center gap-1.5 font-semibold text-emerald-600 dark:text-emerald-400">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span>{{ $provider->accounts->count() }} {{ Str::plural('connection', $provider->accounts->count()) }}</span>
                        </span>
                        <span>&bull;</span>
                        <div class="inline-flex items-center gap-1.5 bg-zinc-50 dark:bg-black/30 border border-zinc-200/80 dark:border-white/5 rounded-lg px-2.5 py-1 font-mono text-zinc-600 dark:text-zinc-300">
                            <span class="truncate max-w-xs">{{ $provider->base_url }}</span>
                            <button type="button" @click="copyText('{{ $provider->base_url }}', 'Base URL copied!')" class="text-zinc-400 hover:text-zinc-800 dark:hover:text-white transition-colors" title="Copy Base URL">
                                <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2.5 flex-shrink-0">
                <button type="button"
                    @click="openEditProviderModal({{ json_encode([
                        'id' => $provider->id,
                        'name' => $provider->name,
                        'slug' => $provider->slug,
                        'base_url' => $provider->base_url,
                        'format' => $provider->format,
                        'description' => $provider->description ?? '',
                    ]) }})"
                    class="px-4 py-2.5 bg-zinc-100 hover:bg-zinc-200 text-zinc-800 dark:bg-white/5 dark:hover:bg-white/10 dark:text-zinc-200 border border-zinc-200/80 dark:border-white/10 font-semibold text-xs sm:text-sm rounded-xl transition-all flex items-center gap-2 shadow-sm cursor-pointer">
                    <i data-lucide="pencil" class="w-4 h-4 text-emerald-500"></i>
                    <span>Edit Provider</span>
                </button>
                <button type="button" @click="openAddKeyModal({{ $provider->id }})"
                    class="px-4.5 py-2.5 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-semibold text-xs sm:text-sm rounded-xl transition-all shadow-md flex items-center gap-2 cursor-pointer">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    <span>Add Connection</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- DUAL COLUMN ROW: NOTES & CREDENTIALS + CONNECTIONS & API KEYS  -->
    <!-- ============================================================== -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">
        <!-- CARD 1: PROVIDER NOTES & PORTAL CREDENTIALS -->
        <div x-data="{ editingNotes: false }" class="bg-white dark:bg-[#121622] border border-zinc-200/90 dark:border-white/[0.08] rounded-2xl p-6 shadow-sm flex flex-col justify-between">
            <div>
                <!-- Card Header -->
                <div class="flex items-center justify-between pb-3.5 border-b border-zinc-100 dark:border-white/[0.06] flex-wrap gap-2">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400 flex-shrink-0">
                            <i data-lucide="file-text" class="w-4 h-4"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                                <span>Provider Notes & Portal Credentials</span>
                            </h3>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Portal logins, passwords, billing reminders.</p>
                        </div>
                    </div>

                    <button type="button" @click="editingNotes = !editingNotes" class="inline-flex items-center gap-1.5 text-xs font-semibold text-zinc-700 dark:text-zinc-300 hover:text-zinc-950 dark:hover:text-white px-3 py-1.5 rounded-xl bg-zinc-100 hover:bg-zinc-200 dark:bg-white/5 dark:hover:bg-white/10 transition-colors border border-zinc-200/80 dark:border-white/5 cursor-pointer shadow-sm">
                        <i data-lucide="pencil" class="w-3.5 h-3.5 text-emerald-500"></i>
                        <span x-text="editingNotes ? 'Close' : '{{ !empty($provider->description) ? 'Edit Notes' : '+ Add Notes' }}'"></span>
                    </button>
                </div>

                <!-- Notes Content View -->
                <div x-show="!editingNotes" class="mt-4">
                    @if(!empty($provider->description))
                        <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-black/20 border border-zinc-200/60 dark:border-white/5 font-mono text-xs sm:text-sm text-zinc-800 dark:text-zinc-200 whitespace-pre-wrap leading-relaxed select-text break-words min-h-[70px]">{{ trim($provider->description) }}</div>
                    @else
                        <div class="p-6 rounded-xl border border-dashed border-zinc-200 dark:border-white/10 text-center flex flex-col items-center justify-center bg-zinc-50/50 dark:bg-[#0d1017]">
                            <p class="text-zinc-400 dark:text-zinc-500 text-xs italic">
                                No notes or credentials stored yet. Click "Add Notes" to save portal login URLs, passwords, or billing reminders.
                            </p>
                        </div>
                    @endif
                </div>

                <!-- Notes Edit Form -->
                <form x-show="editingNotes" action="{{ route('dashboard.providers.update', $provider) }}" method="POST" class="space-y-3 mt-4" x-cloak>
                    @csrf
                    @method('PUT')
                    <textarea name="description" rows="4" placeholder="e.g. Website URL: https://console.together.ai&#10;Username: team@example.com&#10;Password Reference: vault-key-123&#10;Billing Note: Renews on 1st of every month"
                        class="w-full bg-zinc-50 dark:bg-[#09090b] border border-zinc-200 dark:border-white/10 rounded-xl p-3.5 text-xs sm:text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500 resize-none font-mono">{{ old('description', $provider->description) }}</textarea>
                    <div class="flex items-center justify-end gap-2.5">
                        <button type="button" @click="editingNotes = false" class="px-3.5 py-1.5 text-xs font-semibold text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-white rounded-xl">Cancel</button>
                        <button type="submit" class="px-4 py-1.5 bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-bold text-xs rounded-xl transition-all shadow-sm">Save Notes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- CARD 2: CONNECTIONS & API KEYS -->
        <div class="bg-white dark:bg-[#121622] border border-zinc-200/90 dark:border-white/[0.08] rounded-2xl p-6 shadow-sm flex flex-col justify-between">
            <div>
                <!-- Panel Header -->
                <div class="flex items-center justify-between pb-3.5 border-b border-zinc-100 dark:border-white/[0.06] flex-wrap gap-2">
                    <div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                            <span>Connections & API Keys</span>
                            <span class="text-xs font-mono font-bold px-2 py-0.5 rounded-md bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-white/5">{{ $provider->accounts->count() }} active</span>
                        </h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">API key connection pool for load balancing.</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" @click="openAddKeyModal({{ $provider->id }})"
                            class="px-3.5 py-1.5 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-semibold text-xs rounded-xl transition-all flex items-center gap-1.5 shadow-sm cursor-pointer">
                            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            <span>Add Connection</span>
                        </button>
                    </div>
                </div>

                <!-- Accounts List or Empty State -->
                <div class="mt-4">
                    @if($provider->accounts->isEmpty())
                        <div class="border border-dashed border-zinc-200 dark:border-white/10 rounded-xl p-6 text-center text-zinc-500 text-sm flex flex-col items-center justify-center gap-2.5 bg-zinc-50/50 dark:bg-[#0d1017]">
                            <div class="w-10 h-10 rounded-xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-zinc-400">
                                <i data-lucide="key" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-zinc-900 dark:text-white">No API connections configured</h4>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Add your upstream API key to start routing traffic.</p>
                            </div>
                            <button type="button" @click="openAddKeyModal({{ $provider->id }})"
                                class="mt-1 px-3.5 py-1.5 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-semibold text-xs rounded-xl transition-all flex items-center gap-1.5 shadow-sm cursor-pointer">
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                <span>Add First Connection</span>
                            </button>
                        </div>
                    @else
                        <!-- Accounts List -->
                        <div class="space-y-2.5">
                            @foreach($provider->accounts as $acc)
                                <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-[#161c2c] border border-zinc-200/80 dark:border-white/[0.06] hover:border-zinc-300 dark:hover:border-white/[0.12] transition-colors flex items-center justify-between gap-3 flex-wrap text-sm">
                                    <!-- Left Details -->
                                    <div class="flex items-center gap-3 min-w-0 flex-1">
                                        <div class="w-8 h-8 rounded-xl bg-white dark:bg-white/5 border border-zinc-200 dark:border-white/10 flex items-center justify-center text-zinc-500 dark:text-zinc-400 flex-shrink-0 shadow-sm">
                                            <i data-lucide="lock" class="w-3.5 h-3.5 text-emerald-500"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="font-mono font-bold text-zinc-900 dark:text-white text-xs sm:text-sm truncate">{{ $acc->label }}</span>
                                                @if($acc->isInCooldown())
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-red-50 text-red-700 border border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-ping"></span>
                                                        <span>Cooldown</span>
                                                    </span>
                                                @elseif($acc->is_active)
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/20">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                        <span>Connected</span>
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-zinc-100 text-zinc-500 border border-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:border-white/5">
                                                        Disabled
                                                    </span>
                                                @endif
                                                <span class="text-xs font-mono bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 rounded text-indigo-600 dark:text-indigo-300 font-bold border border-zinc-200/80 dark:border-white/5">Priority #{{ $acc->priority }}</span>
                                            </div>
                                            <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5 flex items-center gap-2 font-mono">
                                                <span>Today: <strong class="text-zinc-800 dark:text-zinc-200">{{ number_format($acc->tokens_used_today) }}</strong> tokens</span>
                                                @if($acc->daily_token_limit)
                                                    <span>&bull;</span>
                                                    <span>Limit: {{ number_format($acc->daily_token_limit) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Right Actions -->
                                    <div class="flex items-center gap-1.5 flex-shrink-0">
                                        <!-- Retest Button -->
                                        <button type="button" @click="pingAccount({{ $acc->id }})"
                                            :disabled="testingAccountId === {{ $acc->id }}"
                                            class="px-2.5 py-1 text-xs font-semibold text-zinc-700 dark:text-zinc-200 hover:text-zinc-900 dark:hover:text-white bg-white hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-700 rounded-lg border border-zinc-200/80 dark:border-white/5 transition-colors flex items-center gap-1 shadow-sm cursor-pointer" title="Retest Connection">
                                            <i data-lucide="refresh-cw" class="w-3 h-3 text-zinc-400" :class="testingAccountId === {{ $acc->id }} ? 'animate-spin' : ''"></i>
                                            <span x-text="testingAccountId === {{ $acc->id }} ? 'Testing...' : 'Retest'"></span>
                                        </button>

                                        <!-- Active Toggle Switch -->
                                        <form action="{{ route('dashboard.accounts.toggle', $acc) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="p-1 rounded-lg transition-colors cursor-pointer {{ $acc->is_active ? 'text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-500/10' : 'text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800' }}" title="{{ $acc->is_active ? 'Disable Account' : 'Enable Account' }}">
                                                <i data-lucide="{{ $acc->is_active ? 'toggle-right' : 'toggle-left' }}" class="w-5 h-5 pointer-events-none"></i>
                                            </button>
                                        </form>

                                        <!-- Delete Button -->
                                        <form action="{{ route('dashboard.accounts.delete', $acc) }}" method="POST" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="button"
                                                @click="confirmDelete($el.closest('form'), 'Delete Provider Connection', 'Permanently delete key &quot;{{ $acc->label }}&quot;?', 'Yes, Delete Key')"
                                                class="p-1 text-zinc-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg transition-colors cursor-pointer" title="Delete Connection">
                                                <i data-lucide="trash-2" class="w-4 h-4 pointer-events-none"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- SECTION 2: AVAILABLE MODELS PANEL (WITH IMPORT FROM /MODELS)   -->
    <!-- ============================================================== -->
    <div class="bg-white dark:bg-[#121622] border border-zinc-200/90 dark:border-white/[0.08] rounded-2xl p-6 shadow-sm space-y-4">
        <!-- Panel Header: Title + Import from /models Button -->
        <div class="flex items-center justify-between pb-3.5 border-b border-zinc-100 dark:border-white/[0.06] flex-wrap gap-3">
            <div>
                <h3 class="text-base font-bold text-zinc-900 dark:text-white flex items-center gap-2.5">
                    <span>Available Models</span>
                    <span class="text-xs font-mono font-bold px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-800 border border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20" x-text="`${modelsList.length} active`"></span>
                </h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">Models mapped to this upstream provider. Dynamically imported via API or manually configured.</p>
            </div>

            <!-- Import from /models Action -->
            <div class="flex items-center gap-2">
                <button type="button" @click="importModels()" :disabled="isImporting"
                    class="px-4 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 dark:bg-emerald-500/10 dark:hover:bg-emerald-500/20 dark:text-emerald-300 dark:border-emerald-500/20 font-semibold text-xs rounded-xl transition-all flex items-center gap-2 shadow-sm cursor-pointer">
                    <i data-lucide="download" class="w-4 h-4" :class="isImporting ? 'animate-bounce' : ''"></i>
                    <span x-text="isImporting ? 'Importing models...' : 'Import from /models'"></span>
                </button>
            </div>
        </div>

        <!-- Toolbar: Search / Filter Models -->
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="relative flex-1 min-w-[220px] max-w-sm">
                <i data-lucide="search" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-zinc-400 pointer-events-none"></i>
                <input type="text" x-model="modelFilterQuery" placeholder="Filter models..."
                    class="w-full bg-zinc-50 dark:bg-[#161c2c] border border-zinc-200/90 dark:border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500 transition-colors">
            </div>

            <span class="text-xs text-zinc-500 dark:text-zinc-400 font-mono font-semibold" x-text="`${filteredModels().length} / ${modelsList.length} shown`"></span>
        </div>

        <!-- Models Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5">
            <template x-for="modelName in filteredModels()" :key="modelName">
                <div class="p-4 rounded-2xl bg-white hover:bg-zinc-50/80 dark:bg-[#161c2c] dark:hover:bg-[#1b2338] border border-zinc-200/90 hover:border-zinc-300 dark:border-white/[0.06] dark:hover:border-white/[0.14] transition-all flex flex-col justify-between min-h-[110px] group shadow-sm hover:shadow-md">
                    <div class="flex items-start justify-between gap-2.5">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400 flex-shrink-0 shadow-sm">
                                <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                            </div>
                            <span class="font-mono text-xs sm:text-sm text-zinc-900 dark:text-white group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition-colors font-bold truncate" x-text="modelName"></span>
                        </div>
                        <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-800 border border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20 flex-shrink-0">
                            Available
                        </span>
                    </div>

                    <div class="pt-2.5 border-t border-zinc-100 dark:border-white/[0.04] flex items-center justify-between text-xs mt-2.5">
                        <span class="text-xs text-zinc-500 dark:text-zinc-400 truncate font-mono" x-text="'{{ $provider->slug }}/' + modelName"></span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" @click="copyText(modelName, `Copied model '${modelName}'!`)"
                                class="p-1.5 text-zinc-400 hover:text-zinc-800 dark:hover:text-white rounded-lg hover:bg-zinc-100 dark:hover:bg-white/5 transition-colors cursor-pointer" title="Copy Model ID">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                            </button>
                            <a :href="`{{ route('dashboard.playground') }}?model={{ $provider->slug }}/${encodeURIComponent(modelName)}`"
                                class="p-1.5 text-zinc-400 hover:text-emerald-600 dark:hover:text-emerald-400 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/5 transition-colors cursor-pointer" title="Test in Playground">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>
                            </a>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Empty state when no models match filter or catalog is empty -->
        <div x-show="filteredModels().length === 0" class="border border-dashed border-zinc-200 dark:border-white/10 rounded-2xl p-10 text-center text-zinc-500 dark:text-zinc-400 text-sm flex flex-col items-center justify-center gap-2.5 bg-zinc-50/50 dark:bg-[#0d1017]" x-cloak>
            <i data-lucide="box" class="w-8 h-8 text-zinc-400 dark:text-zinc-600"></i>
            <span class="text-zinc-900 dark:text-white font-bold">No models found</span>
            <span class="text-zinc-500 dark:text-zinc-400 text-xs">Click "Import from /models" above or test a connection to fetch models dynamically.</span>
        </div>
    </div>
</div>
@endsection
