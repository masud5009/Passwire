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
            $table->unsignedInteger('redacted_pii_count')->default(0)->after('is_cached');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gateway_logs', function (Blueprint $table) {
            $table->dropColumn('redacted_pii_count');
        });
    }
};
