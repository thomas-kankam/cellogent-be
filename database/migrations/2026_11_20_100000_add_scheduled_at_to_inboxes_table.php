<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inboxes', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('status');
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('inboxes', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn('scheduled_at');
        });
    }
};
