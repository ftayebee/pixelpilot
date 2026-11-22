<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money columns (estimated_*, actual_cost, actual_charge) are NUMERIC and
 * come back from PostgreSQL as exact decimal strings. They are never cast to
 * float; all arithmetic goes through App\Support\Money.
 *
 * @property GenerationStatus $status
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'submitting',
        'reference_images' => '[]',
        'currency' => 'usd',
        'submit_attempts' => 0,
        'poll_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'estimate_rates' => 'array',
            'rates' => 'array',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'estimated_at' => 'datetime',
            'charged_at' => 'datetime',
            'image_url_expires_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'next_poll_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Generation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::pendingValues());
    }
}
