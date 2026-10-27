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
        Schema::table('site_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('site_settings', 'logo_dark_path')) {
                $table->string('logo_dark_path')->nullable()->after('logo_path');
            }
            if (! Schema::hasColumn('site_settings', 'logo_light_path')) {
                $table->string('logo_light_path')->nullable()->after('logo_dark_path');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['logo_dark_path', 'logo_light_path']);
        });
    }
};
