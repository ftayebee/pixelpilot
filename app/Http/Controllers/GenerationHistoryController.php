<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Dashboard\GenerationPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class GenerationHistoryController extends Controller
{
    public const PER_PAGE = 12;

    /**
     * User-facing status filters. "Queued" includes generations we haven't
     * handed to Pixelforge yet.
     */
    private const STATUS_FILTERS = [
        'queued' => [GenerationStatus::Submitting, GenerationStatus::Queued],
        'processing' => [GenerationStatus::Processing],
        'succeeded' => [GenerationStatus::Succeeded],
        'failed' => [GenerationStatus::Failed],
    ];

    public function index(Request $request, GenerationPresenter $presenter): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(array_keys(self::STATUS_FILTERS))],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
        ]);

        $search = trim($filters['search'] ?? '');
        $status = $filters['status'] ?? null;
        $sort = $filters['sort'] ?? 'newest';

        $generations = $request->user()->generations()
            ->when($search !== '', function ($query) use ($search) {
                // Escape LIKE wildcards so a search for "50%" means the literal text.
                $escaped = addcslashes($search, '\\%_');
                $query->where('prompt', 'ilike', "%{$escaped}%");
            })
            ->when($status, fn ($query) => $query->whereIn('status', array_map(fn ($s) => $s->value, self::STATUS_FILTERS[$status])))
            ->orderBy('created_at', $sort === 'oldest' ? 'asc' : 'desc')
            ->orderBy('id', $sort === 'oldest' ? 'asc' : 'desc')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Generation $g) => $presenter->summary($g));

        return Inertia::render('generations/Index', [
            'generations' => $generations,
            'filters' => ['search' => $search, 'status' => $status, 'sort' => $sort],
            'hasAny' => $request->user()->generations()->exists(),
        ]);
    }

    /**
     * Serves our stored copy of an image (only when image storage is on).
     */
    public function image(Request $request, Generation $generation): HttpResponse
    {
        Gate::authorize('view', $generation);

        abort_if($generation->image_path === null || $generation->image_disk === null, 404);

        $disk = Storage::disk($generation->image_disk);
        abort_unless($disk->exists($generation->image_path), 404);

        return $disk->response($generation->image_path, "pixelpilot-{$generation->uuid}.png", [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
