@extends('layouts.dashboard')

@section('title', 'Request Logs')

@section('content')
<div class="space-y-6">
    <!-- Top Toolbar: Stats & Retention Prune Actions -->
    <div class="bg-white dark:bg-[#121622]/90 border border-zinc-200 dark:border-white/[0.07] rounded-2xl p-4 sm:p-5 shadow-sm dark:shadow-lg flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h2 class="text-base font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                <span>Request Telemetry Logs</span>
                <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span>
                <span class="text-xs font-mono font-medium text-zinc-500 dark:text-zinc-400">({{ number_format($totalLogsCount ?? $logs->count()) }} total records)</span>
            </h2>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                Indexed real-time audit logs with B-Tree acceleration.
                @if(($retentionDays ?? 30) > 0)
                    <span class="text-emerald-500 dark:text-emerald-400 font-medium">Automatic daily retention active ({{ $retentionDays }} days).</span>
                @else
                    <span class="text-amber-500 dark:text-amber-400 font-medium">Retention auto-pruning disabled.</span>
                @endif
            </p>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            @if($logs->isNotEmpty())
                <!-- Prune Old Logs Action -->
                <form action="{{ route('dashboard.logs.prune') }}" method="POST" class="inline">
                    @csrf
                    <input type="hidden" name="days" value="{{ $retentionDays ?? 30 }}">
                    <button type="button"
                        @click="confirmDelete($el.closest('form'), 'Prune Historical Logs', 'Prune all request logs older than {{ $retentionDays ?? 30 }} days? Recent logs will remain intact.', 'Yes, Prune Old Logs')"
                        class="px-3.5 py-2 text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-800/80 hover:bg-zinc-700/80 border border-white/10 rounded-xl transition-all shadow-sm flex items-center gap-1.5 cursor-pointer"
                        title="Prune logs older than retention period">
                        <i data-lucide="archive" class="w-4 h-4 text-zinc-400"></i>
                        <span>Prune &gt;{{ $retentionDays ?? 30 }}d</span>
                    </button>
                </form>

                <!-- Clear All Logs Action -->
                <form action="{{ route('dashboard.logs.clear') }}" method="POST" class="inline">
                    @csrf
                    <button type="button"
                        @click="confirmDelete($el.closest('form'), 'Clear Request Logs', 'Permanently truncate all request logs? This cannot be undone.', 'Yes, Clear All Logs')"
                        class="px-3.5 py-2 text-xs font-semibold text-red-400 hover:text-red-300 bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 rounded-xl transition-all shadow-sm flex items-center gap-1.5 cursor-pointer"
                        title="Clear all logs">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                        <span>Clear All Logs</span>
                    </button>
                </form>
            @endif
        </div>
    </div>

    <!-- Logs Table Container -->
    <div class="bg-[#121215] border border-white/[0.07] rounded-2xl overflow-hidden shadow-lg">
        @if($logs->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[#09090b] text-zinc-400 uppercase text-xs font-bold border-b border-white/[0.06] tracking-wider">
                        <tr>
                            <th class="py-3.5 px-4">Time</th>
                            <th class="py-3.5 px-4">Client Key</th>
                            <th class="py-3.5 px-4">Requested Model</th>
                            <th class="py-3.5 px-4">Routed Upstream</th>
                            <th class="py-3.5 px-4">Account Label</th>
                            <th class="py-3.5 px-4">Tokens (Prompt + Compl)</th>
                            <th class="py-3.5 px-4">Tokens Saved</th>
                            <th class="py-3.5 px-4">Est. Cost</th>
                            <th class="py-3.5 px-4">Latency</th>
                            <th class="py-3.5 px-4">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.05]">
                        @foreach($logs as $log)
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="py-3.5 px-4 text-zinc-400 font-mono whitespace-nowrap">
                                    <div class="text-xs font-semibold text-zinc-200">{{ $log->created_at->format('H:i:s') }}</div>
                                    <div class="text-xs text-zinc-500">{{ $log->created_at->format('M d') }}</div>
                                </td>
                                <td class="py-3.5 px-4">
                                    @if($log->gatewayKey)
                                        <span class="text-zinc-200 font-semibold text-sm">{{ $log->gatewayKey->name }}</span>
                                    @else
                                        <span class="text-zinc-500 font-mono text-xs">-</span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 font-mono font-semibold text-white text-xs truncate max-w-[160px]">
                                    {{ $log->requested_model }}
                                </td>
                                <td class="py-3.5 px-4 font-mono text-xs">
                                    @if($log->routed_provider)
                                        <span class="text-emerald-400 font-semibold">{{ $log->routed_provider }}</span>/<span class="text-zinc-300">{{ $log->routed_model }}</span>
                                    @else
                                        <span class="text-zinc-500">-</span>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 text-zinc-300 font-mono text-xs">
                                    {{ $log->account_label ?? '-' }}
                                </td>
                                <td class="py-3.5 px-4 font-mono">
                                    <span class="text-white font-medium text-xs">{{ number_format($log->total_tokens) }}</span>
                                    <span class="text-zinc-500 text-xs">({{ $log->prompt_tokens }} + {{ $log->completion_tokens }})</span>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-emerald-400 text-xs font-medium">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        @if($log->saved_tokens > 0)
                                            <span>+{{ number_format($log->saved_tokens) }}</span>
                                        @else
                                            <span class="text-zinc-600">0</span>
                                        @endif

                                        @if($log->redacted_pii_count > 0)
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-mono font-bold bg-purple-500/10 text-purple-400 border border-purple-500/20" title="{{ $log->redacted_pii_count }} sensitive PII items redacted before upstream delivery">
                                                <i data-lucide="shield-check" class="w-3 h-3 text-purple-400"></i>
                                                <span>{{ $log->redacted_pii_count }} PII</span>
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 font-mono whitespace-nowrap">
                                    @if($log->is_cached)
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-zinc-500 line-through text-[11px]">${{ number_format($log->cost_saved_usd, 5) }}</span>
                                            <span class="text-emerald-400 font-semibold text-xs">$0.00</span>
                                        </div>
                                        <div class="text-[10px] text-emerald-400 font-medium">Saved ${{ number_format($log->cost_saved_usd, 5) }}</div>
                                    @else
                                        <div class="text-zinc-200 font-semibold text-xs">${{ number_format($log->cost_usd, 5) }}</div>
                                        @if($log->cost_saved_usd > 0)
                                            <div class="text-[10px] text-emerald-400 font-medium">+${{ number_format($log->cost_saved_usd, 5) }} saved</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 font-mono text-amber-400 text-xs font-medium whitespace-nowrap">
                                    {{ $log->latency_ms }} ms
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    @if($log->is_cached)
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-300 mr-1.5"></span>
                                            Cached
                                        </span>
                                    @elseif($log->status_code === 200)
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 mr-1.5"></span>
                                            200 OK
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-red-500/10 text-red-400 border border-red-500/20" title="{{ $log->error_message }}">
                                            <span class="w-1.5 h-1.5 rounded-full bg-red-400 mr-1.5"></span>
                                            {{ $log->status_code }} Error
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="py-16 text-center text-zinc-500 text-sm">
                <div class="w-14 h-14 rounded-2xl bg-zinc-800/80 border border-white/5 mx-auto flex items-center justify-center text-zinc-400 mb-3.5">
                    <i data-lucide="activity" class="w-7 h-7"></i>
                </div>
                <div class="text-base text-zinc-300 font-semibold">No gateway requests logged yet</div>
                <p class="text-xs text-zinc-500 mt-1 max-w-sm mx-auto">Use the <a href="{{ route('dashboard.playground') }}" class="text-emerald-400 underline underline-offset-2">Live Playground</a> or send prompts from Cursor/client to start generating request logs.</p>
            </div>
        @endif
    </div>
</div>
@endsection
