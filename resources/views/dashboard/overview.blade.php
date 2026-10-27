@extends('layouts.dashboard')

@section('title', 'Overview & Live Analytics')

@section('content')
<div class="space-y-6">
    <!-- 6 Bento KPI Cards with subtle radial gradient glow -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        <!-- Card 1: Requests -->
        <div class="bg-[#121215] border border-white/[0.07] hover:border-white/[0.15] rounded-2xl p-5 shadow-lg relative overflow-hidden transition-all group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-zinc-400 uppercase tracking-wider">Requests</span>
                <div class="w-8 h-8 rounded-lg bg-zinc-800/80 border border-white/5 flex items-center justify-center text-zinc-400 group-hover:text-white transition-colors">
                    <i data-lucide="activity" class="w-4 h-4"></i>
                </div>
            </div>
            <div class="text-2xl font-extrabold tracking-tight text-white">{{ number_format($stats['total_requests_today']) }}</div>
            <div class="flex items-center gap-1.5 text-xs text-zinc-500 mt-2.5 font-mono">
                <span class="text-emerald-400 font-medium">100% gateway uptime</span>
            </div>
        </div>

        <!-- Card 2: Tokens Consumed -->
        <div class="bg-[#121215] border border-white/[0.07] hover:border-white/[0.15] rounded-2xl p-5 shadow-lg relative overflow-hidden transition-all group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-zinc-400 uppercase tracking-wider">Processed</span>
                <div class="w-8 h-8 rounded-lg bg-zinc-800/80 border border-white/5 flex items-center justify-center text-zinc-400 group-hover:text-indigo-400 transition-colors">
                    <i data-lucide="zap" class="w-4 h-4"></i>
                </div>
            </div>
            <div class="text-2xl font-extrabold tracking-tight text-white">{{ number_format($stats['total_tokens_today']) }}</div>
            <div class="flex items-center gap-1 text-xs text-zinc-500 mt-2.5 font-mono">
                <span>Tokens today</span>
            </div>
        </div>

        <!-- Card 3: Tokens Saved -->
        <div class="bg-[#121215] border border-white/[0.07] hover:border-white/[0.15] rounded-2xl p-5 shadow-lg relative overflow-hidden transition-all group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-emerald-400 uppercase tracking-wider">Tokens Saved</span>
                <div class="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                    <i data-lucide="sparkles" class="w-4 h-4"></i>
                </div>
            </div>
            <div class="text-2xl font-extrabold tracking-tight text-emerald-400">+{{ number_format($stats['total_saved_tokens']) }}</div>
            <div class="flex items-center justify-between text-xs text-emerald-500/80 mt-2.5 font-mono">
                <span>Cache & Pipeline</span>
                <span class="text-zinc-400 font-normal">({{ $stats['cached_requests_today'] }} hits • {{ $stats['redacted_pii_today'] }} PII)</span>
            </div>
        </div>

        <!-- Card 4: Estimated Spend -->
        <div class="bg-[#121215] border border-white/[0.07] hover:border-white/[0.15] rounded-2xl p-5 shadow-lg relative overflow-hidden transition-all group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-zinc-400 uppercase tracking-wider">Est. Spend</span>
                <div class="w-8 h-8 rounded-lg bg-sky-500/10 border border-sky-500/20 flex items-center justify-center text-sky-400">
                    <i data-lucide="circle-dollar-sign" class="w-4 h-4"></i>
                </div>
            </div>
            <div class="text-2xl font-extrabold tracking-tight text-white font-mono">
                ${{ number_format($stats['total_cost_today'], $stats['total_cost_today'] > 0 && $stats['total_cost_today'] < 0.01 ? 5 : 4) }}
            </div>
            <div class="flex items-center gap-1 text-xs text-zinc-500 mt-2.5 font-mono">
                <span>Based on model rates</span>
            </div>
        </div>

        <!-- Card 5: Money Saved -->
        <div class="bg-[#121215] border border-white/[0.07] hover:border-white/[0.15] rounded-2xl p-5 shadow-lg relative overflow-hidden transition-all group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-emerald-400 uppercase tracking-wider">Cost Saved</span>
                <div class="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                    <i data-lucide="piggy-bank" class="w-4 h-4"></i>
                </div>
            </div>
            <div class="text-2xl font-extrabold tracking-tight text-emerald-400 font-mono">
                +${{ number_format($stats['total_cost_saved_today'], $stats['total_cost_saved_today'] > 0 && $stats['total_cost_saved_today'] < 0.01 ? 5 : 4) }}
            </div>
            <div class="flex items-center gap-1 text-xs text-emerald-500/80 mt-2.5 font-mono">
                <span>Zero-spend cache reuse</span>
            </div>
        </div>

        <!-- Card 6: Speed & Health -->
        <div class="bg-[#121215] border border-white/[0.07] hover:border-white/[0.15] rounded-2xl p-5 shadow-lg relative overflow-hidden transition-all group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-zinc-400 uppercase tracking-wider">Latency</span>
                <div class="w-8 h-8 rounded-lg bg-zinc-800/80 border border-white/5 flex items-center justify-center text-amber-400">
                    <i data-lucide="gauge" class="w-4 h-4"></i>
                </div>
            </div>
            <div class="text-2xl font-extrabold tracking-tight text-white font-mono">
                {{ $stats['avg_latency_ms'] }} <span class="text-xs font-normal text-zinc-400">ms</span>
            </div>
            <div class="flex items-center gap-2 text-xs mt-2.5 font-mono">
                <span class="text-emerald-400 font-medium">{{ $stats['active_accounts_count'] }} Healthy</span>
                <span class="text-zinc-600">/</span>
                <span class="{{ $stats['cooldown_accounts_count'] > 0 ? 'text-rose-400 font-medium' : 'text-zinc-500' }}">{{ $stats['cooldown_accounts_count'] }} Cooldown</span>
            </div>
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- VISUAL ANALYTICS COMMAND CENTER (APEXCHARTS) -->
    <!-- ============================================================== -->
    <div x-data="analyticsDashboard()" class="space-y-6">
        <!-- Control Bar: Title & Time Filter Switcher -->
        <div class="flex items-center justify-between flex-wrap gap-4 bg-[#121215] border border-white/[0.07] rounded-2xl p-4 sm:p-5 shadow-lg">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
                    <i data-lucide="bar-chart-2" class="w-5 h-5"></i>
                </div>
                <div>
                    <h2 class="text-base font-bold text-white flex items-center gap-2">
                        <span>Gateway Visual Analytics & Workload Distribution</span>
                        <span x-show="loading" class="inline-block animate-spin text-xs text-indigo-400">&#9696;</span>
                    </h2>
                    <p class="text-xs text-zinc-400">Real-time throughput, token efficiency, and provider latency distribution.</p>
                </div>
            </div>

            <!-- Time Range Pills -->
            <div class="inline-flex items-center p-1 rounded-xl bg-[#09090b] border border-white/5 font-mono text-xs">
                <button type="button" @click="changePeriod('today')"
                    :class="period === 'today' ? 'bg-indigo-600 text-white font-bold shadow-md' : 'text-zinc-400 hover:text-white'"
                    class="px-3.5 py-1.5 rounded-lg transition-all cursor-pointer">
                    Today
                </button>
                <button type="button" @click="changePeriod('7d')"
                    :class="period === '7d' ? 'bg-indigo-600 text-white font-bold shadow-md' : 'text-zinc-400 hover:text-white'"
                    class="px-3.5 py-1.5 rounded-lg transition-all cursor-pointer">
                    Last 7 Days
                </button>
                <button type="button" @click="changePeriod('30d')"
                    :class="period === '30d' ? 'bg-indigo-600 text-white font-bold shadow-md' : 'text-zinc-400 hover:text-white'"
                    class="px-3.5 py-1.5 rounded-lg transition-all cursor-pointer">
                    30 Days
                </button>
            </div>
        </div>

        <!-- Row 1: Token Usage Chart (2/3) + Provider Donut Chart (1/3) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Chart 1: Token Throughput & Savings -->
            <div class="lg:col-span-2 bg-[#121215] border border-white/[0.07] rounded-2xl p-5 sm:p-6 shadow-lg space-y-3">
                <div class="flex items-center justify-between border-b border-white/[0.06] pb-3">
                    <div class="flex items-center gap-2">
                        <i data-lucide="zap" class="w-4.5 h-4.5 text-emerald-400"></i>
                        <h3 class="text-sm font-bold text-white uppercase tracking-wider">Token Throughput & Prompt Savings</h3>
                    </div>
                    <div class="flex items-center gap-3 text-xs font-mono">
                        <span class="inline-flex items-center gap-1.5 text-indigo-400">
                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                            <span>Prompt</span>
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-emerald-400">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            <span>Completion</span>
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-cyan-400">
                            <span class="w-2 h-2 rounded-full bg-cyan-400"></span>
                            <span>Saved</span>
                        </span>
                    </div>
                </div>

                <div class="relative min-h-[300px]">
                    <div id="chart-token-usage" class="w-full"></div>
                </div>
            </div>

            <!-- Chart 2: Provider Traffic Share Donut -->
            <div class="bg-[#121215] border border-white/[0.07] rounded-2xl p-5 sm:p-6 shadow-lg space-y-3">
                <div class="flex items-center justify-between border-b border-white/[0.06] pb-3">
                    <div class="flex items-center gap-2">
                        <i data-lucide="pie-chart" class="w-4.5 h-4.5 text-indigo-400"></i>
                        <h3 class="text-sm font-bold text-white uppercase tracking-wider">Provider Traffic Share</h3>
                    </div>
                    <span class="text-xs font-mono px-2 py-0.5 rounded bg-zinc-800 text-zinc-300" x-text="totalRequests + ' Requests'"></span>
                </div>

                <div class="relative min-h-[300px] flex items-center justify-center">
                    <div id="chart-provider-donut" class="w-full"></div>
                </div>
            </div>
        </div>

        <!-- Row 2: Latency & Error Rate Trend -->
        <div class="bg-[#121215] border border-white/[0.07] rounded-2xl p-5 sm:p-6 shadow-lg space-y-3">
            <div class="flex items-center justify-between border-b border-white/[0.06] pb-3">
                <div class="flex items-center gap-2">
                    <i data-lucide="activity" class="w-4.5 h-4.5 text-amber-400"></i>
                    <h3 class="text-sm font-bold text-white uppercase tracking-wider">Gateway Latency & Reliability Trend</h3>
                </div>
                <div class="flex items-center gap-3 text-xs font-mono">
                    <span class="inline-flex items-center gap-1.5 text-amber-400">
                        <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                        <span>Avg Latency (ms)</span>
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-rose-400">
                        <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                        <span>Errors (4xx / 5xx)</span>
                    </span>
                </div>
            </div>

            <div class="relative min-h-[260px]">
                <div id="chart-latency-trend" class="w-full"></div>
            </div>
        </div>
    </div>

    <!-- 2-Column Split: Active Provider Matrix (2/3) + Quick Integration (1/3) -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6" x-data="{
        testingAccountId: null,
        testResult: null,
        async pingAccount(accountId) {
            this.testingAccountId = accountId;
            this.testResult = null;
            try {
                const res = await fetch(`/dashboard/accounts/${accountId}/test`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json'
                    }
                });
                const data = await res.json();
                this.testResult = { id: accountId, ...data };
                if (data.success) {
                    $dispatch('show-toast', { message: data.message });
                }
                setTimeout(() => { this.testResult = null; }, 5000);
            } catch (err) {
                this.testResult = { id: accountId, success: false, message: err.message };
            } finally {
                this.testingAccountId = null;
                this.$nextTick(() => lucide.createIcons());
            }
        }
    }">
        <!-- Left 2/3: Active Provider Health Matrix -->
        <div class="lg:col-span-2 bg-[#121215] border border-white/[0.07] rounded-2xl p-6 shadow-lg space-y-4">
            <div class="flex items-center justify-between border-b border-white/[0.06] pb-3.5">
                <h3 class="text-base font-bold text-white flex items-center gap-2.5">
                    <span>Active Provider Health Matrix</span>
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span>
                </h3>
                <a href="{{ route('dashboard.providers') }}" class="text-sm text-indigo-400 hover:text-indigo-300 font-medium flex items-center gap-1.5">
                    <span>Manage All Providers</span>
                    <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>

            @php
                $healthAccounts = isset($healthMatrixAccounts) 
                    ? $healthMatrixAccounts 
                    : \App\Models\ProviderAccount::with('provider')->latest()->take(10)->get();
            @endphp

            @if($healthAccounts->isEmpty())
                <div class="py-12 text-center space-y-3.5">
                    <div class="w-14 h-14 rounded-2xl bg-zinc-800/80 border border-white/5 mx-auto flex items-center justify-center text-zinc-400">
                        <i data-lucide="key" class="w-7 h-7"></i>
                    </div>
                    <div class="text-base text-zinc-200 font-semibold">No provider keys connected yet</div>
                    <p class="text-sm text-zinc-400 max-w-sm mx-auto">Add your free Groq, Gemini, OpenRouter, or OpenAI API key to start routing requests through Passwire.</p>
                    <button type="button" @click="openAddKeyModal()" class="px-5 py-2.5 bg-emerald-500 text-white font-semibold text-sm rounded-xl shadow-lg hover:bg-emerald-400 transition-colors inline-flex items-center gap-2">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        <span>Connect Your First Key</span>
                    </button>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($healthAccounts as $acc)
                        @php
                            $provider = $acc->provider;
                        @endphp
                        <div class="p-4 rounded-xl bg-[#0e0e11] border border-white/[0.05] hover:border-white/[0.1] transition-all flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3.5 min-w-0">
                                <!-- Provider Tag -->
                                <div class="w-9 h-9 rounded-xl bg-zinc-800 border border-white/10 flex items-center justify-center font-bold text-xs text-emerald-400 flex-shrink-0">
                                    {{ strtoupper(substr($provider?->slug ?? 'AI', 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold text-white truncate">{{ $acc->label }}</span>
                                        <span class="text-xs font-mono text-zinc-400 bg-zinc-800/80 px-2 py-0.5 rounded-md border border-white/5">{{ $provider?->slug ?? 'ai' }}</span>
                                        <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-zinc-800 text-indigo-300">P{{ $acc->priority }}</span>
                                    </div>
                                    <div class="text-xs text-zinc-400 font-mono mt-1">
                                        Tokens today: <span class="text-zinc-200 font-medium">{{ number_format($acc->tokens_used_today) }}</span>
                                        @if($acc->daily_token_limit)
                                            / {{ number_format($acc->daily_token_limit) }}
                                        @endif
                                    </div>
                                    @php
                                        $quotaPct = $acc->daily_token_limit ? min(100, round(($acc->tokens_used_today / $acc->daily_token_limit) * 100)) : min(100, round(($acc->tokens_used_today / 50000) * 100));
                                    @endphp
                                    <div class="mt-1.5 flex items-center gap-2.5">
                                        <div class="w-28 sm:w-40 bg-zinc-800 h-2 rounded-full overflow-hidden">
                                            <div class="h-full bg-emerald-500 rounded-full transition-all duration-300" style="width: {{ max(4, $quotaPct) }}%"></div>
                                        </div>
                                        <span class="text-xs text-zinc-400 font-mono">{{ $quotaPct }}%</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 flex-shrink-0">
                                <!-- Status Badge -->
                                @if($acc->isInCooldown())
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-500/10 text-red-400 border border-red-500/20">
                                        <span class="w-1.5 h-1.5 rounded-full bg-red-400 animate-ping"></span>
                                        Cooldown ({{ $acc->cooldown_until->diffForHumans() }})
                                    </span>
                                    <form action="{{ route('dashboard.accounts.clear-cooldown', $acc) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs text-emerald-400 hover:underline font-semibold">Reset</button>
                                    </form>
                                @elseif(!$acc->is_active)
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-zinc-800 text-zinc-400 border border-zinc-700">
                                        Disabled
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                        Ready
                                    </span>
                                @endif

                                <!-- Quick Ping Button -->
                                <button type="button" @click="pingAccount({{ $acc->id }})"
                                    :disabled="testingAccountId === {{ $acc->id }}"
                                    class="px-3 py-1.5 text-xs font-semibold bg-zinc-800 hover:bg-zinc-700 text-zinc-200 rounded-xl border border-white/5 transition-colors disabled:opacity-50">
                                    <span x-text="testingAccountId === {{ $acc->id }} ? 'Testing...' : 'Test'">Test</span>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Right 1/3: Quick Integration & Combos -->
        <div class="space-y-6">
            <!-- Integration Snippet -->
            <div class="bg-[#121215] border border-white/[0.07] rounded-2xl p-6 shadow-lg space-y-3.5" x-data="{ intTab: 'cursor' }">
                <div class="flex items-center justify-between border-b border-white/[0.06] pb-3">
                    <h3 class="text-xs font-bold text-zinc-400 uppercase tracking-wider">Quick Integration</h3>
                    <div class="flex items-center gap-1 bg-[#09090b] p-0.5 rounded-lg border border-white/5 text-xs">
                        <button type="button" @click="intTab = 'cursor'" :class="intTab === 'cursor' ? 'bg-zinc-800 text-white font-medium' : 'text-zinc-500 hover:text-zinc-300'" class="px-2.5 py-1 rounded-md transition-colors">Cursor</button>
                        <button type="button" @click="intTab = 'python'" :class="intTab === 'python' ? 'bg-zinc-800 text-white font-medium' : 'text-zinc-500 hover:text-zinc-300'" class="px-2.5 py-1 rounded-md transition-colors">Python</button>
                        <button type="button" @click="intTab = 'js'" :class="intTab === 'js' ? 'bg-zinc-800 text-white font-medium' : 'text-zinc-500 hover:text-zinc-300'" class="px-2.5 py-1 rounded-md transition-colors">Node/JS</button>
                        <button type="button" @click="intTab = 'curl'" :class="intTab === 'curl' ? 'bg-zinc-800 text-white font-medium' : 'text-zinc-500 hover:text-zinc-300'" class="px-2.5 py-1 rounded-md transition-colors">cURL</button>
                    </div>
                </div>

                <div x-show="intTab === 'cursor'" class="bg-[#09090b] rounded-xl p-3.5 border border-white/5 text-xs font-mono space-y-2 text-zinc-300">
                    <div class="text-zinc-500">// Cursor IDE -> Settings -> Models:</div>
                    <div><span class="text-emerald-400">Base URL:</span> {{ url('/v1') }}</div>
                    <div><span class="text-emerald-400">API Key:</span> {{ $gatewayKeys->first()?->key ?? 'sk-pw-master-key-...' }}</div>
                    <div><span class="text-emerald-400">Model:</span> auto</div>
                </div>

                <div x-show="intTab === 'python'" class="bg-[#09090b] rounded-xl p-3.5 border border-white/5 text-xs font-mono text-zinc-300 overflow-x-auto" x-cloak>
<pre>client = OpenAI(
  base_url="{{ url('/v1') }}",
  api_key="{{ $gatewayKeys->first()?->key ?? 'sk-pw-...' }}"
)
res = client.chat.completions.create(
  model="auto",
  messages=[{"role":"user","content":"Hi!"}]
)</pre>
                </div>

                <div x-show="intTab === 'js'" class="bg-[#09090b] rounded-xl p-3.5 border border-white/5 text-xs font-mono text-zinc-300 overflow-x-auto" x-cloak>
<pre>import OpenAI from 'openai';

const client = new OpenAI({
  baseURL: '{{ url('/v1') }}',
  apiKey: '{{ $gatewayKeys->first()?->key ?? 'sk-pw-...' }}',
});
const res = await client.chat.completions.create({
  model: 'auto',
  messages: [{ role: 'user', content: 'Hi!' }]
});</pre>
                </div>

                <div x-show="intTab === 'curl'" class="bg-[#09090b] rounded-xl p-3.5 border border-white/5 text-xs font-mono text-zinc-300 overflow-x-auto" x-cloak>
<pre>curl {{ url('/v1/chat/completions') }} \
 -H "Authorization: Bearer {{ $gatewayKeys->first()?->key ?? 'sk-pw-...' }}" \
 -d '{"model":"auto","messages":[{"role":"user","content":"Hi"}]}'</pre>
                </div>
            </div>

            <!-- Active Smart Combos List -->
            <div class="bg-[#121215] border border-white/[0.07] rounded-2xl p-6 shadow-lg space-y-3.5">
                <div class="flex items-center justify-between border-b border-white/[0.06] pb-3">
                    <h3 class="text-xs font-bold text-zinc-400 uppercase tracking-wider">Active Smart Combos</h3>
                    <a href="{{ route('dashboard.combos') }}" class="text-xs text-indigo-400 hover:text-indigo-300 font-medium">View All</a>
                </div>

                <div class="space-y-2.5">
                    @foreach($combos->take(3) as $c)
                        <div class="p-3 rounded-xl bg-[#09090b] border border-white/5 text-xs flex items-center justify-between">
                            <div>
                                <div class="font-bold text-white text-sm">{{ $c->name }}</div>
                                <div class="text-xs text-zinc-400 font-mono mt-0.5">{{ count($c->models ?? []) }} model fallback sequence</div>
                            </div>
                            <span class="text-xs font-semibold px-2.5 py-1 rounded-md bg-zinc-800 text-indigo-300 font-mono">{{ $c->strategy }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function analyticsDashboard() {
    return {
        period: 'today',
        loading: false,
        totalRequests: 0,
        tokenChart: null,
        providerChart: null,
        latencyChart: null,

        async init() {
            await this.loadData();
        },

        async changePeriod(newPeriod) {
            if (this.period === newPeriod || this.loading) return;
            this.period = newPeriod;
            await this.loadData();
        },

        async loadData() {
            this.loading = true;
            try {
                const res = await fetch(`{{ route('dashboard.analytics.charts') }}?period=${this.period}`);
                const data = await res.json();
                this.totalRequests = data.provider_chart?.total_requests || 0;
                this.renderOrUpdateCharts(data);
            } catch (err) {
                console.error('Failed to load chart analytics:', err);
            } finally {
                this.loading = false;
                this.$nextTick(() => lucide.createIcons());
            }
        },

        renderOrUpdateCharts(data) {
            const isDark = document.documentElement.classList.contains('dark');
            const themeMode = isDark ? 'dark' : 'light';
            const gridBorderColor = isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.06)';
            const fontColor = isDark ? '#a1a1aa' : '#52525b';

            // 1. Token Throughput & Savings Chart
            const tokenOptions = {
                series: [
                    { name: 'Prompt Tokens', data: data.token_chart.prompt_tokens },
                    { name: 'Completion Tokens', data: data.token_chart.completion_tokens },
                    { name: 'Saved Tokens', data: data.token_chart.saved_tokens }
                ],
                chart: {
                    type: 'area',
                    height: 290,
                    fontFamily: 'Inter, sans-serif',
                    toolbar: { show: false },
                    background: 'transparent',
                    zoom: { enabled: false }
                },
                theme: { mode: themeMode },
                colors: ['#6366f1', '#10b981', '#06b6d4'],
                dataLabels: { enabled: false },
                stroke: { curve: 'smooth', width: 2 },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.35,
                        opacityTo: 0.05,
                        stops: [0, 95, 100]
                    }
                },
                grid: {
                    borderColor: gridBorderColor,
                    strokeDashArray: 3,
                    padding: { left: 10, right: 10, top: 0, bottom: 0 }
                },
                xaxis: {
                    categories: data.token_chart.categories,
                    labels: { style: { colors: fontColor, fontSize: '11px', fontFamily: 'JetBrains Mono, monospace' } },
                    axisBorder: { show: false },
                    axisTicks: { show: false }
                },
                yaxis: {
                    labels: {
                        style: { colors: fontColor, fontSize: '11px', fontFamily: 'JetBrains Mono, monospace' },
                        formatter: function(val) {
                            if (val >= 1000000) return (val / 1000000).toFixed(1) + 'M';
                            if (val >= 1000) return (val / 1000).toFixed(0) + 'k';
                            return val;
                        }
                    }
                },
                tooltip: {
                    theme: themeMode,
                    y: {
                        formatter: function(val) {
                            return new Intl.NumberFormat().format(val) + ' tokens';
                        }
                    }
                },
                legend: { show: false }
            };

            if (!this.tokenChart) {
                const el = document.getElementById('chart-token-usage');
                if (el) {
                    this.tokenChart = new ApexCharts(el, tokenOptions);
                    this.tokenChart.render();
                }
            } else {
                this.tokenChart.updateOptions(tokenOptions);
            }

            // 2. Provider Donut Chart
            const providerOptions = {
                series: data.provider_chart.series,
                labels: data.provider_chart.labels,
                chart: {
                    type: 'donut',
                    height: 290,
                    fontFamily: 'Inter, sans-serif',
                    background: 'transparent'
                },
                theme: { mode: themeMode },
                colors: ['#10b981', '#6366f1', '#06b6d4', '#f59e0b', '#ec4899', '#8b5cf6', '#14b8a6', '#f43f5e'],
                stroke: { width: 2, colors: [isDark ? '#121215' : '#ffffff'] },
                dataLabels: { enabled: false },
                legend: {
                    position: 'bottom',
                    labels: { colors: fontColor },
                    fontSize: '11px',
                    fontFamily: 'JetBrains Mono, monospace'
                },
                plotOptions: {
                    pie: {
                        donut: {
                            size: '72%',
                            labels: {
                                show: true,
                                name: { show: true, fontSize: '12px', color: fontColor },
                                value: {
                                    show: true,
                                    fontSize: '20px',
                                    fontWeight: 'bold',
                                    color: isDark ? '#ffffff' : '#09090b',
                                    formatter: (val) => val + ' reqs'
                                },
                                total: {
                                    show: true,
                                    label: 'Traffic Split',
                                    color: fontColor,
                                    fontSize: '11px',
                                    formatter: () => this.totalRequests + ' total'
                                }
                            }
                        }
                    }
                },
                tooltip: {
                    theme: themeMode,
                    y: {
                        formatter: function(val) {
                            return val + ' requests';
                        }
                    }
                }
            };

            if (!this.providerChart) {
                const el = document.getElementById('chart-provider-donut');
                if (el) {
                    this.providerChart = new ApexCharts(el, providerOptions);
                    this.providerChart.render();
                }
            } else {
                this.providerChart.updateOptions(providerOptions);
            }

            // 3. Latency & Error Rate Trend
            const latencyOptions = {
                series: [
                    { name: 'Avg Latency (ms)', type: 'area', data: data.latency_chart.latencies },
                    { name: 'Errors (4xx/5xx)', type: 'column', data: data.latency_chart.errors }
                ],
                chart: {
                    height: 250,
                    fontFamily: 'Inter, sans-serif',
                    toolbar: { show: false },
                    background: 'transparent'
                },
                theme: { mode: themeMode },
                colors: ['#f59e0b', '#ef4444'],
                stroke: { width: [2.5, 0], curve: 'smooth' },
                fill: {
                    type: ['gradient', 'solid'],
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.3,
                        opacityTo: 0.05,
                        stops: [0, 95, 100]
                    }
                },
                grid: {
                    borderColor: gridBorderColor,
                    strokeDashArray: 3
                },
                xaxis: {
                    categories: data.latency_chart.categories,
                    labels: { style: { colors: fontColor, fontSize: '11px', fontFamily: 'JetBrains Mono, monospace' } },
                    axisBorder: { show: false },
                    axisTicks: { show: false }
                },
                yaxis: [
                    {
                        title: { text: 'Latency (ms)', style: { color: fontColor, fontSize: '10px' } },
                        labels: {
                            style: { colors: fontColor, fontSize: '11px', fontFamily: 'JetBrains Mono, monospace' },
                            formatter: (val) => Math.round(val) + 'ms'
                        }
                    },
                    {
                        opposite: true,
                        title: { text: 'Errors', style: { color: fontColor, fontSize: '10px' } },
                        labels: {
                            style: { colors: fontColor, fontSize: '11px', fontFamily: 'JetBrains Mono, monospace' },
                            formatter: (val) => Math.round(val)
                        }
                    }
                ],
                tooltip: {
                    theme: themeMode,
                    shared: true,
                    intersect: false
                },
                legend: { show: false }
            };

            if (!this.latencyChart) {
                const el = document.getElementById('chart-latency-trend');
                if (el) {
                    this.latencyChart = new ApexCharts(el, latencyOptions);
                    this.latencyChart.render();
                }
            } else {
                this.latencyChart.updateOptions(latencyOptions);
            }
        }
    };
}
</script>
@endpush
