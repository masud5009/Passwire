<?php

namespace App\Services\Notification;

use App\Models\AlertSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlertNotificationService
{
    /**
     * Send a real-time failover alert notification to the configured webhook channel.
     *
     * @param string $title
     * @param string $message
     * @param string $level 'danger'|'warning'|'info'|'success'
     * @param array<string, mixed> $fields
     * @param string $eventType 'circuit_breaker'|'account_cooldown'|'key_limit_exceeded'|'test'
     * @param bool $force Bypass enabled checks (e.g. for test button)
     */
    public function sendAlert(
        string $title,
        string $message,
        string $level = 'danger',
        array $fields = [],
        string $eventType = 'circuit_breaker',
        bool $force = false
    ): bool {
        $settings = AlertSetting::getSettings();

        if (!$force) {
            if (!$settings->is_enabled) {
                return false;
            }

            // Check event type toggles
            if ($eventType === 'circuit_breaker' && !$settings->alert_circuit_breaker) {
                return false;
            }
            if ($eventType === 'account_cooldown' && !$settings->alert_account_cooldown) {
                return false;
            }
            if ($eventType === 'key_limit_exceeded' && !$settings->alert_key_limit_exceeded) {
                return false;
            }

            // Anti-spam debounce: do not send identical alert within 60 seconds
            $debounceKey = "alert_debounce:{$eventType}:" . md5($title . json_encode($fields));
            if (Cache::has($debounceKey)) {
                return false;
            }
            Cache::put($debounceKey, true, now()->addSeconds(60));
        }

        try {
            $success = match ($settings->webhook_type) {
                'discord' => $this->sendDiscordWebhook($settings->webhook_url, $title, $message, $level, $fields),
                'slack' => $this->sendSlackWebhook($settings->webhook_url, $title, $message, $level, $fields),
                'telegram' => $this->sendTelegramMessage(
                    $settings->telegram_bot_token,
                    $settings->telegram_chat_id,
                    $title,
                    $message,
                    $fields
                ),
                default => $this->sendGenericWebhook($settings->webhook_url, $eventType, $title, $message, $level, $fields),
            };

            if ($success) {
                $settings->update(['last_alert_sent_at' => now()]);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning("Passwire Webhook Alert failed: " . $e->getMessage(), [
                'type' => $settings->webhook_type,
                'title' => $title,
            ]);

            return false;
        }
    }

    /**
     * Dispatch Discord Webhook with rich Embeds.
     */
    protected function sendDiscordWebhook(?string $url, string $title, string $message, string $level, array $fields): bool
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $color = match ($level) {
            'danger' => 15158332, // Red #ef4444
            'warning' => 16753920, // Amber #f59e0b
            'success' => 3066993,  // Emerald #10b981
            default => 6323595,    // Slate #64748b
        };

        $embedFields = [];
        foreach ($fields as $key => $val) {
            $embedFields[] = [
                'name' => (string) $key,
                'value' => (string) $val,
                'inline' => true,
            ];
        }

        $payload = [
            'username' => 'Passwire AI Gateway',
            'embeds' => [
                [
                    'title' => $title,
                    'description' => $message,
                    'color' => $color,
                    'fields' => $embedFields,
                    'footer' => [
                        'text' => 'Passwire • Gateway Alert Monitor',
                    ],
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ];

        $response = Http::timeout(3)->post($url, $payload);
        return $response->successful();
    }

    /**
     * Dispatch Slack Incoming Webhook.
     */
    protected function sendSlackWebhook(?string $url, string $title, string $message, string $level, array $fields): bool
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $color = match ($level) {
            'danger' => '#ef4444',
            'warning' => '#f59e0b',
            'success' => '#10b981',
            default => '#64748b',
        };

        $attachmentFields = [];
        foreach ($fields as $key => $val) {
            $attachmentFields[] = [
                'title' => (string) $key,
                'value' => (string) $val,
                'short' => true,
            ];
        }

        $payload = [
            'text' => "{$title}\n{$message}",
            'attachments' => [
                [
                    'color' => $color,
                    'fields' => $attachmentFields,
                    'footer' => 'Passwire Failover Monitor',
                    'ts' => time(),
                ],
            ],
        ];

        $response = Http::timeout(3)->post($url, $payload);
        return $response->successful();
    }

    /**
     * Dispatch Telegram Bot sendMessage API.
     */
    protected function sendTelegramMessage(?string $botToken, ?string $chatId, string $title, string $message, array $fields): bool
    {
        if (empty($botToken) || empty($chatId)) {
            return false;
        }

        $lines = [
            "*Passwire Alert*",
            "*" . str_replace('*', '', $title) . "*",
            str_replace('_', '\_', $message),
        ];

        if (!empty($fields)) {
            $lines[] = "";
            foreach ($fields as $k => $v) {
                $lines[] = "• *{$k}*: `{$v}`";
            }
        }

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $response = Http::timeout(3)->post($url, [
            'chat_id' => $chatId,
            'text' => implode("\n", $lines),
            'parse_mode' => 'Markdown',
        ]);

        return $response->successful();
    }

    /**
     * Dispatch Generic Webhook JSON payload.
     */
    protected function sendGenericWebhook(?string $url, string $eventType, string $title, string $message, string $level, array $fields): bool
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $payload = [
            'event' => $eventType,
            'title' => $title,
            'message' => $message,
            'level' => $level,
            'fields' => $fields,
            'timestamp' => now()->toIso8601String(),
        ];

        $response = Http::timeout(3)->post($url, $payload);
        return $response->successful();
    }

    /**
     * Send alert when a provider's circuit breaker trips.
     */
    public function notifyCircuitBreakerTripped(string $providerSlug, string $reason): bool
    {
        return $this->sendAlert(
            "🚨 Circuit Breaker Tripped: {$providerSlug}",
            "Provider '{$providerSlug}' has tripped its circuit breaker. Upstream calls to this provider are temporarily suspended for 1 minute.",
            'danger',
            [
                'Provider' => $providerSlug,
                'Status' => 'Circuit Open (1m Cooldown)',
                'Reason' => substr($reason, 0, 200),
            ],
            'circuit_breaker'
        );
    }

    /**
     * Send alert when an upstream provider account enters cooldown.
     */
    public function notifyAccountCooldown(string $accountLabel, string $providerSlug, int $statusCode, string $reason): bool
    {
        return $this->sendAlert(
            "⚠️ API Account Cooldown: {$accountLabel}",
            "Upstream account '{$accountLabel}' ({$providerSlug}) received HTTP {$statusCode} and was placed into automatic cooldown.",
            'warning',
            [
                'Account' => $accountLabel,
                'Provider' => $providerSlug,
                'HTTP Status' => (string) $statusCode,
                'Details' => substr($reason, 0, 200),
            ],
            'account_cooldown'
        );
    }

    /**
     * Send alert when a client Gateway Key exceeds quota or rate limits.
     */
    public function notifyGatewayKeyLimitExceeded(string $keyName, string $limitType, string $details): bool
    {
        return $this->sendAlert(
            "🛑 Client Key Quota Exceeded: {$keyName}",
            "Client Gateway Key '{$keyName}' has exceeded its {$limitType} limit.",
            'warning',
            [
                'Key Name' => $keyName,
                'Limit Type' => $limitType,
                'Details' => substr($details, 0, 200),
            ],
            'key_limit_exceeded'
        );
    }

    /**
     * Send a test alert notification to verify webhook connectivity.
     *
     * @return array{success: bool, message: string}
     */
    public function sendTestNotification(AlertSetting $settings): array
    {
        $type = strtoupper($settings->webhook_type);

        $sent = $this->sendAlert(
            "🧪 Passwire Webhook Test Notification",
            "This is a test notification from your Passwire AI Gateway console. Your {$type} webhook alert channel is functioning properly!",
            'success',
            [
                'Channel' => $type,
                'Status' => 'Connected & Operational',
                'Time' => now()->toTimeString(),
            ],
            'test',
            true // Force test bypasses is_enabled
        );

        if ($sent) {
            return [
                'success' => true,
                'message' => "Test alert successfully sent to {$type}!",
            ];
        }

        return [
            'success' => false,
            'message' => "Failed to deliver test alert to {$type}. Please verify your Webhook URL or Bot Token & Chat ID.",
        ];
    }
}
