<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('type', 32)->nullable()->after('status')->index();
        });

        // Existing orders already store the purpose on their first item.
        if (Schema::hasTable('order_items')) {
            DB::table('orders')
                ->whereNull('type')
                ->orderBy('id')
                ->chunkById(500, function ($orders) {
                    foreach ($orders as $order) {
                        $type = DB::table('order_items')
                            ->where('order_id', $order->id)
                            ->whereNotNull('type')
                            ->value('type');

                        if ($type) {
                            DB::table('orders')->where('id', $order->id)->update(['type' => $type]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
