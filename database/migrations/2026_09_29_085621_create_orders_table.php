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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('event_id')->constrained('events');
            $table->foreignId('discount_code_id')->nullable()->constrained('discount_codes');
            $table->char('currency', 3);
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->enum('status', [
                'pending',
                'paid',
                'expired',
                'failed',
                'refunded',
                'partially_refunded',
            ])->default('pending');
            $table->string('failure_reason')->nullable();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_refund_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        // One pending order per user per event (enforced by the database)
        DB::statement(
            "CREATE UNIQUE INDEX orders_one_pending_per_event
            ON orders (customer_id, event_id) WHERE status = 'pending'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
