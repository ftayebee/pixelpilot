<?php

namespace App\Services\Credits;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Every change to a user's credits goes through here.
 *
 * Each operation locks the user's row, appends one ledger entry and updates
 * the running balance in the same transaction, so the balance always
 * equals the sum of the ledger and concurrent operations can't lose an
 * update. Generation-related entries are unique per (generation, type),
 * which makes reserve/release/settle idempotent.
 *
 *   create      → reservation  (−max estimated charge), refused if unaffordable
 *   failure     → release      (+reservation)
 *   success     → release (+reservation) and charge (−actual charge)
 */
class CreditLedger
{
    public function purchase(User $user, string $amount, string $reference): CreditTransaction
    {
        return DB::transaction(function () use ($user, $amount, $reference) {
            $existing = CreditTransaction::where('reference', $reference)->first();
            if ($existing !== null) {
                return $existing; // same request submitted twice
            }

            return $this->append($user->id, CreditTransaction::PURCHASE, $amount, null, 'Test purchase (simulated, no payment taken)', $reference);
        });
    }

    /**
     * @throws InsufficientCredits
     */
    public function reserve(Generation $generation, string $amount): void
    {
        DB::transaction(function () use ($generation, $amount) {
            $balance = $this->lockBalance($generation->user_id);

            if (Money::compare($balance, $amount) < 0) {
                throw new InsufficientCredits($amount, $balance);
            }

            $this->append($generation->user_id, CreditTransaction::RESERVATION, Money::sub('0', $amount), $generation->id, 'Reserved for image (maximum estimated price)');
            $generation->forceFill(['reserved_amount' => $amount])->save();
        });
    }

    /**
     * Gives back the reservation of a generation that failed or was rejected.
     */
    public function release(Generation $generation): void
    {
        DB::transaction(function () use ($generation) {
            if (! $this->hasReservation($generation) || $this->exists($generation, CreditTransaction::RELEASE)) {
                return;
            }

            $this->lockBalance($generation->user_id);
            $this->append($generation->user_id, CreditTransaction::RELEASE, $generation->reserved_amount, $generation->id, 'Image failed: reservation returned, not charged');
        });
    }

    /**
     * Replaces the reservation with the actual charge.
     */
    public function settle(Generation $generation, string $charge): void
    {
        DB::transaction(function () use ($generation, $charge) {
            if ($this->exists($generation, CreditTransaction::CHARGE)) {
                return;
            }

            $balance = $this->lockBalance($generation->user_id);
            $reserved = $this->hasReservation($generation) ? $generation->reserved_amount : '0';

            if ($this->hasReservation($generation) && ! $this->exists($generation, CreditTransaction::RELEASE)) {
                $balance = $this->append($generation->user_id, CreditTransaction::RELEASE, $reserved, $generation->id, 'Reservation released at settlement')->balance_after;
            }

            // Policy: always record the true charge. If it exceeds what was
            // reserved and the balance can't cover the difference, flag it.
            $shortfall = null;
            if (Money::compare($charge, $reserved) > 0 && Money::compare($balance, $charge) < 0) {
                $shortfall = Money::sub($charge, Money::max($balance, '0'));
                Log::warning('Generation charge exceeded its reservation and the available balance.', [
                    'generation' => $generation->uuid,
                    'charge' => $charge,
                    'reserved' => $reserved,
                    'shortfall' => $shortfall,
                ]);
            }

            $this->append($generation->user_id, CreditTransaction::CHARGE, Money::sub('0', $charge), $generation->id, 'Image created', shortfall: $shortfall);
        });
    }

    /**
     * Sum of reservations not yet released or settled: credits set aside
     * for images still in progress.
     */
    public function reservedFor(User $user): string
    {
        $amount = DB::table('credit_transactions as r')
            ->where('r.user_id', $user->id)
            ->where('r.type', CreditTransaction::RESERVATION)
            ->whereNotExists(fn ($q) => $q->from('credit_transactions as x')
                ->whereColumn('x.generation_id', 'r.generation_id')
                ->where('x.type', CreditTransaction::RELEASE))
            ->sum('r.amount');

        return Money::normalize(Money::sub('0', (string) $amount));
    }

    private function lockBalance(int $userId): string
    {
        return (string) DB::table('users')->where('id', $userId)->lockForUpdate()->value('credit_balance');
    }

    private function append(int $userId, string $type, string $amount, ?int $generationId, string $description, ?string $reference = null, ?string $shortfall = null): CreditTransaction
    {
        $balance = (string) DB::table('users')->where('id', $userId)->value('credit_balance');
        $after = Money::normalize(Money::add($balance, $amount));

        DB::table('users')->where('id', $userId)->update(['credit_balance' => $after]);

        return CreditTransaction::create([
            'reference' => $reference ?? (string) Str::uuid(),
            'user_id' => $userId,
            'type' => $type,
            'amount' => Money::normalize($amount),
            'balance_after' => $after,
            'generation_id' => $generationId,
            'description' => $description,
            'shortfall' => $shortfall === null ? null : Money::normalize($shortfall),
        ]);
    }

    private function hasReservation(Generation $generation): bool
    {
        return $generation->reserved_amount !== null && $this->exists($generation, CreditTransaction::RESERVATION);
    }

    private function exists(Generation $generation, string $type): bool
    {
        return CreditTransaction::where('generation_id', $generation->id)->where('type', $type)->exists();
    }
}
