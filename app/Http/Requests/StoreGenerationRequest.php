<?php

namespace App\Http\Requests;

use App\Services\Pricing\PricingCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Mirrors Pixelforge's documented limits so bad input is caught here with a
 * helpful message instead of bouncing off the API. Sizes and qualities come
 * from the live pricing catalogue, not a hardcoded list.
 */
class StoreGenerationRequest extends FormRequest
{
    public const MAX_PROMPT_CHARACTERS = 4000;

    public const MAX_REFERENCE_IMAGES = 4;

    protected function prepareForValidation(): void
    {
        $references = collect((array) $this->input('reference_images', []))
            ->map(fn ($url) => is_string($url) ? trim($url) : $url)
            ->filter(fn ($url) => $url !== '' && $url !== null)
            ->values()
            ->all();

        $this->merge([
            'prompt' => is_string($this->input('prompt')) ? trim($this->input('prompt')) : $this->input('prompt'),
            'reference_images' => $references,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pricing = app(PricingCatalog::class)->current();

        return [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_CHARACTERS],
            'size' => ['required', 'string', Rule::in($pricing->sizes())],
            'quality' => ['required', 'string', Rule::in($pricing->qualities())],
            'reference_images' => ['array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['string', 'distinct', 'max:2048', 'url:http,https'],
            'client_request_id' => ['required', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Laravel's max:4000 on strings already counts multibyte characters;
                // this guards the API's Unicode character limit explicitly.
                if (is_string($this->input('prompt')) && mb_strlen($this->input('prompt')) > self::MAX_PROMPT_CHARACTERS) {
                    $validator->errors()->add('prompt', 'The prompt may not be longer than 4,000 characters.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prompt.required' => 'Describe the image you want to create.',
            'prompt.max' => 'The prompt may not be longer than 4,000 characters.',
            'size.in' => 'Choose one of the supported sizes.',
            'quality.in' => 'Choose low, medium or high quality.',
            'reference_images.max' => 'You can add up to 4 reference images.',
            'reference_images.*.url' => 'Enter a full http:// or https:// image URL.',
            'reference_images.*.distinct' => 'This reference image is already in the list.',
            'reference_images.*.max' => 'This URL is too long.',
        ];
    }
}
