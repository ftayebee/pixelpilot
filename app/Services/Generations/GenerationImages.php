<?php

namespace App\Services\Generations;

use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Where a generation's image is served from. Callers ask for a URL and
 * don't care whether it's Pixelforge's signed link or our own copy, so
 * switching storage strategy doesn't touch the UI or the controllers.
 */
class GenerationImages
{
    public function url(Generation $generation): ?string
    {
        if ($generation->image_path !== null && $generation->image_disk !== null) {
            return route('generations.image', $generation);
        }

        if ($generation->image_url !== null && $generation->image_url_expires_at?->isFuture()) {
            return $generation->image_url;
        }

        return null;
    }

    public function shouldStore(): bool
    {
        return (bool) config('pixelpilot.images.store');
    }

    /**
     * Copies the Pixelforge image to our own disk. Only fetches from the
     * Pixelforge host, so a tampered URL can't make us request arbitrary
     * addresses.
     */
    public function store(Generation $generation): void
    {
        if ($generation->image_path !== null || $generation->image_url === null) {
            return;
        }

        if (! $this->isPixelforgeUrl($generation->image_url)) {
            throw new RuntimeException('Refusing to download an image from a non-Pixelforge host.');
        }

        $response = Http::timeout(30)->accept('image/png')->get($generation->image_url);

        if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            throw new RuntimeException("Could not download image (HTTP {$response->status()}).");
        }

        $body = $response->body();
        if (strlen($body) === 0 || strlen($body) > config('pixelpilot.images.max_bytes')) {
            throw new RuntimeException('Downloaded image has an unexpected size.');
        }

        $disk = (string) config('pixelpilot.images.disk');
        $path = "generations/{$generation->uuid}.png";

        Storage::disk($disk)->put($path, $body);

        $generation->forceFill(['image_disk' => $disk, 'image_path' => $path])->save();

        Log::info('Stored generation image.', ['generation' => $generation->uuid, 'disk' => $disk]);
    }

    private function isPixelforgeUrl(string $url): bool
    {
        $allowed = parse_url((string) config('pixelforge.base_url'), PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return $scheme === 'https' && is_string($host) && $host === $allowed;
    }
}
