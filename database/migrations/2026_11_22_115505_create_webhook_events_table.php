<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every verified Pixelforge event, stored once. The unique event_id is what
 * makes duplicate deliveries harmless: a second insert of the same event is
 * a no-op at the database level, not just in application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type', 64);
            $table->string('provider_generation_id')->nullable()->index();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();

            // Exact bytes as received (and verified), for audit and replay.
            $table->text('payload');
            $table->timestampTz('event_created_at')->nullable();

            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('deliveries')->default(1);
            $table->unsignedSmallInteger('last_delivery_attempt')->nullable();
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestampTz('received_at');
            $table->timestampTz('last_received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'received_at']);
        });

        DB::statement("ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_status_check CHECK (status IN ('pending','processed','ignored','unmatched','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
