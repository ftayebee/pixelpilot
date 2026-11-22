<?php

namespace App\Services\Pixelforge\Webhooks;

use App\Jobs\ProcessPixelforgeEvent;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Accepts a verified webhook: validates its shape, stores it exactly once,
 * and hands it to the queue. Kept fast so Pixelforge gets its 2xx well
 * inside the 10 second limit.
 */
class WebhookInbox
{
    public const GENERATION_EVENTS = ['generation.processing', 'generation.succeeded', 'generation.failed'];

    /**
     * @return array{event: WebhookEvent, duplicate: bool}
     *
     * @throws InvalidArgumentException when the payload isn't a Pixelforge event
     */
    public function receive(string $rawPayload, ?int $deliveryAttempt): array
    {
        $event = $this->parse($rawPayload);
        $now = now();

        // ON CONFLICT DO NOTHING: concurrent deliveries of the same event can't both insert.
        $inserted = DB::table('webhook_events')->insertOrIgnore([
            'event_id' => $event['id'],
            'type' => $event['type'],
            'provider_generation_id' => $event['generation_id'],
            'payload' => $rawPayload,
            'event_created_at' => $event['created_at'],
            'status' => WebhookEvent::PENDING,
            'deliveries' => 1,
            'last_delivery_attempt' => $deliveryAttempt,
            'received_at' => $now,
            'last_received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stored = WebhookEvent::where('event_id', $event['id'])->firstOrFail();

        if ($inserted === 0) {
            $stored->increment('deliveries', 1, [
                'last_delivery_attempt' => $deliveryAttempt,
                'last_received_at' => $now,
            ]);
        }

        // A redelivery of an event we haven't finished with is a nudge to try
        // again; processing is idempotent, so a second job is harmless.
        if (! $stored->isFinished()) {
            $this->dispatch($stored);
        }

        return ['event' => $stored, 'duplicate' => $inserted === 0];
    }

    /**
     * The event is already stored, so a failure to queue it (or, with the sync
     * driver, to process it) must not turn into a non-2xx for Pixelforge. The
     * sweeper picks up anything left pending.
     */
    private function dispatch(WebhookEvent $event): void
    {
        try {
            app(Dispatcher::class)->dispatch(new ProcessPixelforgeEvent($event->id));
        } catch (Throwable $e) {
            Log::warning('Webhook event stored but not processed yet.', ['event_id' => $event->event_id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{id: string, type: string, created_at: ?CarbonImmutable, generation_id: ?string}
     */
    private function parse(string $rawPayload): array
    {
        try {
            $data = json_decode($rawPayload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Payload is not valid JSON.');
        }

        if (! is_array($data) || ($data['object'] ?? null) !== 'event') {
            throw new InvalidArgumentException('Payload is not an event object.');
        }

        $id = $data['id'] ?? null;
        $type = $data['type'] ?? null;

        if (! is_string($id) || ! preg_match('/^evt_[A-Za-z0-9_-]{1,200}$/', $id)) {
            throw new InvalidArgumentException('Event id is missing or malformed.');
        }

        if (! is_string($type) || $type === '' || strlen($type) > 64) {
            throw new InvalidArgumentException('Event type is missing.');
        }

        $generationId = null;
        if (in_array($type, self::GENERATION_EVENTS, true)) {
            $object = $data['data']['object'] ?? null;
            if (! is_array($object) || ($object['object'] ?? null) !== 'generation' || ! is_string($object['id'] ?? null)) {
                throw new InvalidArgumentException('Generation event has no generation object.');
            }
            $generationId = $object['id'];
        }

        $createdAt = null;
        if (is_string($data['created_at'] ?? null)) {
            try {
                $createdAt = CarbonImmutable::parse($data['created_at'])->utc();
            } catch (Throwable) {
                $createdAt = null;
            }
        }

        return ['id' => $id, 'type' => $type, 'created_at' => $createdAt, 'generation_id' => $generationId];
    }
}
