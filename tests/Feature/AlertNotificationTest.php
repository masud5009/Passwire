<?php

namespace Tests\Feature;

use App\Models\AlertSetting;
use App\Models\User;
use App\Services\Notification\AlertNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlertNotificationTest extends TestCase
{
    use DatabaseTransactions;
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_discord_webhook_dispatches_with_embeds(): void
    {
        Http::fake([
            'https://discord.com/api/webhooks/*' => Http::response(['id' => '123'], 204),
        ]);

        $settings = AlertSetting::getSettings();
        $settings->update([
            'is_enabled' => true,
            'webhook_type' => 'discord',
            'webhook_url' => 'https://discord.com/api/webhooks/test/token',
            'alert_circuit_breaker' => true,
        ]);

        $service = new AlertNotificationService();
        $sent = $service->notifyCircuitBreakerTripped('openai', '5 consecutive failures on account key-1');

        $this->assertTrue($sent);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_contains($request->url(), 'discord.com/api/webhooks')
                && isset($data['embeds'])
                && str_contains($data['embeds'][0]['title'], 'Circuit Breaker Tripped')
                && $data['embeds'][0]['color'] === 15158332; // Red color
        });
    }

    public function test_slack_webhook_dispatches_with_attachments(): void
    {
        Http::fake([
            'https://hooks.slack.com/services/*' => Http::response('ok', 200),
        ]);

        $settings = AlertSetting::getSettings();
        $settings->update([
            'is_enabled' => true,
            'webhook_type' => 'slack',
            'webhook_url' => 'https://hooks.slack.com/services/T00/B00/XXX',
            'alert_account_cooldown' => true,
        ]);

        $service = new AlertNotificationService();
        $sent = $service->notifyAccountCooldown('Primary Key', 'gemini', 429, 'Rate limit exceeded');

        $this->assertTrue($sent);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_contains($request->url(), 'hooks.slack.com')
                && isset($data['attachments'])
                && $data['attachments'][0]['color'] === '#f59e0b'; // Amber warning color
        });
    }

    public function test_telegram_webhook_dispatches_to_bot_api(): void
    {
        Http::fake([
            'https://api.telegram.org/bot123456:TEST_TOKEN/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        $settings = AlertSetting::getSettings();
        $settings->update([
            'is_enabled' => true,
            'webhook_type' => 'telegram',
            'telegram_bot_token' => '123456:TEST_TOKEN',
            'telegram_chat_id' => '-100987654321',
            'alert_key_limit_exceeded' => true,
        ]);

        $service = new AlertNotificationService();
        $sent = $service->notifyGatewayKeyLimitExceeded('Client Production Key', 'RPM', '60 RPM exceeded');

        $this->assertTrue($sent);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_contains($request->url(), 'api.telegram.org/bot123456:TEST_TOKEN/sendMessage')
                && $data['chat_id'] === '-100987654321'
                && str_contains($data['text'], 'Client Production Key');
        });
    }

    public function test_alerts_are_not_sent_when_disabled(): void
    {
        Http::fake();

        $settings = AlertSetting::getSettings();
        $settings->update([
            'is_enabled' => false,
            'webhook_type' => 'discord',
            'webhook_url' => 'https://discord.com/api/webhooks/test/token',
        ]);

        $service = new AlertNotificationService();
        $sent = $service->notifyCircuitBreakerTripped('openai', 'Reason');

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }

    public function test_admin_can_update_alert_settings_and_send_test_notification(): void
    {
        Http::fake([
            'https://discord.com/api/webhooks/*' => Http::response(null, 204),
        ]);

        $admin = User::first() ?? User::factory()->create([
            'email' => 'admin@omniroute.local',
            'is_admin' => true,
        ]);

        // 1. Update settings
        $response = $this->actingAs($admin)->from('/dashboard/settings')->put('/dashboard/settings/alerts', [
            'is_enabled' => '1',
            'webhook_type' => 'discord',
            'webhook_url' => 'https://discord.com/api/webhooks/999/token123',
            'alert_circuit_breaker' => '1',
            'alert_account_cooldown' => '1',
            'alert_key_limit_exceeded' => '1',
        ]);

        $response->assertRedirect('/dashboard/settings');

        $settings = AlertSetting::getSettings();
        $this->assertTrue($settings->is_enabled);
        $this->assertEquals('discord', $settings->webhook_type);
        $this->assertEquals('https://discord.com/api/webhooks/999/token123', $settings->webhook_url);

        // 2. Test alert via endpoint
        $testResponse = $this->actingAs($admin)->postJson('/dashboard/settings/alerts/test');
        $testResponse->assertStatus(200);
        $testResponse->assertJsonPath('success', true);
    }
}
