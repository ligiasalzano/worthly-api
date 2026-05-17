<?php

use App\Ai\Harness\Observability\DegradedRateWatcher;
use App\Models\Analysis;
use App\Models\HarnessRun;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->seed([
        InputTypeSeeder::class,
        RecommendationDecisionSeeder::class,
    ]);
});

function createHarnessRun(bool $degraded): HarnessRun
{
    $analysis = Analysis::factory()->create();

    return HarnessRun::create([
        'analysis_id' => $analysis->id,
        'started_at' => Carbon::now()->subMinutes(5),
        'finished_at' => Carbon::now()->subMinutes(5),
        'total_ms' => 1000,
        'llm_calls' => 1,
        'retrieval_calls' => 1,
        'tokens_in' => 0,
        'tokens_out' => 0,
        'cache_hit' => false,
        'degraded' => $degraded,
        'budget_exhausted' => false,
        'error' => null,
        'layers' => [],
    ]);
}

it('emits a warning log when the degraded rate over the last hour exceeds 10%', function () {
    Log::spy();

    for ($i = 0; $i < 9; $i++) {
        createHarnessRun(degraded: false);
    }
    createHarnessRun(degraded: true);
    createHarnessRun(degraded: true);

    $result = app(DegradedRateWatcher::class)->check();

    expect($result['total'])->toBe(11);
    expect($result['degraded'])->toBe(2);
    expect($result['rate'])->toBeGreaterThan(0.10);
    expect($result['alerted'])->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(function ($message, $payload) {
            return $message === 'harness.degraded_rate_alert'
                && ($payload['total'] ?? null) === 11
                && ($payload['degraded'] ?? null) === 2;
        })
        ->once();
});

it('does not emit a warning when the degraded rate stays at 10% or below', function () {
    Log::spy();

    for ($i = 0; $i < 9; $i++) {
        createHarnessRun(degraded: false);
    }
    createHarnessRun(degraded: true);

    $result = app(DegradedRateWatcher::class)->check();

    expect($result['total'])->toBe(10);
    expect($result['degraded'])->toBe(1);
    expect($result['rate'])->toBe(0.1);
    expect($result['alerted'])->toBeFalse();

    Log::shouldNotHaveReceived('warning');
});

it('skips the alert when there are no runs in the last hour', function () {
    Log::spy();

    $result = app(DegradedRateWatcher::class)->check();

    expect($result['total'])->toBe(0);
    expect($result['alerted'])->toBeFalse();

    Log::shouldNotHaveReceived('warning');
});
