<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credits (USD). credit_transactions is an append-only ledger; the balance
 * on users is a running total kept in the same transaction as each entry,
 * and always equals the sum of the user's ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('credit_balance', 24, 12)->default(0)->after('is_admin');
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->decimal('reserved_amount', 24, 12)->nullable()->after('estimated_charge_max');
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 24);
            // Signed: positive adds credits, negative removes them.
            $table->decimal('amount', 24, 12);
            $table->decimal('balance_after', 24, 12);
            $table->foreignId('generation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            // Set on a charge that exceeded its reservation (see docs/pricing.md).
            $table->decimal('shortfall', 24, 12)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
        });

        DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_type_check CHECK (type IN ('purchase','reservation','release','charge'))");
        DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_sign_check CHECK (
            (type IN ('purchase','release') AND amount >= 0) OR (type IN ('reservation','charge') AND amount <= 0)
        )");
        DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_generation_check CHECK (
            (type = 'purchase') = (generation_id IS NULL)
        )");
        // At most one reservation, one release and one charge per generation.
        DB::statement('CREATE UNIQUE INDEX credit_transactions_one_per_generation ON credit_transactions (generation_id, type) WHERE generation_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION credit_transactions_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'credit transactions are append-only';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS credit_transactions_append_only ON credit_transactions;
            CREATE TRIGGER credit_transactions_append_only
                BEFORE UPDATE OR DELETE ON credit_transactions
                FOR EACH ROW EXECUTE FUNCTION credit_transactions_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS credit_transactions_append_only()');

        Schema::table('generations', fn (Blueprint $table) => $table->dropColumn('reserved_amount'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('credit_balance'));
    }
};
