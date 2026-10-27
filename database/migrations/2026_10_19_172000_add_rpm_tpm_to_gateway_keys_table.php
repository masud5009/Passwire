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
        Schema::table('gateway_keys', function (Blueprint $table) {
            $table->unsignedInteger('rpm_limit')->nullable()->after('daily_token_limit');
            $table->unsignedBigInteger('tpm_limit')->nullable()->after('rpm_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gateway_keys', function (Blueprint $table) {
            $table->dropColumn(['rpm_limit', 'tpm_limit']);
        });
    }
};
