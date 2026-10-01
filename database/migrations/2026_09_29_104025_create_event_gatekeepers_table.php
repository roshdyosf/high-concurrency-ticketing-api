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
        Schema::create('event_gatekeepers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamp('assigned_at');
            $table->enum('status', ['pending', 'accepted', 'rejected'])
                ->default('pending');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // At most one active (pending or accepted, not revoked) row per user per event
        DB::statement(
            "CREATE UNIQUE INDEX gk_one_active
            ON event_gatekeepers (event_id, user_id)
            WHERE revoked_at IS NULL AND status IN ('pending', 'accepted')"
        );
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_gatekeepers');
    }
};
