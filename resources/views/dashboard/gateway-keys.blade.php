@extends('layouts.dashboard')

@section('title', 'Client Gateway API Keys')

@section('content')
<div class="space-y-6" x-data="{
    editModalOpen: false,
    editingKey: {
        id: null,
        name: '',
        daily_token_limit: '',
        rpm_limit: '',
        tpm_limit: '',
        updateUrl: ''
    },
    openEditModal(key) {
        this.editingKey = {
            id: key.id,
            name: key.name,
            daily_token_limit: key.daily_token_limit || '',
            rpm_limit: key.rpm_limit || '',
            tpm_limit: key.tpm_limit || '',
            updateUrl: '/dashboard/gateway-keys/' + key.id
        };
        this.editModalOpen = true;
    }
}">
    <!-- Generator Box with Granular RPM / TPM Limits -->
    <div class="bg-[#121215] border border-white/[0.07] rounded-2xl p-6 shadow-lg space-y-4">
        <div class="border-b border-white/[0.06] pb-3">
            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                <i data-lucide="key" class="w-4 h-4 text-emerald-400"></i>
                <span>Generate Client API Key</span>
            </h3>
            <p class="text-xs text-zinc-400 mt-0.5">Provision secure virtual keys for external IDEs, agents, or microservices with granular rate limit enforcement.</p>
        </div>

        <form action="{{ route('dashboard.gateway-keys.store') }}" method="POST" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3.5 items-end">
            @csrf
            <div class="lg:col-span-2">
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Client Label</label>
                <input type="text" name="name" placeholder="e.g. Cursor IDE Workstation" required
                    class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Daily Quota</label>
                <input type="number" name="daily_token_limit" placeholder="Tokens (unlimited)" min="1"
                    class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 font-mono">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5 flex items-center justify-between">
                    <span>RPM Limit</span>
                    <span class="text-[10px] text-blue-400 font-mono">req/min</span>
                </label>
                <input type="number" name="rpm_limit" placeholder="e.g. 60 (unlimited)" min="1"
                    class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 font-mono">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5 flex items-center justify-between">
                    <span>TPM Limit</span>
                    <span class="text-[10px] text-purple-400 font-mono">tok/min</span>
                </label>
                <input type="number" name="tpm_limit" placeholder="e.g. 100000" min="1"
                    class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 font-mono">
            </div>
            <div class="sm:col-span-2 lg:col-span-5 flex justify-end pt-1">
                <button type="submit" class="btn-primary w-full sm:w-auto px-5 py-2.5 text-white font-semibold text-sm rounded-xl shadow-md transition-colors whitespace-nowrap flex items-center justify-center gap-2 cursor-pointer">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    <span>Generate Virtual Key</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Keys Table -->
    <div class="bg-[#121215] border border-white/[0.07] rounded-2xl overflow-hidden shadow-lg">
        @if($gatewayKeys->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[#09090b] text-zinc-400 uppercase text-xs font-bold border-b border-white/[0.06] tracking-wider">
                        <tr>
                            <th class="py-3.5 px-5">Key Name</th>
                            <th class="py-3.5 px-5">API Key Token</th>
                            <th class="py-3.5 px-5">Rate Limits (RPM & TPM)</th>
                            <th class="py-3.5 px-5">Daily Quota Usage</th>
                            <th class="py-3.5 px-5">Last Activity</th>
                            <th class="py-3.5 px-5">Status</th>
                            <th class="py-3.5 px-5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.05]">
                        @foreach($gatewayKeys as $gKey)
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="py-4 px-5 font-semibold text-white">
                                    <div class="text-sm font-bold text-white">{{ $gKey->name }}</div>
                                </td>
                                <td class="py-4 px-5 font-mono">
                                    <div class="flex items-center gap-2">
                                        <span class="bg-[#09090b] border border-white/10 px-2.5 py-1 rounded-lg text-emerald-300 font-mono text-xs select-all">
                                            {{ $gKey->key }}
                                        </span>
                                        <button type="button" @click="copyText('{{ $gKey->key }}', 'API key copied!')" class="p-1.5 text-zinc-400 hover:text-white cursor-pointer" title="Copy Key">
                                            <i data-lucide="copy" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                </td>
                                <td class="py-4 px-5 whitespace-nowrap">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <!-- RPM Badge -->
                                        @if($gKey->rpm_limit)
                                            <span class="px-2.5 py-1 rounded-lg bg-blue-500/10 text-blue-400 border border-blue-500/20 font-mono text-xs font-semibold flex items-center gap-1">
                                                <i data-lucide="gauge" class="w-3 h-3"></i>
                                                <span>{{ number_format($gKey->rpm_limit) }} RPM</span>
                                            </span>
                                        @else
                                            <span class="px-2.5 py-1 rounded-lg bg-zinc-800 text-zinc-400 border border-white/5 font-mono text-xs">
                                                ∞ RPM
                                            </span>
                                        @endif

                                        <!-- TPM Badge -->
                                        @if($gKey->tpm_limit)
                                            <span class="px-2.5 py-1 rounded-lg bg-purple-500/10 text-purple-300 border border-purple-500/20 font-mono text-xs font-semibold flex items-center gap-1">
                                                <i data-lucide="zap" class="w-3 h-3"></i>
                                                <span>{{ number_format($gKey->tpm_limit) }} TPM</span>
                                            </span>
                                        @else
                                            <span class="px-2.5 py-1 rounded-lg bg-zinc-800 text-zinc-400 border border-white/5 font-mono text-xs">
                                                ∞ TPM
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-4 px-5">
                                    <div class="font-bold text-white font-mono">{{ number_format($gKey->tokens_used_today) }}</div>
                                    <div class="text-xs text-zinc-500 font-mono">Quota: {{ $gKey->daily_token_limit ? number_format($gKey->daily_token_limit) : 'Unlimited' }}</div>
                                </td>
                                <td class="py-4 px-5 text-zinc-400 font-mono text-xs whitespace-nowrap">
                                    {{ $gKey->last_used_at ? $gKey->last_used_at->diffForHumans() : 'Never' }}
                                </td>
                                <td class="py-4 px-5 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {{ $gKey->is_active ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-zinc-800 text-zinc-400' }}">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $gKey->is_active ? 'bg-emerald-400' : 'bg-zinc-500' }} mr-1.5"></span>
                                        {{ $gKey->is_active ? 'Active' : 'Disabled' }}
                                    </span>
                                </td>
                                <td class="py-4 px-5 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <!-- Edit Key Button -->
                                        <button type="button"
                                            @click="openEditModal({{ json_encode($gKey) }})"
                                            class="p-2 text-zinc-400 hover:text-white hover:bg-white/5 rounded-xl transition-colors cursor-pointer" title="Edit Rate Limits & Quota">
                                            <i data-lucide="pencil" class="w-4 h-4 pointer-events-none"></i>
                                        </button>

                                        <!-- Pause / Enable Toggle -->
                                        <form action="{{ route('dashboard.gateway-keys.toggle', $gKey) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="p-2 rounded-xl transition-colors cursor-pointer {{ $gKey->is_active ? 'text-amber-400 hover:bg-amber-500/10' : 'text-emerald-400 hover:bg-emerald-500/10' }}" title="{{ $gKey->is_active ? 'Pause Key' : 'Enable Key' }}">
                                                <i data-lucide="{{ $gKey->is_active ? 'pause-circle' : 'play-circle' }}" class="w-4 h-4 pointer-events-none"></i>
                                            </button>
                                        </form>

                                        <!-- Delete Button -->
                                        <form action="{{ route('dashboard.gateway-keys.delete', $gKey) }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                            <button type="button"
                                                @click="confirmDelete($el.closest('form'), 'Delete Gateway Key', 'Permanently delete gateway key &quot;{{ $gKey->name }}&quot;? External clients using this key will immediately lose access.', 'Yes, Delete Key')"
                                                class="p-2 text-zinc-500 hover:text-red-400 hover:bg-red-500/10 rounded-xl transition-colors cursor-pointer" title="Delete Key">
                                                <i data-lucide="trash-2" class="w-4 h-4 pointer-events-none"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="py-14 text-center text-zinc-500 text-sm">
                <div class="w-14 h-14 rounded-2xl bg-zinc-800/80 border border-white/5 mx-auto flex items-center justify-center text-zinc-400 mb-3.5">
                    <i data-lucide="key-round" class="w-7 h-7"></i>
                </div>
                <div class="text-base text-zinc-300 font-semibold">No gateway keys generated yet</div>
                <p class="text-xs text-zinc-500 mt-1 max-w-sm mx-auto">Generate your first virtual key above to start routing requests.</p>
            </div>
        @endif
    </div>

    <!-- Edit Gateway Key Modal -->
    <div x-show="editModalOpen"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75" x-cloak>
        <div x-show="editModalOpen"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            @click.away="editModalOpen = false"
            class="bg-[#121215] border border-white/10 rounded-2xl w-full max-w-lg shadow-2xl overflow-hidden p-6 space-y-5">
            
            <div class="flex items-center justify-between border-b border-white/[0.06] pb-3.5">
                <div class="flex items-center gap-2.5">
                    <i data-lucide="sliders" class="w-5 h-5 text-emerald-400"></i>
                    <h3 class="text-base font-bold text-white">Edit Gateway Key & Rate Limits</h3>
                </div>
                <button type="button" @click="editModalOpen = false" class="text-zinc-500 hover:text-white text-xl leading-none">&times;</button>
            </div>

            <form :action="editingKey.updateUrl" method="POST" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Client Label</label>
                    <input type="text" name="name" x-model="editingKey.name" required
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5">Daily Token Quota</label>
                    <input type="number" name="daily_token_limit" x-model="editingKey.daily_token_limit" placeholder="Optional (unlimited)" min="1"
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 font-mono">
                </div>

                <div class="grid grid-cols-2 gap-3.5">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5 flex items-center justify-between">
                            <span>RPM Limit</span>
                            <span class="text-[10px] text-blue-400 font-mono">req/min</span>
                        </label>
                        <input type="number" name="rpm_limit" x-model="editingKey.rpm_limit" placeholder="Unlimited" min="1"
                            class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-1.5 flex items-center justify-between">
                            <span>TPM Limit</span>
                            <span class="text-[10px] text-purple-400 font-mono">tok/min</span>
                        </label>
                        <input type="number" name="tpm_limit" x-model="editingKey.tpm_limit" placeholder="Unlimited" min="1"
                            class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 focus:outline-none focus:border-emerald-500 font-mono">
                    </div>
                </div>

                <div class="pt-4 border-t border-white/[0.06] flex items-center justify-end gap-2.5">
                    <button type="button" @click="editModalOpen = false" class="px-4 py-2.5 text-sm font-semibold bg-rose-600 hover:bg-rose-500 text-white rounded-xl transition-all shadow-md cursor-pointer">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 text-white font-semibold text-sm rounded-xl transition-all shadow-md cursor-pointer">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
