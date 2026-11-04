<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per send request (the original request, kept forever).
     * No foreign keys: ids are stored plainly and indexed for lookups.
     */
    public function up(): void
    {
        Schema::create('inboxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable()->index(); // re-blast / resend source
            $table->string('mode', 16)->default('send');                 // send, reblast, resend
            $table->string('request_type', 16);                          // api, system
            $table->string('type', 16);                                  // quick, bulk
            $table->text('to')->nullable();                              // quick: comma-separated numbers
            $table->string('from', 20);
            $table->text('message');
            $table->unsignedTinyInteger('parts')->default(1);
            $table->json('contact_group_ids')->nullable();               // bulk only
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedBigInteger('units_used')->default(0);
            $table->unsignedBigInteger('previous_units')->nullable();
            $table->unsignedBigInteger('units_refunded')->default(0);
            $table->unsignedInteger('submitted_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('batches_total')->default(0);
            $table->unsignedInteger('batches_done')->default(0);
            $table->string('aggregator', 32)->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('message_id', 64)->nullable()->index();       // aggregator id used for resend
            $table->string('status', 16)->default('queued');             // queued, processing, completed, failed
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
            $table->index(['client_id', 'type', 'created_at']);
            $table->index(['admin_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inboxes');
    }
};
