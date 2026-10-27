@extends('layouts.dashboard')

@section('title', 'Smart Combos & Fallback Router')

@section('content')
@php
    $providersData = $providers->map(fn($p) => [
        'id' => $p->id,
        'name' => $p->name,
        'slug' => $p->slug,
        'format' => $p->format,
        'hasKeys' => $p->accounts->count() > 0,
        'models' => array_values($p->models_catalog ?? []),
    ])->values();
@endphp

<script>
    window.__comboProviders = @json($providersData);
</script>

<div class="space-y-6" x-data="{ showCreateModal: false }">
    <!-- ============================================================== -->
    <!-- TOP TOOLBAR: HEADER & ACTION BUTTON                            -->
    <!-- ============================================================== -->
    <div class="bg-white dark:bg-[#121622]/90 border border-zinc-200 dark:border-white/[0.07] rounded-2xl p-4 sm:p-5 shadow-sm dark:shadow-lg flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h2 class="text-base font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                <span>Smart Routing Combos</span>
                <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span>
                <span class="text-xs font-mono font-medium text-zinc-500 dark:text-zinc-400">({{ $combos->count() }})</span>
            </h2>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">High availability virtual model pipelines with automated fallback on 429 rate limits & failovers.</p>
        </div>

        <button type="button" @click="showCreateModal = true; $nextTick(() => { if (window.lucide) lucide.createIcons(); })"
            class="btn-primary px-4 py-2 text-xs sm:text-sm font-semibold text-white rounded-xl transition-all shadow-md flex items-center gap-2 cursor-pointer">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Create Smart Combo</span>
        </button>
    </div>

    @if($combos->isEmpty())
        <!-- ============================================================== -->
        <!-- ZERO COMBOS EMPTY STATE                                        -->
        <!-- ============================================================== -->
        <div class="border border-dashed border-zinc-200 dark:border-white/10 rounded-2xl p-12 text-center text-zinc-500 text-base flex flex-col items-center justify-center gap-3.5 bg-zinc-50/50 dark:bg-[#121622]/40">
            <div class="w-14 h-14 rounded-2xl bg-zinc-100 dark:bg-white/5 border border-zinc-200 dark:border-white/10 flex items-center justify-center">
                <i data-lucide="git-branch" class="w-7 h-7 text-zinc-400 dark:text-zinc-500"></i>
            </div>
            <span class="text-zinc-900 dark:text-zinc-200 font-semibold text-lg">No Smart Combos created yet</span>
            <span class="text-zinc-500 dark:text-zinc-400 text-sm max-w-md">Create virtual model names with ordered multi-provider fallback chains and prompt compression for 100% uptime.</span>
            <button type="button" @click="showCreateModal = true; $nextTick(() => { if (window.lucide) lucide.createIcons(); })"
                class="btn-primary mt-2 px-4 py-2 text-xs sm:text-sm font-semibold text-white rounded-xl transition-all shadow-md flex items-center gap-2 cursor-pointer">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Create First Combo</span>
            </button>
        </div>
    @else
        <!-- ============================================================== -->
        <!-- ACTIVE SMART COMBOS GRID                                       -->
        <!-- ============================================================== -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach($combos as $combo)
                <div class="bg-white dark:bg-[#121215] border border-zinc-200/90 hover:border-zinc-300 dark:border-white/[0.07] dark:hover:border-white/[0.14] rounded-2xl p-6 shadow-sm hover:shadow-md transition-all flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between gap-2 mb-2.5">
                            <h3 class="font-bold text-base text-zinc-900 dark:text-white flex items-center gap-2.5">
                                <i data-lucide="git-branch" class="w-4.5 h-4.5 text-emerald-500"></i>
                                <span>{{ $combo->name }}</span>
                            </h3>
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs font-bold px-2.5 py-1 rounded-full {{ $combo->strategy === 'round-robin' ? 'bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/20' : 'bg-indigo-50 text-indigo-700 border border-indigo-200 dark:bg-indigo-500/10 dark:text-indigo-300 dark:border-indigo-500/20' }}">
                                    {{ $combo->strategy }}
                                </span>
                                <span class="text-xs font-bold px-2.5 py-1 rounded-full {{ $combo->is_active ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/20' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                    {{ $combo->is_active ? 'Active' : 'Paused' }}
                                </span>
                            </div>
                        </div>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">{{ $combo->description ?: 'Smart multi-provider fallback sequence.' }}</p>

                        <!-- Visual Fallback Chain Pipeline Design -->
                        <div class="space-y-2 mb-4">
                            <div class="text-xs font-bold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-2.5">Fallback Pipeline Sequence:</div>
                            @foreach($combo->models ?? [] as $step => $m)
                                <div class="relative">
                                    <div class="flex items-center justify-between p-3 rounded-xl bg-zinc-50 dark:bg-[#09090b] border border-zinc-200/80 dark:border-white/[0.06] text-xs font-mono">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <span class="w-6 h-6 rounded-md bg-zinc-200 dark:bg-zinc-800 text-emerald-600 dark:text-emerald-400 text-xs font-bold flex items-center justify-center flex-shrink-0">
                                                {{ $step + 1 }}
                                            </span>
                                            <span class="text-zinc-800 dark:text-zinc-200 truncate">{{ $m }}</span>
                                        </div>
                                        <span class="text-xs text-zinc-400 font-sans font-medium flex-shrink-0">Step {{ $step + 1 }}</span>
                                    </div>
                                    @if(!$loop->last)
                                        <div class="flex items-center justify-center my-1.5 text-zinc-500">
                                            <div class="flex items-center gap-1.5 text-xs font-mono text-zinc-500 dark:text-zinc-400 bg-white dark:bg-[#09090b] px-3 py-1 rounded-full border border-zinc-200 dark:border-white/5 shadow-xs">
                                                <i data-lucide="arrow-down" class="w-3.5 h-3.5 text-emerald-500"></i>
                                                <span>fallback on 429 / failover</span>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if(!empty($combo->compression_modes))
                            <div class="flex items-center gap-1.5 flex-wrap text-xs mb-2">
                                <span class="text-zinc-500 dark:text-zinc-400 font-medium">Pipeline:</span>
                                @foreach($combo->compression_modes as $mode)
                                    @if($mode === 'pii' || $mode === 'pii_redaction')
                                        <span class="bg-purple-500/10 border border-purple-500/20 text-purple-400 px-2 py-0.5 rounded-md font-mono inline-flex items-center gap-1">
                                            <i data-lucide="shield-check" class="w-3 h-3 text-purple-400"></i>
                                            <span>pii-guard</span>
                                        </span>
                                    @else
                                        <span class="bg-zinc-100 dark:bg-zinc-900 border border-zinc-200 dark:border-white/5 text-emerald-600 dark:text-emerald-400 px-2 py-0.5 rounded-md font-mono">{{ $mode }}</span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="pt-3.5 border-t border-zinc-100 dark:border-white/[0.06] flex items-center justify-between mt-3">
                        <span class="text-xs text-zinc-400 font-mono">{{ count($combo->models ?? []) }} steps</span>
                        <div class="flex items-center gap-1.5">
                            <form action="{{ route('dashboard.combos.toggle', $combo) }}" method="POST">
                                @csrf
                                <button type="submit" class="p-2 rounded-xl transition-colors {{ $combo->is_active ? 'text-amber-500 hover:bg-amber-500/10' : 'text-emerald-500 hover:bg-emerald-500/10' }}" title="{{ $combo->is_active ? 'Pause Combo' : 'Enable Combo' }}">
                                    <i data-lucide="{{ $combo->is_active ? 'pause-circle' : 'play-circle' }}" class="w-4.5 h-4.5 pointer-events-none"></i>
                                </button>
                            </form>

                            <form action="{{ route('dashboard.combos.delete', $combo) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="button"
                                    @click="confirmDelete($el.closest('form'), 'Delete Smart Combo', 'Delete smart combo &quot;{{ $combo->name }}&quot;? Fallback routing for this model will cease.', 'Yes, Delete Combo')"
                                    class="p-2 text-zinc-400 hover:text-red-500 hover:bg-red-500/10 rounded-xl transition-colors cursor-pointer" title="Delete Combo">
                                    <i data-lucide="trash-2" class="w-4.5 h-4.5 pointer-events-none"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- ============================================================== -->
    <!-- MODAL: CREATE SMART COMBO                                      -->
    <!-- ============================================================== -->
    <div x-show="showCreateModal"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 overflow-y-auto bg-black/80 backdrop-blur-sm flex items-center justify-center p-3 sm:p-5" x-cloak>
        
        <div x-show="showCreateModal"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="bg-white dark:bg-[#121622] border border-zinc-200 dark:border-white/[0.1] rounded-2xl w-full max-w-5xl max-h-[92vh] flex flex-col shadow-2xl relative"
            @click.away="showCreateModal = false"
            x-data="comboBuilder(window.__comboProviders)">
            
            <!-- Modal Header (Sticky) -->
            <div class="flex items-center justify-between p-4 sm:p-5 border-b border-zinc-100 dark:border-white/[0.08] flex-shrink-0">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                        <i data-lucide="git-branch" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">Create New Smart Combo</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Virtual model name with fallback routing & token compression</p>
                    </div>
                </div>
                <button type="button" @click="showCreateModal = false" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-white p-1 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 transition-colors text-2xl leading-none cursor-pointer">
                    &times;
                </button>
            </div>

            <!-- Modal Body (Scrollable) -->
            <div class="p-4 sm:p-6 overflow-y-auto space-y-4">
                <form id="createComboForm" action="{{ route('dashboard.combos.store') }}" method="POST" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 mb-1.5">Combo Name</label>
                            <input type="text" name="name" placeholder="auto/custom" required
                                class="w-full bg-zinc-50 dark:bg-[#09090b] border border-zinc-200 dark:border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 mb-1.5">Description</label>
                            <input type="text" name="description" placeholder="Fallback sequence for high availability"
                                class="w-full bg-zinc-50 dark:bg-[#09090b] border border-zinc-200 dark:border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 mb-1.5">Strategy</label>
                            <select name="strategy" class="w-full bg-zinc-50 dark:bg-[#09090b] border border-zinc-200 dark:border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-zinc-900 dark:text-white focus:outline-none focus:border-emerald-500">
                                <option value="priority">Priority Fallback (Step 1 ➔ Step 2)</option>
                                <option value="round-robin">Round-Robin (Rotate starting)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400 mb-2">Prompt Compression & Privacy Guardrails</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-zinc-700 dark:text-zinc-300">
                            <label class="flex items-center gap-2 bg-zinc-50 dark:bg-[#09090b] border border-zinc-200 dark:border-white/5 px-3 py-2 rounded-xl cursor-pointer hover:border-zinc-300 dark:hover:border-white/10">
                                <input type="checkbox" name="compression_modes[]" value="dedup" checked class="rounded border-zinc-300 dark:border-white/10 text-emerald-500 focus:ring-emerald-500">
                                <span><strong>dedup</strong>: Deduplicate repeated system messages</span>
                            </label>
                            <label class="flex items-center gap-2 bg-zinc-50 dark:bg-[#09090b] border border-zinc-200 dark:border-white/5 px-3 py-2 rounded-xl cursor-pointer hover:border-zinc-300 dark:hover:border-white/10">
                                <input type="checkbox" name="compression_modes[]" value="lite" checked class="rounded border-zinc-300 dark:border-white/10 text-emerald-500 focus:ring-emerald-500">
                                <span><strong>lite</strong>: Collapse 3+ newlines & spaces</span>
                            </label>
                            <label class="flex items-center gap-2 bg-zinc-50 dark:bg-[#09090b] border border-zinc-200 dark:border-white/5 px-3 py-2 rounded-xl cursor-pointer hover:border-zinc-300 dark:hover:border-white/10">
                                <input type="checkbox" name="compression_modes[]" value="caveman" class="rounded border-zinc-300 dark:border-white/10 text-emerald-500 focus:ring-emerald-500">
                                <span><strong>caveman</strong>: Strip conversational filler</span>
                            </label>
                            <label class="flex items-center gap-2 bg-zinc-50 dark:bg-[#09090b] border border-purple-500/20 px-3 py-2 rounded-xl cursor-pointer hover:border-purple-500/40">
                                <input type="checkbox" name="compression_modes[]" value="pii" checked class="rounded border-zinc-300 dark:border-white/10 text-purple-500 focus:ring-purple-500">
                                <span class="flex items-center gap-1.5"><i data-lucide="shield-check" class="w-3.5 h-3.5 text-purple-400"></i><strong class="text-purple-400">pii</strong>: Mask API keys, cards, emails & phones</span>
                            </label>
                        </div>
                    </div>



                    <!-- Ordered Fallback Sequence Visual Pipeline -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">
                                Fallback Sequence (<code class="text-emerald-500">provider_slug/model_name</code>)
                            </label>
                            <button type="button" x-show="sequence.length > 0" @click="sequence = []" class="text-xs text-rose-500 hover:text-rose-600 font-medium cursor-pointer">
                                Clear All Steps
                            </button>
                        </div>

                        <!-- Hidden Input for Form Submission -->
                        <input type="hidden" name="models" :value="sequenceString" required>

                        <!-- Empty State -->
                        <div x-show="sequence.length === 0" class="p-5 rounded-xl border border-dashed border-zinc-200 dark:border-white/10 bg-white dark:bg-[#09090b]/50 text-center text-zinc-500 text-xs">
                            No models added yet. Click any model chip below to build your fallback chain.
                        </div>

                        <!-- Sequence Steps List -->
                        <div x-show="sequence.length > 0" class="space-y-2 mb-3">
                            <template x-for="(step, index) in sequence" :key="step">
                                <div>
                                    <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-[#09090b] border border-zinc-200 dark:border-white/10 hover:border-zinc-300 dark:hover:border-white/20 transition-all text-xs font-mono shadow-xs">
                                        <div class="flex items-center gap-2.5 min-w-0">
                                            <span class="w-5 h-5 rounded-md bg-emerald-50 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/30 text-[11px] font-bold flex items-center justify-center flex-shrink-0" x-text="index + 1"></span>
                                            <span class="text-zinc-900 dark:text-zinc-100 font-semibold truncate" x-text="step"></span>
                                        </div>
                                        <div class="flex items-center gap-1 flex-shrink-0">
                                            <button type="button" @click="moveUp(index)" :disabled="index === 0" class="p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 text-zinc-400 hover:text-zinc-700 dark:hover:text-white disabled:opacity-20 disabled:pointer-events-none transition-colors" title="Move Up (Increase Priority)">
                                                <i data-lucide="arrow-up" class="w-3.5 h-3.5"></i>
                                            </button>
                                            <button type="button" @click="moveDown(index)" :disabled="index === sequence.length - 1" class="p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 text-zinc-400 hover:text-zinc-700 dark:hover:text-white disabled:opacity-20 disabled:pointer-events-none transition-colors" title="Move Down (Decrease Priority)">
                                                <i data-lucide="arrow-down" class="w-3.5 h-3.5"></i>
                                            </button>
                                            <button type="button" @click="removeStep(index)" class="p-1.5 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-500/10 text-zinc-400 hover:text-rose-500 transition-colors ml-1" title="Remove Step">
                                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <template x-if="index < sequence.length - 1">
                                        <div class="flex items-center justify-center my-1 text-zinc-400">
                                            <div class="flex items-center gap-1 text-[10px] font-mono text-zinc-500 dark:text-zinc-400 bg-zinc-100 dark:bg-[#09090b] px-2.5 py-0.5 rounded-full border border-zinc-200 dark:border-white/5">
                                                <i data-lucide="arrow-down" class="w-3 h-3 text-emerald-500"></i>
                                                <span>fallback on 429 / failover</span>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <!-- All Quick Chips from Connected Providers -->
                        <div class="pt-3.5 border-t border-zinc-200 dark:border-white/[0.06] space-y-3">
                            <div class="text-xs font-semibold text-zinc-600 dark:text-zinc-400 flex items-center justify-between gap-3 flex-wrap">
                                <div class="flex items-center gap-2">
                                    <i data-lucide="sparkles" class="w-4 h-4 text-amber-500"></i>
                                    <span>Quick Add from Available Models Catalog</span>
                                    <span class="text-[10px] text-zinc-400 font-normal">(Green = Added)</span>
                                </div>
                                <!-- Real-time search filter for catalog models -->
                                <div class="relative min-w-[220px] flex-1 max-w-xs">
                                    <i data-lucide="search" class="w-3.5 h-3.5 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                                    <input type="text" x-model="catalogSearch" placeholder="Filter models (e.g. flash, deepseek)..."
                                        class="w-full bg-zinc-50 dark:bg-[#141926] border border-zinc-200 dark:border-white/10 rounded-xl pl-8 pr-3 py-1.5 text-xs text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500">
                                </div>
                            </div>

                            <div class="space-y-3 max-h-64 sm:max-h-72 overflow-y-auto pr-1.5">
                                @foreach($providers as $p)
                                    @if(!empty($p->models_catalog))
                                        <div class="p-3 rounded-xl bg-zinc-50 dark:bg-zinc-900/60 border border-zinc-200/80 dark:border-white/5 space-y-2">
                                            <div class="flex items-center justify-between gap-2">
                                                <div class="flex items-center gap-2">
                                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                                    <span class="text-xs font-bold text-zinc-800 dark:text-white">{{ $p->name }}</span>
                                                    <span class="text-[11px] font-mono text-zinc-400">({{ $p->slug }})</span>
                                                    <span class="text-[10px] px-1.5 py-0.5 rounded-md bg-zinc-200 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 font-mono">{{ count($p->models_catalog) }} models</span>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                @foreach($p->models_catalog as $m)
                                                    <button type="button" @click="toggleModel('{{ $p->slug }}/{{ $m }}')"
                                                        x-show="matchesCatalogSearch('{{ $m }}')"
                                                        :class="isInSequence('{{ $p->slug }}/{{ $m }}') ? 'bg-emerald-600 text-white border-emerald-500 font-semibold shadow-xs' : 'bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border-zinc-200 dark:border-white/10 hover:border-zinc-300 dark:hover:bg-zinc-700'"
                                                        class="px-2.5 py-1 rounded-lg border font-mono text-[11px] transition-all flex items-center gap-1.5 cursor-pointer">
                                                        <span x-show="isInSequence('{{ $p->slug }}/{{ $m }}')">✓</span>
                                                        <span x-show="!isInSequence('{{ $p->slug }}/{{ $m }}')">+</span>
                                                        <span>{{ $m }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>

                            <!-- Optional: Add custom unlisted model -->
                            <div class="flex items-center gap-2 pt-2 border-t border-zinc-200/60 dark:border-white/5">
                                <span class="text-[11px] text-zinc-500 font-medium">Or add custom model:</span>
                                <div class="flex items-center gap-1.5 flex-1 max-w-xs">
                                    <input type="text" x-model="customModelInput" @keydown.enter.prevent="addCustomModel()" placeholder="provider/model-name"
                                        class="w-full bg-white dark:bg-[#141926] border border-zinc-200 dark:border-white/10 rounded-lg px-2.5 py-1 text-xs text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:border-emerald-500 font-mono">
                                    <button type="button" @click="addCustomModel()"
                                        class="px-2.5 py-1 bg-zinc-800 hover:bg-zinc-700 text-white rounded-lg text-xs font-semibold flex items-center gap-1 cursor-pointer">
                                        <i data-lucide="plus" class="w-3 h-3"></i>
                                        <span>Add</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Modal Footer (Sticky) -->
            <div class="p-3.5 sm:p-4 border-t border-zinc-100 dark:border-white/[0.08] flex items-center justify-between gap-3 bg-zinc-50 dark:bg-[#0c101a] rounded-b-2xl flex-shrink-0">
                <button type="button" @click="showCreateModal = false"
                    class="px-4 py-2 text-xs sm:text-sm font-semibold text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white rounded-xl transition-colors cursor-pointer">
                    Cancel
                </button>
                <button type="submit" form="createComboForm" :disabled="sequence.length === 0"
                    class="btn-primary px-4 sm:px-5 py-2 sm:py-2.5 disabled:opacity-40 disabled:pointer-events-none text-white font-semibold text-xs sm:text-sm rounded-xl shadow-md transition-all flex items-center gap-2 cursor-pointer">
                    <i data-lucide="check" class="w-4 h-4"></i>
                    <span>Save Smart Combo</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endsection
