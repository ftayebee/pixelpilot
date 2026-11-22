<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The financial record of a successful generation. One row per generation,
 * enforced by a unique index, and every amount must be reproducible from the
 * token counts and rate snapshot stored alongside it, enforced by CHECKs.
 *
 * A trigger on generations stops final statuses and recorded charges from
 * ever being rewritten, whatever code path tries it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider_generation_id')->unique();

            $table->unsignedInteger('input_text_tokens');
            $table->unsignedInteger('input_image_tokens');
            $table->unsignedInteger('output_tokens');
            $table->unsignedInteger('reasoning_tokens');

            // USD per 1M tokens, as Pixelforge fixed them for this generation.
            $table->decimal('rate_input_text', 18, 6);
            $table->decimal('rate_input_image', 18, 6);
            $table->decimal('rate_output', 18, 6);
            $table->decimal('rate_reasoning', 18, 6);
            $table->decimal('markup', 8, 4);

            $table->decimal('provider_cost', 24, 12);
            $table->decimal('customer_charge', 24, 12);
            $table->string('currency', 3)->default('usd');

            // UTC day the generation completed, which is the day Pixelforge bills it on.
            $table->date('billed_on');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['billed_on', 'user_id']);
        });

        DB::statement('ALTER TABLE generation_charges ADD CONSTRAINT generation_charges_cost_matches_usage CHECK (
            provider_cost * 1000000 = input_text_tokens * rate_input_text
                + input_image_tokens * rate_input_image
                + output_tokens * rate_output
                + reasoning_tokens * rate_reasoning
        )');
        DB::statement('ALTER TABLE generation_charges ADD CONSTRAINT generation_charges_charge_is_marked_up CHECK (customer_charge = provider_cost * markup)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION generations_protect_final_state() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('succeeded', 'failed') AND NEW.status IS DISTINCT FROM OLD.status THEN
                    RAISE EXCEPTION 'generation % is % and cannot become %', OLD.id, OLD.status, NEW.status;
                END IF;
                IF OLD.actual_charge IS NOT NULL AND NEW.actual_charge IS DISTINCT FROM OLD.actual_charge THEN
                    RAISE EXCEPTION 'generation % already has a recorded charge', OLD.id;
                END IF;
                IF OLD.actual_cost IS NOT NULL AND NEW.actual_cost IS DISTINCT FROM OLD.actual_cost THEN
                    RAISE EXCEPTION 'generation % already has a recorded cost', OLD.id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS generations_protect_final_state ON generations;
            CREATE TRIGGER generations_protect_final_state
                BEFORE UPDATE ON generations
                FOR EACH ROW EXECUTE FUNCTION generations_protect_final_state();

            CREATE OR REPLACE FUNCTION generation_charges_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'generation charges are immutable';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER generation_charges_immutable
                BEFORE UPDATE OR DELETE ON generation_charges
                FOR EACH ROW EXECUTE FUNCTION generation_charges_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS generations_protect_final_state ON generations');
        DB::unprepared('DROP FUNCTION IF EXISTS generations_protect_final_state()');
        Schema::dropIfExists('generation_charges');
        DB::unprepared('DROP FUNCTION IF EXISTS generation_charges_immutable()');
    }
};
