<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money columns are NUMERIC(24,12). Pixelforge costs have at most 9 decimal
 * places at the documented rates and the 1.25 markup adds at most 2 more, so
 * 12 places stores every amount exactly with headroom for finer rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Sent to Pixelforge as the Idempotency-Key; reused on every retry.
            $table->uuid('idempotency_key')->unique();
            $table->string('provider_generation_id')->nullable()->unique();

            $table->string('status', 20)->default('submitting');
            $table->text('prompt');
            $table->string('size', 20);
            $table->string('quality', 10);
            $table->jsonb('reference_images')->default('[]');

            // Up-front estimate (range) and the pricing it was based on.
            $table->string('currency', 3)->default('usd');
            $table->decimal('estimated_cost_min', 24, 12);
            $table->decimal('estimated_cost_max', 24, 12);
            $table->decimal('estimated_charge_min', 24, 12);
            $table->decimal('estimated_charge_max', 24, 12);
            $table->jsonb('estimate_rates');
            $table->timestampTz('estimated_at');

            // Rates Pixelforge fixed for this generation when it was created.
            $table->jsonb('rates')->nullable();

            // Actual usage and cost, set exactly once when the generation succeeds.
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->decimal('actual_cost', 24, 12)->nullable();
            $table->decimal('actual_charge', 24, 12)->nullable();
            $table->timestampTz('charged_at')->nullable();

            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();
            $table->string('image_disk')->nullable();
            $table->string('image_path')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            // Submission retries and polling fallback bookkeeping.
            $table->unsignedSmallInteger('submit_attempts')->default(0);
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('next_poll_at')->nullable();
            $table->unsignedSmallInteger('poll_attempts')->default(0);

            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'next_poll_at']);
        });

        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_status_check CHECK (status IN ('submitting','queued','processing','succeeded','failed'))");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_size_check CHECK (size IN ('1024x1024','1536x1024','1024x1536'))");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_quality_check CHECK (quality IN ('low','medium','high'))");
        // Actual amounts exist only for finished generations, and a failed one is always free.
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_actual_only_when_final CHECK ((actual_charge IS NULL AND actual_cost IS NULL) OR status IN ('succeeded','failed'))");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_failed_is_free CHECK (status <> 'failed' OR (actual_charge = 0 AND actual_cost = 0))");
        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_amounts_non_negative CHECK (estimated_cost_min >= 0 AND estimated_charge_min >= 0 AND (actual_cost IS NULL OR actual_cost >= 0) AND (actual_charge IS NULL OR actual_charge >= 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
