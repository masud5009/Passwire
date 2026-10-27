<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('alert_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->string('webhook_type')->default('discord'); // discord, slack, telegram, generic
            $table->text('webhook_url')->nullable();
            $table->string('telegram_bot_token')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->boolean('alert_circuit_breaker')->default(true);
            $table->boolean('alert_account_cooldown')->default(true);
            $table->boolean('alert_key_limit_exceeded')->default(true);
            $table->timestamp('last_alert_sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_settings');
    }
};
