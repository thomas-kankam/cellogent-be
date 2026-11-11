<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * api_keys.token now holds sha256(plain token). Existing keys keep working:
 * their plaintext is hashed in place, and a short prefix is kept for display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('token_prefix', 16)->nullable()->after('token');
        });

        DB::table('api_keys')
            ->where('token', 'like', 'cgk\_%')
            ->orderBy('id')
            ->chunkById(500, function ($keys) {
                foreach ($keys as $key) {
                    DB::table('api_keys')->where('id', $key->id)->update([
                        'token' => hash('sha256', $key->token),
                        'token_prefix' => substr($key->token, 0, 12),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('token_prefix');
        });
    }
};
