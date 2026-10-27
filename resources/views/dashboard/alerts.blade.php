@extends('layouts.dashboard')

@section('title', 'Alerts & Webhooks')

@section('content')
<div class="space-y-6">

    <!-- ============================================================== -->
    <!-- REAL-TIME FAILOVER ALERTS & WEBHOOKS CARD -->
    <!-- ============================================================== -->
    <div x-data="{
        webhookType: '{{ $alertSettings->webhook_type }}',
        sendingTest: false,
        testResult: null,
        async sendTest() {
            this.sendingTest = true;
            this.testResult = null;
            try {
                const res = await fetch('{{ route('dashboard.alerts.test') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json'
                    }
                });
                const data = await res.json();
                this.testResult = data;
                if (data.success) {
                    $dispatch('show-toast', { message: data.message });
                }
            } catch (err) {
                this.testResult = { success: false, message: 'Network error: ' + err.message };
            } finally {
                this.sendingTest = false;
                this.$nextTick(() => lucide.createIcons());
            }
        }
    }" class="bg-[#121215] border border-white/[0.07] rounded-2xl p-6 shadow-lg space-y-5">
        <div class="flex items-center justify-between border-b border-white/[0.06] pb-3.5">
            <div class="flex items-center gap-2.5">
                <i data-lucide="bell-ring" class="w-5 h-5 text-amber-400"></i>
                <h3 class="text-sm font-bold text-white uppercase tracking-wider">Real-Time Failover Alerts & Webhooks</h3>
            </div>
            <div class="flex items-center gap-2">
                @if($alertSettings->last_alert_sent_at)
                    <span class="text-xs text-zinc-500 font-mono hidden sm:inline">Last sent: {{ $alertSettings->last_alert_sent_at->diffForHumans() }}</span>
                @endif
                <span class="text-xs font-mono px-2.5 py-1 rounded-md font-semibold {{ $alertSettings->is_enabled ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-zinc-800 text-zinc-400 border border-white/5' }}">
                    {{ $alertSettings->is_enabled ? 'Alerts Active' : 'Alerts Disabled' }}
                </span>
            </div>
        </div>

        <!-- Test Result Banner -->
        <template x-if="testResult">
            <div :class="testResult.success ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' : 'bg-rose-500/10 border-rose-500/30 text-rose-300'"
                class="p-4 rounded-xl border text-xs sm:text-sm flex items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <i :data-lucide="testResult.success ? 'check-circle' : 'alert-triangle'" class="w-4.5 h-4.5 shrink-0"></i>
                    <span x-text="testResult.message"></span>
                </div>
                <button type="button" @click="testResult = null" class="text-zinc-400 hover:text-white text-lg">&times;</button>
            </div>
        </template>

        <form action="{{ route('dashboard.alerts.update') }}" method="POST" class="space-y-5">
            @csrf
            @method('PUT')

            <!-- Master Toggle -->
            <div class="p-4 rounded-xl bg-[#09090b] border border-white/5 flex items-center justify-between">
                <div>
                    <div class="text-sm font-bold text-white">Enable Real-Time Alerts</div>
                    <div class="text-xs text-zinc-400 mt-0.5">Activate background webhook dispatching across configured trigger events</div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="is_enabled" value="1" {{ $alertSettings->is_enabled ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-zinc-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                </label>
            </div>

            <!-- Webhook Channel Type Selector Cards -->
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-2">Notification Channel</label>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <!-- Discord -->
                    <label class="p-3.5 rounded-xl border cursor-pointer transition-all flex flex-col items-center text-center gap-2"
                        :class="webhookType === 'discord' ? 'bg-indigo-500/10 border-indigo-500 text-white' : 'bg-[#09090b] border-white/5 text-zinc-400 hover:border-white/10'">
                        <input type="radio" name="webhook_type" value="discord" x-model="webhookType" class="sr-only">
                        <i data-lucide="message-square" class="w-5 h-5" :class="webhookType === 'discord' ? 'text-indigo-400' : 'text-zinc-500'"></i>
                        <span class="text-xs font-bold">Discord</span>
                    </label>

                    <!-- Slack -->
                    <label class="p-3.5 rounded-xl border cursor-pointer transition-all flex flex-col items-center text-center gap-2"
                        :class="webhookType === 'slack' ? 'bg-emerald-500/10 border-emerald-500 text-white' : 'bg-[#09090b] border-white/5 text-zinc-400 hover:border-white/10'">
                        <input type="radio" name="webhook_type" value="slack" x-model="webhookType" class="sr-only">
                        <i data-lucide="hash" class="w-5 h-5" :class="webhookType === 'slack' ? 'text-emerald-400' : 'text-zinc-500'"></i>
                        <span class="text-xs font-bold">Slack</span>
                    </label>

                    <!-- Telegram -->
                    <label class="p-3.5 rounded-xl border cursor-pointer transition-all flex flex-col items-center text-center gap-2"
                        :class="webhookType === 'telegram' ? 'bg-sky-500/10 border-sky-500 text-white' : 'bg-[#09090b] border-white/5 text-zinc-400 hover:border-white/10'">
                        <input type="radio" name="webhook_type" value="telegram" x-model="webhookType" class="sr-only">
                        <i data-lucide="send" class="w-5 h-5" :class="webhookType === 'telegram' ? 'text-sky-400' : 'text-zinc-500'"></i>
                        <span class="text-xs font-bold">Telegram</span>
                    </label>

                    <!-- Generic Webhook -->
                    <label class="p-3.5 rounded-xl border cursor-pointer transition-all flex flex-col items-center text-center gap-2"
                        :class="webhookType === 'generic' ? 'bg-purple-500/10 border-purple-500 text-white' : 'bg-[#09090b] border-white/5 text-zinc-400 hover:border-white/10'">
                        <input type="radio" name="webhook_type" value="generic" x-model="webhookType" class="sr-only">
                        <i data-lucide="globe" class="w-5 h-5" :class="webhookType === 'generic' ? 'text-purple-400' : 'text-zinc-500'"></i>
                        <span class="text-xs font-bold">Generic HTTP</span>
                    </label>
                </div>
            </div>

            <!-- Dynamic Input: Discord / Slack / Generic Webhook URL -->
            <div x-show="webhookType !== 'telegram'" class="space-y-1.5">
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400">
                    <span x-text="webhookType === 'discord' ? 'Discord Webhook URL' : (webhookType === 'slack' ? 'Slack Webhook URL' : 'HTTP Webhook Endpoint')"></span>
                </label>
                <input type="url" name="webhook_url" value="{{ $alertSettings->webhook_url }}"
                    :placeholder="webhookType === 'discord' ? 'https://discord.com/api/webhooks/123456789/token...' : (webhookType === 'slack' ? 'https://hooks.slack.com/services/T.../B.../...' : 'https://api.yourdomain.com/webhooks/omniroute')"
                    class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-600 focus:outline-none focus:border-emerald-500 font-mono">
                <p class="text-xs text-zinc-500">Incoming payloads will be formatted specifically for the selected service.</p>
            </div>

            <!-- Dynamic Input: Telegram Bot Token & Chat ID -->
            <div x-show="webhookType === 'telegram'" class="grid grid-cols-1 sm:grid-cols-2 gap-4" x-cloak>
                <div class="space-y-1.5">
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400">Telegram Bot Token</label>
                    <input type="text" name="telegram_bot_token" value="{{ $alertSettings->telegram_bot_token }}" placeholder="123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ"
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-600 focus:outline-none focus:border-sky-500 font-mono">
                    <p class="text-xs text-zinc-500">Obtained from <a href="https://t.me/BotFather" target="_blank" class="text-sky-400 underline">@BotFather</a>.</p>
                </div>
                <div class="space-y-1.5">
                    <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400">Telegram Chat / Channel ID</label>
                    <input type="text" name="telegram_chat_id" value="{{ $alertSettings->telegram_chat_id }}" placeholder="-1001234567890 or @channelname"
                        class="w-full bg-[#09090b] border border-white/10 rounded-xl px-3.5 py-2.5 text-sm text-white placeholder-zinc-600 focus:outline-none focus:border-sky-500 font-mono">
                    <p class="text-xs text-zinc-500">Target chat or group ID where notifications will be sent.</p>
                </div>
            </div>

            <!-- Trigger Events Checklist -->
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-400 mb-2">Trigger Event Subscriptions</label>
                <div class="space-y-2.5 text-xs text-zinc-300">
                    <label class="flex items-center gap-3 p-3 rounded-xl bg-[#09090b] border border-white/5 cursor-pointer hover:border-white/10">
                        <input type="checkbox" name="alert_circuit_breaker" value="1" {{ $alertSettings->alert_circuit_breaker ? 'checked' : '' }}
                            class="rounded border-zinc-700 text-rose-500 focus:ring-rose-500">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                            <span class="font-bold text-white">Circuit Breaker Tripped:</span>
                            <span class="text-zinc-400">Immediate alert when 5 consecutive upstream failures suspend a provider for 1 minute.</span>
                        </div>
                    </label>

                    <label class="flex items-center gap-3 p-3 rounded-xl bg-[#09090b] border border-white/5 cursor-pointer hover:border-white/10">
                        <input type="checkbox" name="alert_account_cooldown" value="1" {{ $alertSettings->alert_account_cooldown ? 'checked' : '' }}
                            class="rounded border-zinc-700 text-amber-500 focus:ring-amber-500">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                            <span class="font-bold text-white">Account Rate-Limit Cooldown:</span>
                            <span class="text-zinc-400">Alert when an individual upstream API key hits HTTP 429 or quota depletion.</span>
                        </div>
                    </label>

                    <label class="flex items-center gap-3 p-3 rounded-xl bg-[#09090b] border border-white/5 cursor-pointer hover:border-white/10">
                        <input type="checkbox" name="alert_key_limit_exceeded" value="1" {{ $alertSettings->alert_key_limit_exceeded ? 'checked' : '' }}
                            class="rounded border-zinc-700 text-indigo-500 focus:ring-indigo-500">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-indigo-400"></span>
                            <span class="font-bold text-white">Gateway Client Key Limits:</span>
                            <span class="text-zinc-400">Alert when a client Gateway Key exceeds its daily token quota, RPM, or TPM limits.</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="pt-3 border-t border-white/[0.06] flex items-center justify-between gap-3 flex-wrap">
                <button type="button" @click="sendTest()" :disabled="sendingTest"
                    class="px-4 py-2.5 rounded-xl border border-white/10 hover:border-white/20 text-zinc-300 hover:text-white font-semibold text-xs transition-colors flex items-center gap-2 cursor-pointer disabled:opacity-40">
                    <i data-lucide="zap" class="w-3.5 h-3.5 text-amber-400" x-show="!sendingTest"></i>
                    <span x-show="sendingTest" class="inline-block animate-spin">&#9696;</span>
                    <span x-text="sendingTest ? 'Sending Test...' : 'Send Test Notification'"></span>
                </button>

                <button type="submit"
                    class="btn-primary px-5 py-2.5 text-white font-bold text-xs rounded-xl transition-all shadow-md flex items-center gap-2 cursor-pointer">
                    <i data-lucide="save" class="w-3.5 h-3.5"></i>
                    <span>Save Alert Settings</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
