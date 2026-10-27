<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\GatewayKey;
use App\Models\GatewayLog;
use App\Models\ProviderAccount;
use App\Models\RoutingCombo;
use App\Services\Gateway\OmniRouterService;
use App\Services\Gateway\ResponseCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    /**
     * Create a new AdminDashboardController instance.
     */
    public function __construct(
        protected OmniRouterService $router,
        protected ResponseCacheService $cacheService
    ) {}

    /**
     * Display the Overview & Analytics page.
     */
    public function overview(): View
    {
        $today = today();

        $stats = [
            'total_requests_today' => GatewayLog::whereDate('created_at', $today)->count(),
            'total_tokens_today' => (int) GatewayLog::whereDate('created_at', $today)->sum('total_tokens'),
            'total_saved_tokens' => (int) GatewayLog::whereDate('created_at', $today)->sum('saved_tokens'),
            'total_cost_today' => (float) GatewayLog::whereDate('created_at', $today)->sum('cost_usd'),
            'total_cost_saved_today' => (float) GatewayLog::whereDate('created_at', $today)->sum('cost_saved_usd'),
            'avg_latency_ms' => (int) round(GatewayLog::whereDate('created_at', $today)->where('status_code', 200)->avg('latency_ms') ?? 0),
            'active_accounts_count' => ProviderAccount::where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now());
                })->count(),
            'cooldown_accounts_count' => ProviderAccount::where('is_active', true)
                ->whereNotNull('cooldown_until')
                ->where('cooldown_until', '>', now())
                ->count(),
            'cached_requests_today' => GatewayLog::whereDate('created_at', $today)->where('is_cached', true)->count(),
            'redacted_pii_today' => (int) GatewayLog::whereDate('created_at', $today)->sum('redacted_pii_count'),
            'cache_stats' => $this->cacheService->getHitStats(),
        ];

        $providers = AiProvider::with([
            'accounts' => fn ($q) => $q->orderBy('priority', 'asc')->orderBy('tokens_used_today', 'asc')
        ])->get();

        $healthMatrixAccounts = ProviderAccount::with('provider')
            ->latest()
            ->take(10)
            ->get();

        $combos = RoutingCombo::all();
        $gatewayKeys = GatewayKey::latest()->get();

        return view('dashboard.overview', compact(
            'stats',
            'providers',
            'combos',
            'gatewayKeys',
            'healthMatrixAccounts'
        ));
    }

    /**
     * Display AI Providers & Key Pool page.
     */
    public function providers(): View
    {
        $providers = AiProvider::with([
            'accounts' => fn ($q) => $q->orderBy('priority', 'asc')->orderBy('tokens_used_today', 'asc')
        ])->get();

        return view('dashboard.providers', compact('providers'));
    }

    /**
     * Display Smart Combos & Fallback Router page.
     */
    public function combos(): View
    {
        $combos = RoutingCombo::all();
        $providers = AiProvider::with('accounts')->where('is_active', true)->get();

        return view('dashboard.combos', compact('combos', 'providers'));
    }

    /**
     * Display Gateway Client API Keys page.
     */
    public function gatewayKeys(): View
    {
        $gatewayKeys = GatewayKey::latest()->get();

        return view('dashboard.gateway-keys', compact('gatewayKeys'));
    }

    /**
     * Display Live AI Studio Playground page.
     */
    public function playground(): View
    {
        $combos = RoutingCombo::all();
        $providers = AiProvider::with([
            'accounts' => fn ($q) => $q->orderBy('priority', 'asc')
        ])->where('is_active', true)->get();

        return view('dashboard.playground', compact('combos', 'providers'));
    }

    /**
     * Display Request Telemetry & Error Logs page.
     */
    public function logs(): View
    {
        $logs = GatewayLog::with('gatewayKey')->latest()->take(100)->get();

        return view('dashboard.logs', compact('logs'));
    }

    /**
     * Display Website / Branding Settings page (only Site Settings).
     */
    public function settings(): View
    {
        $siteSettings = \App\Models\SiteSetting::getSettings();

        return view('dashboard.settings', compact('siteSettings'));
    }

    /**
     * Display Alerts & Webhooks page (only Alert Settings).
     */
    public function alerts(): View
    {
        $alertSettings = \App\Models\AlertSetting::getSettings();

        return view('dashboard.alerts', compact('alertSettings'));
    }

    /**
     * Update General Website / Branding Settings.
     */
    public function updateSiteSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'site_title' => 'required|string|max:100',
            'version' => 'required|string|max:20',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,svg,webp',
            'logo_dark' => 'nullable|image|mimes:png,jpg,jpeg,svg,webp',
            'logo_light' => 'nullable|image|mimes:png,jpg,jpeg,svg,webp',
            'favicon' => 'nullable|mimes:png,jpg,jpeg,svg,webp,ico',
            'remove_logo' => 'nullable|boolean',
            'remove_logo_dark' => 'nullable|boolean',
            'remove_logo_light' => 'nullable|boolean',
            'remove_favicon' => 'nullable|boolean',
        ]);

        $settings = \App\Models\SiteSetting::getSettings();

        $data = [
            'site_title' => $validated['site_title'],
            'version' => $validated['version'],
        ];

        if ($request->boolean('remove_logo') && $settings->logo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($settings->logo_path);
            $data['logo_path'] = null;
        }

        if ($request->boolean('remove_logo_dark') && $settings->logo_dark_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($settings->logo_dark_path);
            $data['logo_dark_path'] = null;
        }

        if ($request->boolean('remove_logo_light') && $settings->logo_light_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($settings->logo_light_path);
            $data['logo_light_path'] = null;
        }

        if ($request->boolean('remove_favicon') && $settings->favicon_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($settings->favicon_path);
            $data['favicon_path'] = null;
        }

        if ($request->hasFile('logo')) {
            if ($settings->logo_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($settings->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('branding', 'public');
        }

        if ($request->hasFile('logo_dark')) {
            if ($settings->logo_dark_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($settings->logo_dark_path);
            }
            $data['logo_dark_path'] = $request->file('logo_dark')->store('branding', 'public');
        }

        if ($request->hasFile('logo_light')) {
            if ($settings->logo_light_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($settings->logo_light_path);
            }
            $data['logo_light_path'] = $request->file('logo_light')->store('branding', 'public');
        }

        if ($request->hasFile('favicon')) {
            if ($settings->favicon_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($settings->favicon_path);
            }
            $data['favicon_path'] = $request->file('favicon')->store('branding', 'public');
        }

        $settings->update($data);

        return back()->with('success', 'Website settings updated successfully.');
    }

    /**
     * Update Real-time Failover Alert Webhook Settings.
     */
    public function updateAlertSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'is_enabled' => 'nullable|boolean',
            'webhook_type' => 'required|in:discord,slack,telegram,generic',
            'webhook_url' => 'nullable|url|max:1000',
            'telegram_bot_token' => 'nullable|string|max:255',
            'telegram_chat_id' => 'nullable|string|max:100',
            'alert_circuit_breaker' => 'nullable|boolean',
            'alert_account_cooldown' => 'nullable|boolean',
            'alert_key_limit_exceeded' => 'nullable|boolean',
        ]);

        $settings = \App\Models\AlertSetting::getSettings();
        $settings->update([
            'is_enabled' => $request->boolean('is_enabled'),
            'webhook_type' => $validated['webhook_type'],
            'webhook_url' => $validated['webhook_url'] ?? null,
            'telegram_bot_token' => $validated['telegram_bot_token'] ?? null,
            'telegram_chat_id' => $validated['telegram_chat_id'] ?? null,
            'alert_circuit_breaker' => $request->boolean('alert_circuit_breaker'),
            'alert_account_cooldown' => $request->boolean('alert_account_cooldown'),
            'alert_key_limit_exceeded' => $request->boolean('alert_key_limit_exceeded'),
        ]);

        return back()->with('success', 'Real-time alert notification settings updated successfully.');
    }

    /**
     * Send a test alert notification to the configured webhook channel.
     */
    public function testAlertWebhook(\App\Services\Notification\AlertNotificationService $alertService): JsonResponse
    {
        $settings = \App\Models\AlertSetting::getSettings();
        $result = $alertService->sendTestNotification($settings);

        return response()->json($result);
    }

    /**
     * Provide aggregated visual analytics data for ApexCharts on Overview page.
     */
    public function chartAnalytics(Request $request): JsonResponse
    {
        $period = $request->query('period', 'today');
        $now = now();

        if ($period === '7d') {
            $startDate = $now->copy()->subDays(6)->startOfDay();
            $dateKeys = [];
            $categories = [];
            for ($i = 6; $i >= 0; $i--) {
                $dt = $now->copy()->subDays($i);
                $key = $dt->format('Y-m-d');
                $dateKeys[$key] = [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'saved_tokens' => 0,
                    'latency_sum' => 0,
                    'latency_count' => 0,
                    'errors' => 0,
                ];
                $categories[$key] = $dt->format('M d');
            }
            $groupBy = 'day';
        } elseif ($period === '30d') {
            $startDate = $now->copy()->subDays(29)->startOfDay();
            $dateKeys = [];
            $categories = [];
            for ($i = 29; $i >= 0; $i--) {
                $dt = $now->copy()->subDays($i);
                $key = $dt->format('Y-m-d');
                $dateKeys[$key] = [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'saved_tokens' => 0,
                    'latency_sum' => 0,
                    'latency_count' => 0,
                    'errors' => 0,
                ];
                $categories[$key] = $dt->format('M d');
            }
            $groupBy = 'day';
        } else {
            $period = 'today';
            $startDate = $now->copy()->startOfDay();
            $dateKeys = [];
            $categories = [];
            for ($h = 0; $h < 24; $h++) {
                $key = sprintf('%02d:00', $h);
                $dateKeys[$key] = [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'saved_tokens' => 0,
                    'latency_sum' => 0,
                    'latency_count' => 0,
                    'errors' => 0,
                ];
                $categories[$key] = $key;
            }
            $groupBy = 'hour';
        }

        $logs = GatewayLog::where('created_at', '>=', $startDate)->get([
            'prompt_tokens',
            'completion_tokens',
            'saved_tokens',
            'latency_ms',
            'status_code',
            'routed_provider',
            'created_at',
        ]);

        $providerCounts = [];

        foreach ($logs as $log) {
            $key = $groupBy === 'hour'
                ? $log->created_at->format('H:00')
                : $log->created_at->format('Y-m-d');

            if (isset($dateKeys[$key])) {
                $dateKeys[$key]['prompt_tokens'] += (int) $log->prompt_tokens;
                $dateKeys[$key]['completion_tokens'] += (int) $log->completion_tokens;
                $dateKeys[$key]['saved_tokens'] += (int) $log->saved_tokens;

                if ($log->status_code === 200 && $log->latency_ms > 0) {
                    $dateKeys[$key]['latency_sum'] += (int) $log->latency_ms;
                    $dateKeys[$key]['latency_count']++;
                }

                if ($log->status_code >= 400) {
                    $dateKeys[$key]['errors']++;
                }
            }

            $provider = !empty($log->routed_provider) ? $log->routed_provider : 'direct/combo';
            $providerCounts[$provider] = ($providerCounts[$provider] ?? 0) + 1;
        }

        $catList = array_values($categories);
        $promptList = [];
        $completionList = [];
        $savedList = [];
        $latencyList = [];
        $errorList = [];

        foreach ($dateKeys as $item) {
            $promptList[] = $item['prompt_tokens'];
            $completionList[] = $item['completion_tokens'];
            $savedList[] = $item['saved_tokens'];
            $avgLat = $item['latency_count'] > 0 ? (int) round($item['latency_sum'] / $item['latency_count']) : 0;
            $latencyList[] = $avgLat;
            $errorList[] = $item['errors'];
        }

        arsort($providerCounts);
        $providerLabels = !empty($providerCounts) ? array_keys($providerCounts) : ['No Traffic'];
        $providerSeries = !empty($providerCounts) ? array_values($providerCounts) : [0];

        return response()->json([
            'period' => $period,
            'token_chart' => [
                'categories' => $catList,
                'prompt_tokens' => $promptList,
                'completion_tokens' => $completionList,
                'saved_tokens' => $savedList,
            ],
            'provider_chart' => [
                'labels' => $providerLabels,
                'series' => $providerSeries,
                'total_requests' => $logs->count(),
            ],
            'latency_chart' => [
                'categories' => $catList,
                'latencies' => $latencyList,
                'errors' => $errorList,
            ],
        ]);
    }

    /**
     * Alias for overview page.
     */
    public function index(): View
    {
        return $this->overview();
    }

    /**
     * Add a custom AI provider.
     */
    public function storeProvider(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100|unique:ai_providers,slug',
            'base_url' => 'required|string|url|max:255',
            'format' => 'required|in:openai,anthropic,gemini',
            'description' => 'nullable|string|max:3000',
            'models_catalog' => 'nullable|string',
        ]);

        $catalog = [];
        if (!empty($validated['models_catalog'])) {
            $catalog = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $validated['models_catalog']))));
        }

        $baseSlug = Str::slug($validated['name']);
        if (empty($baseSlug)) {
            $baseSlug = 'provider';
        }
        $slug = $baseSlug;
        $counter = 1;
        while (AiProvider::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        AiProvider::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'base_url' => $validated['base_url'],
            'format' => $validated['format'],
            'description' => $validated['description'] ?? null,
            'models_catalog' => $catalog,
            'is_active' => true,
        ]);

        return back()->with('success', "Provider '{$validated['name']}' created successfully.");
    }

    /**
     * Update an AI provider's details and info.
     */
    public function updateProvider(Request $request, AiProvider $provider): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'base_url' => 'sometimes|required|url|max:255',
            'format' => 'sometimes|required|in:openai,anthropic,gemini',
            'description' => 'nullable|string|max:3000',
        ]);

        if (!empty($validated['name'])) {
            $provider->name = $validated['name'];
        }
        if (!empty($validated['base_url'])) {
            $provider->base_url = $validated['base_url'];
        }
        if (!empty($validated['format'])) {
            $provider->format = $validated['format'];
        }
        if ($request->has('description')) {
            $provider->description = $validated['description'];
        }

        $provider->save();

        return back()->with('success', "Provider '{$provider->name}' updated successfully.");
    }

    /**
     * Delete an AI provider.
     */
    public function deleteProvider(AiProvider $provider): RedirectResponse
    {
        $name = $provider->name;
        $provider->delete();

        return back()->with('success', "Provider '{$name}' deleted successfully.");
    }

    /**
     * Add a new provider account API key.
     */
    public function storeAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ai_provider_id' => 'required|exists:ai_providers,id',
            'label' => 'required|string|max:255',
            'api_key' => 'required|string',
            'priority' => 'nullable|integer|min:1',
            'daily_token_limit' => 'nullable|integer|min:1',
        ]);

        $account = ProviderAccount::create([
            'ai_provider_id' => $validated['ai_provider_id'],
            'label' => $validated['label'],
            'api_key' => $validated['api_key'],
            'priority' => $validated['priority'] ?? 1,
            'daily_token_limit' => $validated['daily_token_limit'] ?? null,
            'is_active' => true,
        ]);

        $provider = $account->provider;
        $syncNotice = '';
        if ($provider) {
            $beforeCount = count($provider->models_catalog ?? []);
            $models = $this->syncProviderModels($provider, $validated['api_key']);
            $newCount = count($models) - $beforeCount;
            if ($newCount > 0) {
                $syncNotice = " Auto-synced {$newCount} new models into catalog.";
            }
        }

        return back()->with('success', "Account '{$validated['label']}' added successfully.{$syncNotice}");
    }

    /**
     * Toggle provider account active status.
     */
    public function toggleAccount(ProviderAccount $account): RedirectResponse
    {
        $account->update(['is_active' => !$account->is_active]);

        $status = $account->is_active ? 'activated' : 'deactivated';
        return back()->with('success', "Account '{$account->label}' {$status}.");
    }

    /**
     * Clear cooldown and fail count on an account.
     */
    public function clearCooldown(ProviderAccount $account, Request $request): JsonResponse|RedirectResponse
    {
        $account->update([
            'cooldown_until' => null,
            'fail_count' => 0,
            'last_error' => null,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Cooldown and fail counters reset for '{$account->label}'.",
            ]);
        }

        return back()->with('success', "Cooldown and fail counters reset for '{$account->label}'.");
    }

    /**
     * Delete an account.
     */
    public function deleteAccount(ProviderAccount $account): RedirectResponse
    {
        $account->delete();

        return back()->with('success', "Account '{$account->label}' deleted.");
    }

    /**
     * Test an account with a quick ping and auto-sync models catalog.
     */
    public function testAccount(ProviderAccount $account): JsonResponse
    {
        $provider = $account->provider;

        // Auto-sync available models list from upstream provider
        $beforeCount = count($provider->models_catalog ?? []);
        $models = $this->syncProviderModels($provider, $account->api_key);
        $newCount = count($models) - $beforeCount;

        $provider->refresh();
        $adapter = app(\App\Services\Gateway\Adapters\ProviderAdapterFactory::class)->make($provider->format);
        $model = (!empty($provider->models_catalog) && is_array($provider->models_catalog))
            ? $provider->models_catalog[0]
            : ($provider->format === 'anthropic' ? 'claude-3-5-haiku-latest' : ($provider->format === 'gemini' ? 'gemini-1.5-flash' : 'gpt-4o-mini'));

        $startTime = microtime(true);

        try {
            $url = $adapter->buildUrl($provider->base_url, $model, false, $account->api_key);
            $headers = $adapter->formatHeaders($account->api_key, false);
            $body = $adapter->formatPayload($model, [
                'messages' => [['role' => 'user', 'content' => 'Say OK']],
                'max_tokens' => 5,
            ]);

            $response = Http::withHeaders($headers)->timeout(12)->post($url, $body);

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $totalModels = count($provider->models_catalog ?? []);
                $syncMsg = $newCount > 0 
                    ? " • Synced {$newCount} new models ({$totalModels} total in catalog)" 
                    : ($totalModels > 0 ? " • {$totalModels} models ready" : "");

                return response()->json([
                    'success' => true,
                    'message' => "Success (200 OK) with model '{$model}'{$syncMsg}",
                    'latency_ms' => $latencyMs,
                    'models_count' => $totalModels,
                    'models' => $provider->models_catalog ?? [],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => "HTTP {$response->status()}: " . substr($response->body(), 0, 250),
                'latency_ms' => $latencyMs,
            ]);
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'latency_ms' => $latencyMs,
            ]);
        }
    }

    /**
     * Display dedicated Provider Detail page (Connections & Available Models).
     */
    public function providerDetail(string $providerSlug): View
    {
        $provider = AiProvider::where('slug', $providerSlug)
            ->orWhere('id', $providerSlug)
            ->with(['accounts' => fn ($q) => $q->orderBy('priority', 'asc')->orderBy('tokens_used_today', 'asc')])
            ->firstOrFail();

        return view('dashboard.provider-detail', compact('provider'));
    }

    /**
     * Import models from upstream provider /models endpoint.
     */
    public function importModels(AiProvider $provider): JsonResponse
    {
        $account = $provider->accounts()->where('is_active', true)->first()
            ?? $provider->accounts()->first();

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'No configured API keys/credentials found for this provider. Please add a key/connection first.',
            ], 422);
        }

        $beforeCount = count($provider->models_catalog ?? []);
        $models = $this->syncProviderModels($provider, $account->api_key);
        $newCount = count($models) - $beforeCount;

        return response()->json([
            'success' => true,
            'message' => $newCount > 0 
                ? "Successfully imported {$newCount} new models! Total in catalog: " . count($models)
                : "Models catalog up to date (" . count($models) . " models available).",
            'models_count' => count($models),
            'new_count' => $newCount,
            'models' => $models,
        ]);
    }

    /**
     * Attempt to fetch and sync available models from an OpenAI or Anthropic provider.
     *
     * @param AiProvider $provider
     * @param string $apiKey
     * @return array<int, string>
     */
    protected function syncProviderModels(AiProvider $provider, string $apiKey): array
    {
        $baseUrl = rtrim($provider->base_url, '/');

        // Gemini native /models endpoint support
        if ($provider->format === 'gemini') {
            try {
                $modelsUrl = "{$baseUrl}/models?key={$apiKey}";
                $res = Http::withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'Accept' => 'application/json',
                ])->timeout(8)->get($modelsUrl);

                if ($res->successful()) {
                    $json = $res->json();
                    $modelList = [];
                    if (isset($json['models']) && is_array($json['models'])) {
                        foreach ($json['models'] as $item) {
                            $rawName = is_array($item) ? ($item['name'] ?? null) : null;
                            if ($rawName && is_string($rawName)) {
                                $modelId = str_starts_with($rawName, 'models/') ? substr($rawName, 7) : $rawName;
                                $modelList[] = $modelId;
                            }
                        }
                    }
                    if (!empty($modelList)) {
                        $existing = is_array($provider->models_catalog) ? $provider->models_catalog : [];
                        $merged = array_values(array_unique(array_merge($existing, $modelList)));
                        $provider->models_catalog = $merged;
                        $provider->save();

                        return $merged;
                    }
                }
            } catch (\Throwable $e) {
                // Silently fallback
            }

            return $provider->models_catalog ?? [];
        }

        // Anthropic /models endpoint support
        if ($provider->format === 'anthropic') {
            try {
                $modelsUrl = str_ends_with($baseUrl, '/messages')
                    ? substr($baseUrl, 0, -strlen('/messages')) . '/models'
                    : $baseUrl . '/models';

                $res = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Accept' => 'application/json',
                ])->timeout(8)->get($modelsUrl);

                if ($res->successful()) {
                    $json = $res->json();
                    $modelList = [];
                    if (isset($json['data']) && is_array($json['data'])) {
                        foreach ($json['data'] as $item) {
                            $id = is_array($item) ? ($item['id'] ?? null) : (is_string($item) ? $item : null);
                            if ($id && is_string($id)) {
                                $modelList[] = $id;
                            }
                        }
                    }
                    if (!empty($modelList)) {
                        $existing = is_array($provider->models_catalog) ? $provider->models_catalog : [];
                        $merged = array_values(array_unique(array_merge($existing, $modelList)));
                        $provider->models_catalog = $merged;
                        $provider->save();

                        return $merged;
                    }
                }
            } catch (\Throwable $e) {
                // Silently fallback
            }

            return $provider->models_catalog ?? [];
        }

        // OpenAI format /models endpoint support
        try {
            if (str_ends_with($baseUrl, '/chat/completions')) {
                $modelsUrl = substr($baseUrl, 0, -strlen('/chat/completions')) . '/models';
            } elseif (str_ends_with($baseUrl, '/models')) {
                $modelsUrl = $baseUrl;
            } else {
                $modelsUrl = $baseUrl . '/models';
            }

            $res = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ])->timeout(8)->get($modelsUrl);

            if ($res->successful()) {
                $json = $res->json();
                $modelList = [];

                if (isset($json['data']) && is_array($json['data'])) {
                    foreach ($json['data'] as $item) {
                        $id = is_array($item) ? ($item['id'] ?? null) : (is_string($item) ? $item : null);
                        if ($id && is_string($id)) {
                            $modelList[] = $id;
                        }
                    }
                } elseif (isset($json['models']) && is_array($json['models'])) {
                    foreach ($json['models'] as $item) {
                        $id = is_array($item) ? ($item['id'] ?? $item['name'] ?? null) : (is_string($item) ? $item : null);
                        if ($id && is_string($id)) {
                            $modelList[] = $id;
                        }
                    }
                } elseif (is_array($json) && !empty($json)) {
                    foreach ($json as $item) {
                        if (is_string($item)) {
                            $modelList[] = $item;
                        } elseif (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                            $modelList[] = $item['id'];
                        }
                    }
                }

                if (!empty($modelList)) {
                    $existing = is_array($provider->models_catalog) ? $provider->models_catalog : [];
                    $merged = array_values(array_unique(array_merge($existing, $modelList)));
                    $provider->models_catalog = $merged;
                    $provider->save();

                    return $merged;
                }
            }
        } catch (\Throwable $e) {
            // Silently fallback
        }

        return $provider->models_catalog ?? [];
    }

    /**
     * Create or update a RoutingCombo.
     */
    public function storeCombo(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => 'nullable|exists:routing_combos,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'strategy' => 'required|in:priority,round-robin',
            'models' => 'required',
            'compression_modes' => 'nullable|array',
        ]);

        if (is_string($validated['models'])) {
            $models = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $validated['models']))));
        } else {
            $models = is_array($validated['models']) ? $validated['models'] : [];
        }

        $data = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'strategy' => $validated['strategy'],
            'models' => $models,
            'compression_modes' => $validated['compression_modes'] ?? null,
            'is_active' => true,
        ];

        if (!empty($validated['id'])) {
            RoutingCombo::where('id', $validated['id'])->update($data);
        } else {
            RoutingCombo::updateOrCreate(['name' => $data['name']], $data);
        }

        return back()->with('success', "Routing combo '{$validated['name']}' saved.");
    }

    /**
     * Toggle combo active status.
     */
    public function toggleCombo(RoutingCombo $combo): RedirectResponse
    {
        $combo->update(['is_active' => !$combo->is_active]);

        $status = $combo->is_active ? 'activated' : 'deactivated';
        return back()->with('success', "Combo '{$combo->name}' {$status}.");
    }

    /**
     * Delete a routing combo.
     */
    public function deleteCombo(RoutingCombo $combo): RedirectResponse
    {
        $combo->delete();

        return back()->with('success', "Routing combo '{$combo->name}' deleted.");
    }

    /**
     * Generate a new master or client Gateway Key.
     */
    public function storeGatewayKey(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'daily_token_limit' => 'nullable|integer|min:1',
            'rpm_limit' => 'nullable|integer|min:1',
            'tpm_limit' => 'nullable|integer|min:1',
        ]);

        $key = 'sk-omni-' . bin2hex(random_bytes(16));

        GatewayKey::create([
            'name' => $validated['name'],
            'key' => $key,
            'daily_token_limit' => $validated['daily_token_limit'] ?? null,
            'rpm_limit' => $validated['rpm_limit'] ?? null,
            'tpm_limit' => $validated['tpm_limit'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('success', "Generated key '{$validated['name']}': {$key}");
    }

    /**
     * Update an existing Gateway Key.
     */
    public function updateGatewayKey(Request $request, GatewayKey $gatewayKey): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'daily_token_limit' => 'nullable|integer|min:1',
            'rpm_limit' => 'nullable|integer|min:1',
            'tpm_limit' => 'nullable|integer|min:1',
        ]);

        $gatewayKey->update([
            'name' => $validated['name'],
            'daily_token_limit' => $validated['daily_token_limit'] ?: null,
            'rpm_limit' => $validated['rpm_limit'] ?: null,
            'tpm_limit' => $validated['tpm_limit'] ?: null,
        ]);

        return back()->with('success', "Gateway key '{$gatewayKey->name}' updated successfully.");
    }

    /**
     * Toggle Gateway Key active status.
     */
    public function toggleGatewayKey(GatewayKey $gatewayKey): RedirectResponse
    {
        $gatewayKey->update(['is_active' => !$gatewayKey->is_active]);

        $status = $gatewayKey->is_active ? 'activated' : 'deactivated';
        return back()->with('success', "Gateway key '{$gatewayKey->name}' {$status}.");
    }

    /**
     * Delete a Gateway Key.
     */
    public function deleteGatewayKey(GatewayKey $gatewayKey): RedirectResponse
    {
        $gatewayKey->delete();

        return back()->with('success', "Gateway key '{$gatewayKey->name}' deleted.");
    }

    /**
     * Truncate the gateway_logs table.
     */
    public function clearLogs(): RedirectResponse
    {
        GatewayLog::truncate();

        return back()->with('success', 'Gateway logs cleared successfully.');
    }

    /**
     * Purge all gateway response cache items.
     */
    public function purgeCache(Request $request): JsonResponse|RedirectResponse
    {
        $count = $this->cacheService->purgeAll();

        if ($request->expectsJson() || $request->isXmlHttpRequest()) {
            return response()->json([
                'success' => true,
                'purged_count' => $count,
                'message' => "Gateway response cache purged successfully ({$count} entries removed).",
            ]);
        }

        return back()->with('success', "Gateway response cache purged ({$count} entries removed).");
    }

    /**
     * Live playground chat endpoint.
     */
    public function playgroundChat(Request $request): JsonResponse
    {
        $request->validate([
            'model' => 'required|string',
            'messages' => 'required|array|min:1',
        ]);

        try {
            $result = $this->router->dispatchSync($request->all());

            $response = $result['response'];
            $response['_omniroute_meta'] = $result['meta'];
            $response['meta'] = $result['meta'];
            $response['success'] = true;

            return response()->json($response);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                ],
                'message' => $e->getMessage(),
            ], 502);
        }
    }
}
