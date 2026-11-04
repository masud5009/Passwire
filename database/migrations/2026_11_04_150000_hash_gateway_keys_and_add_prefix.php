<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add key_hash and key_prefix columns
        Schema::table('gateway_keys', function (Blueprint $table) {
            $table->string('key_hash', 64)->nullable()->after('name');
            $table->string('key_prefix', 32)->nullable()->after('key_hash');
        });

        // 2. Backfill existing gateway keys with SHA-256 hash and masked prefix
        $rows = DB::table('gateway_keys')->get();
        foreach ($rows as $row) {
            if (!empty($row->key)) {
                $rawKey = $row->key;
                $hash = hash('sha256', $rawKey);
                $prefix = str_starts_with($rawKey, 'sk-pw-')
                    ? 'sk-pw-' . substr($rawKey, 6, 4) . '…'
                    : (strlen($rawKey) > 8 ? substr($rawKey, 0, 8) . '…' : $rawKey . '…');

                DB::table('gateway_keys')->where('id', $row->id)->update([
                    'key_hash' => $hash,
                    'key_prefix' => $prefix,
                ]);
            }
        }

        // 3. Drop plaintext key column and add unique constraint to key_hash
        Schema::table('gateway_keys', function (Blueprint $table) {
            $table->dropColumn('key');
            $table->unique('key_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gateway_keys', function (Blueprint $table) {
            $table->string('key', 64)->nullable()->after('name');
        });

        $rows = DB::table('gateway_keys')->get();
        foreach ($rows as $row) {
            DB::table('gateway_keys')->where('id', $row->id)->update([
                'key' => $row->key_prefix ?? ('sk-pw-' . bin2hex(random_bytes(16))),
            ]);
        }

        Schema::table('gateway_keys', function (Blueprint $table) {
            $table->unique('key');
            $table->dropUnique(['key_hash']);
            $table->dropColumn(['key_hash', 'key_prefix']);
        });
    }
};
