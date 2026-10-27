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
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('base_url');
            $table->string('format')->default('openai');
            $table->json('models_catalog')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('label');
            $table->text('api_key');
            $table->integer('priority')->default(1);
            $table->bigInteger('daily_token_limit')->nullable();
            $table->bigInteger('tokens_used_today')->default(0);
            $table->date('usage_reset_date')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->integer('fail_count')->default(0);
            $table->text('last_error')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('routing_combos', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->string('strategy')->default('priority');
            $table->json('models');
            $table->json('compression_modes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('gateway_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key', 64)->unique();
            $table->bigInteger('daily_token_limit')->nullable();
            $table->bigInteger('tokens_used_today')->default(0);
            $table->date('usage_reset_date')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('gateway_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_key_id')->nullable()->constrained('gateway_keys')->nullOnDelete();
            $table->string('requested_model');
            $table->string('routed_provider')->nullable();
            $table->string('routed_model')->nullable();
            $table->string('account_label')->nullable();
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->integer('saved_tokens')->default(0);
            $table->integer('latency_ms')->default(0);
            $table->integer('status_code')->default(200);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gateway_logs');
        Schema::dropIfExists('gateway_keys');
        Schema::dropIfExists('routing_combos');
        Schema::dropIfExists('provider_accounts');
        Schema::dropIfExists('ai_providers');
    }
};
