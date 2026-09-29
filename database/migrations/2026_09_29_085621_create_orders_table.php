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

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('event_id')->constrained('events');
            $table->decimal('total_amount', 10, 2);
            $table->enum('status', [
                'pending',
                'paid',
                'expired',
                'failed',
                'refunded',
                'partially_refunded',
            ])->default('pending');
            $table->string('stripe_payment_intent_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
