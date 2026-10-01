<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('customer_id')->constrained('users');
            $table->enum('status', ['pending', 'approved', 'rejected', 'processing_refund', 'refund_failed'])
                ->default('pending');
            $table->text('reason')->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('stripe_refund_id')->nullable();
            $table->timestamps();
        });
        DB::statement(
            "CREATE UNIQUE INDEX refund_one_open_per_order
            ON refund_requests (order_id)
            WHERE status IN ('pending', 'processing_refund', 'refund_failed')"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
