<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pixelforge's own record of what it billed, per UTC day, copied from
 * GET /v1/usage. Rows are scoped by a fingerprint of the API key they were
 * fetched with, so usage from different keys (environments) never mixes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_usage_days', function (Blueprint $table) {
            $table->id();
            $table->string('key_fingerprint', 16);
            $table->date('date');
            $table->unsignedInteger('generations');
            $table->unsignedBigInteger('input_text_tokens');
            $table->unsignedBigInteger('input_image_tokens');
            $table->unsignedBigInteger('output_tokens');
            $table->unsignedBigInteger('reasoning_tokens');
            $table->decimal('cost_usd', 24, 12);
            // Set when a later sync reported a different amount for the same day.
            $table->decimal('previous_cost_usd', 24, 12)->nullable();
            $table->timestampTz('synced_at');
            $table->timestampsTz();

            $table->unique(['key_fingerprint', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_usage_days');
    }
};
