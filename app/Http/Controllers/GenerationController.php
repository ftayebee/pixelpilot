<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Services\Dashboard\GenerationPresenter;
use App\Services\Generations\GenerationCreator;
use App\Services\Generations\GenerationSubmitter;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pricing\PricingCatalog;
use App\Services\Pricing\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(private readonly GenerationPresenter $presenter) {}

    public function create(Request $request, PricingCatalog $catalog): Response
    {
        try {
            $pricing = $catalog->current();
        } catch (PixelforgeException) {
            $pricing = null;
        }

        return Inertia::render('generations/Create', [
            'options' => $pricing ? [
                'sizes' => $pricing->sizes(),
                'qualities' => $pricing->qualities(),
                'rates' => $pricing->rates,
                'per_reference_image' => $pricing->tokensPerReferenceImage,
                'markup' => app(PricingService::class)->markup(),
            ] : null,
            'limits' => [
                'prompt' => StoreGenerationRequest::MAX_PROMPT_CHARACTERS,
                'reference_images' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
            'clientRequestId' => (string) Str::uuid(),
            'recent' => $request->user()->generations()->latest()->limit(5)->get()
                ->map(fn (Generation $g) => $this->presenter->summary($g)),
            'prefill' => $this->prefill($request),
        ]);
    }

    /**
     * Price range for the form. Takes the prompt's character count rather
     * than the prompt itself, so prompts never end up in URLs or access logs.
     */
    public function estimate(Request $request, PricingCatalog $catalog, PricingService $pricing): JsonResponse
    {
        try {
            $current = $catalog->current();
        } catch (PixelforgeException) {
            return response()->json(['message' => 'Pricing is temporarily unavailable.'], 503);
        }

        $input = $request->validate([
            'characters' => ['required', 'integer', 'min:0', 'max:'.StoreGenerationRequest::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::in($current->sizes())],
            'quality' => ['required', Rule::in($current->qualities())],
            'references' => ['required', 'integer', 'min:0', 'max:'.StoreGenerationRequest::MAX_REFERENCE_IMAGES],
        ]);

        $estimate = $pricing->estimate((int) $input['characters'], $input['size'], $input['quality'], (int) $input['references'], $current);

        return response()->json($estimate->toArray());
    }

    public function store(StoreGenerationRequest $request, GenerationCreator $creator, GenerationSubmitter $submitter): RedirectResponse
    {
        ['generation' => $generation, 'created' => $created] = $creator->create($request->user(), $request->validated());

        if (! $created) {
            return redirect()->route('generations.show', $generation);
        }

        $result = $submitter->submit($generation);

        if ($result->isRejected()) {
            $error = $result->error;
            $field = $error?->isContentPolicyViolation() ? 'prompt' : ($error?->param ?? null);

            $redirect = back()->with('error', $result->message);

            return $field ? $redirect->withErrors([$field => $result->message]) : $redirect;
        }

        return redirect()
            ->route('generations.show', $generation)
            ->with('success', $result->isRetrying() ? $result->message : 'Generation started. This page updates on its own.');
    }

    public function show(Request $request, Generation $generation): Response
    {
        Gate::authorize('view', $generation);

        return Inertia::render('generations/Show', [
            'generation' => $this->presenter->detail($generation),
        ]);
    }

    /**
     * "Retry" and "Use this prompt" open the form pre-filled from a generation
     * the user owns. Submitting it creates a brand-new generation.
     *
     * @return array<string, mixed>|null
     */
    private function prefill(Request $request): ?array
    {
        $source = $request->query('from');

        if (! is_string($source) || ! Str::isUuid($source)) {
            return null;
        }

        $generation = $request->user()->generations()->where('uuid', $source)->first();

        return $generation ? [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images ?? [],
        ] : null;
    }
}
