<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Models\User;
use App\Services\Generations\GenerationCreator;
use App\Services\Generations\GenerationPoller;
use App\Services\Generations\GenerationSubmitter;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Runs Pixelforge's test keywords through the real application path
 * (estimate → submit → webhook/poll → charge) against the live API, then
 * checks the money invariants on each result. Creates real generations
 * owned by the given user; low quality keeps them cheap.
 */
#[Signature('pixelforge:smoke {email : Owner of the test generations}
    {--keywords=succeed,fail,blocked,overloaded,duplicate : Comma-separated keywords to try}
    {--timeout=150 : Seconds to wait for each generation to finish}')]
#[Description('Live smoke test of the Pixelforge integration using the documented test keywords')]
class PixelforgeSmokeTest extends Command
{
    public function handle(GenerationCreator $creator, GenerationSubmitter $submitter, GenerationPoller $poller): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        if (blank(config('pixelforge.api_key'))) {
            $this->error('PIXELFORGE_API_KEY is not set.');

            return self::FAILURE;
        }

        $rows = [];
        $ok = true;

        foreach (array_filter(explode(',', (string) $this->option('keywords'))) as $keyword) {
            $keyword = trim($keyword);
            $this->line("→ [{$keyword}]");

            $generation = $creator->create($user, [
                'prompt' => "PixelPilot smoke test: a paper plane over a city skyline [{$keyword}]",
                'size' => '1024x1024',
                'quality' => 'low',
                'reference_images' => [],
                'client_request_id' => (string) Str::uuid(),
            ])['generation'];

            $result = $submitter->submit($generation);
            $generation = $this->waitForFinal($generation->fresh(), $poller);

            [$checks, $passed] = $this->verify($generation);
            $ok = $ok && $passed;

            $rows[] = [
                $keyword,
                $result->outcome,
                $generation->status->value,
                $generation->error_code ?? '—',
                $generation->actual_charge === null ? '—' : Money::add($generation->actual_charge),
                $generation->provider_generation_id ?? '—',
                $checks,
            ];
        }

        $this->table(['keyword', 'submission', 'status', 'error', 'charged', 'pixelforge id', 'checks'], $rows);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function waitForFinal(Generation $generation, GenerationPoller $poller): Generation
    {
        $deadline = now()->addSeconds((int) $this->option('timeout'));

        while ($generation->status->isPending() && now()->lt($deadline)) {
            Sleep::for(5)->seconds();
            $generation->refresh();

            // Webhooks may not reach a local machine; poll so the test completes either way.
            if ($generation->status->isPending()) {
                $poller->poll($generation);
                $generation->refresh();
            }
        }

        return $generation;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function verify(Generation $generation): array
    {
        $charges = GenerationCharge::where('generation_id', $generation->id)->get();

        $checks = match ($generation->status) {
            GenerationStatus::Succeeded => [
                'one charge row' => $charges->count() === 1,
                'charge = cost × 1.25' => Money::compare((string) $generation->actual_charge, Money::mul((string) $generation->actual_cost, '1.25')) === 0,
                'within estimate' => Money::compare((string) $generation->actual_charge, $generation->estimated_charge_min) >= 0
                    && Money::compare((string) $generation->actual_charge, $generation->estimated_charge_max) <= 0,
            ],
            GenerationStatus::Failed => [
                'free' => Money::isZero((string) $generation->actual_charge),
                'no charge row' => $charges->isEmpty(),
            ],
            // e.g. [overloaded]: every attempt is rejected, so it waits for a later retry, unbilled.
            default => [
                'not billed while pending' => $generation->actual_charge === null && $charges->isEmpty(),
            ],
        };

        $failed = array_keys(array_filter($checks, fn ($passed) => ! $passed));

        return [$failed === [] ? 'ok' : 'FAILED: '.implode(', ', $failed), $failed === []];
    }
}
