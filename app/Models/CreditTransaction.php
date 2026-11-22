<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable movement of credits. Amounts are signed decimal strings.
 */
class CreditTransaction extends Model
{
    public const UPDATED_AT = null;

    public const PURCHASE = 'purchase';

    public const RESERVATION = 'reservation';

    public const RELEASE = 'release';

    public const CHARGE = 'charge';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
