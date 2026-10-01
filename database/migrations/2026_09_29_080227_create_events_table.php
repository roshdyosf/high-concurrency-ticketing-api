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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('users');
            $table->string('title');
            $table->text('description');
            $table->string('venue_name');
            $table->string('location');
            $table->timestamp('event_date');
            $table->timestamp('end_date');
            $table->string('image_url')->nullable();
            $table->string('image_public_id')->nullable();
            $table->enum('status', ['draft', 'published', 'completed', 'cancelled', 'suspended'])
                ->default('draft');
            $table->timestamps();
        });
        DB::statement(
            'ALTER TABLE events ADD CONSTRAINT events_end_after_start CHECK (end_date > event_date)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
