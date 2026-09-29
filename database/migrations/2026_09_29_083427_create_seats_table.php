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
        Schema::create('seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_tier_id')->constrained('ticket_tiers');
            $table->string('row_label');
            $table->unsignedInteger('seat_number');
            $table->enum('status', ['available', 'held', 'booked'])->default('available');
            $table->timestamps();

            $table->unique(['ticket_tier_id', 'row_label', 'seat_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seats');
    }
};
