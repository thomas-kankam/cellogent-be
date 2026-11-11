<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('utility_orders', 'product_type')) {
                $table->string('product_type', 32)->nullable()->after('service_type')->index();
            }

            if (! Schema::hasColumn('utility_orders', 'batch_id')) {
                $table->string('batch_id', 64)->nullable()->after('reference')->index();
            }

            if (! Schema::hasColumn('utility_orders', 'cost_amount')) {
                $table->decimal('cost_amount', 12, 2)->default(0)->after('amount');
            }

            if (! Schema::hasColumn('utility_orders', 'sell_amount')) {
                $table->decimal('sell_amount', 12, 2)->default(0)->after('cost_amount');
            }

            if (! Schema::hasColumn('utility_orders', 'profit_amount')) {
                $table->decimal('profit_amount', 12, 2)->default(0)->after('sell_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('utility_orders', function (Blueprint $table) {
            $table->dropColumn(['product_type', 'batch_id', 'cost_amount', 'sell_amount', 'profit_amount']);
        });
    }
};
