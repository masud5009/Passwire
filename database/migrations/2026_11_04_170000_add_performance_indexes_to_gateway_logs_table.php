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
        Schema::table('gateway_logs', function (Blueprint $table) {
            // 1. Single index on created_at for fast date filtering, ordering (latest), analytics and pruning
            $table->index('created_at', 'gateway_logs_created_at_idx');

            // 2. Composite index on (gateway_key_id, created_at) for key-scoped logs retrieval
            $table->index(['gateway_key_id', 'created_at'], 'gateway_logs_gateway_key_id_created_at_idx');

            // 3. Composite index on (status_code, created_at) for health matrix and error rate calculations
            $table->index(['status_code', 'created_at'], 'gateway_logs_status_code_created_at_idx');

            // 4. Composite index on (routed_model, created_at) for model-specific performance telemetry
            $table->index(['routed_model', 'created_at'], 'gateway_logs_routed_model_created_at_idx');

            // 5. Covering composite index for lowest-latency routing strategy (status_code = 200, routed_model, created_at)
            $table->index(['status_code', 'routed_model', 'created_at'], 'gateway_logs_latency_strategy_idx');

            // 6. Composite index on (created_at, is_cached) for daily cache hit calculations
            $table->index(['created_at', 'is_cached'], 'gateway_logs_created_at_is_cached_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gateway_logs', function (Blueprint $table) {
            $table->dropIndex('gateway_logs_created_at_idx');
            $table->dropIndex('gateway_logs_status_code_created_at_idx');
            $table->dropIndex('gateway_logs_routed_model_created_at_idx');
            $table->dropIndex('gateway_logs_latency_strategy_idx');
            $table->dropIndex('gateway_logs_created_at_is_cached_idx');
            $table->dropIndex('gateway_logs_gateway_key_id_created_at_idx');
        });
    }
};
