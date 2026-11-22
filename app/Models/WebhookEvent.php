<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    public const PENDING = 'pending';

    public const PROCESSED = 'processed';

    /** Verified, but nothing to do (e.g. "ping", or an event type we don't handle). */
    public const IGNORED = 'ignored';

    /** No local generation matched after several attempts. */
    public const UNMATCHED = 'unmatched';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_created_at' => 'datetime',
            'received_at' => 'datetime',
            'last_received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        return (array) json_decode($this->payload, true, flags: JSON_THROW_ON_ERROR);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::PROCESSED, self::IGNORED], true);
    }
}
