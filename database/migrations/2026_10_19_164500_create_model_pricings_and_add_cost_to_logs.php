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
        Schema::create('model_pricings', function (Blueprint $table) {
            $table->id();
            $table->string('model_pattern')->unique();
            $table->string('display_name')->nullable();
            $table->decimal('input_cost_per_million', 10, 4)->default(0.1500);
            $table->decimal('output_cost_per_million', 10, 4)->default(0.6000);
            $table->string('currency', 10)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('gateway_logs', function (Blueprint $table) {
            $table->decimal('cost_usd', 10, 6)->default(0.000000)->after('saved_tokens');
            $table->decimal('cost_saved_usd', 10, 6)->default(0.000000)->after('cost_usd');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gateway_logs', function (Blueprint $table) {
            $table->dropColumn(['cost_usd', 'cost_saved_usd']);
        });

        Schema::dropIfExists('model_pricings');
    }
};
