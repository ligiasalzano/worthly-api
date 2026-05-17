<?php

use App\Ai\Harness\AnalysisPipeline;
use App\Ai\Harness\Evals\EvalReport;
use App\Models\User;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;

beforeEach(function () {
    if (env('WORTHLY_RUN_EVALS') !== '1') {
        $this->markTestSkipped('Set WORTHLY_RUN_EVALS=1 to run the eval harness against live providers.');
    }

    $this->seed([
        InputTypeSeeder::class,
        RecommendationDecisionSeeder::class,
    ]);
});

it('passes the golden dataset against the live pipeline', function () {
    $path = base_path('tests/Fixtures/Ai/Evals/golden.json');
    $rows = json_decode((string) file_get_contents($path), true);

    expect($rows)->toBeArray()->not->toBeEmpty();

    $pipeline = app(AnalysisPipeline::class);
    $user = User::factory()->create();

    $report = new EvalReport;

    $whitelist = (array) config('worthly.harness.retrievers.reviews.include_domains', []);
    $budgetMs = (int) config('worthly.harness.budget.max_latency_ms', 12_000);

    foreach ($rows as $row) {
        $start = (int) (microtime(true) * 1000);

        $analysis = $pipeline->analyzeText($user, $row['input']);

        $durationMs = (int) (microtime(true) * 1000) - $start;

        $decisionSlug = $analysis->recommendationDecision->slug;
        $acceptable = $row['acceptable_decisions'] ?? [$row['expected_decision']];
        $decisionMatch = in_array($decisionSlug, $acceptable, true);

        $citedHosts = $analysis->sources->map(fn ($s) => parse_url($s->url, PHP_URL_HOST) ?: '')->all();
        $citedHostsNormalized = array_map(fn ($h) => preg_replace('/^www\./', '', strtolower($h)), $citedHosts);

        $citedHighAuthority = false;
        foreach ($citedHostsNormalized as $host) {
            foreach ($whitelist as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    $citedHighAuthority = true;
                    break 2;
                }
            }
        }

        $withinBudget = $durationMs <= $budgetMs;

        $report->addRow([
            'id' => $row['id'] ?? null,
            'input' => $row['input'],
            'expected_decision' => $row['expected_decision'],
            'actual_decision' => $decisionSlug,
            'decision_match' => $decisionMatch,
            'cited_hosts' => $citedHostsNormalized,
            'cited_high_authority' => $citedHighAuthority,
            'duration_ms' => $durationMs,
            'within_budget' => $withinBudget,
        ]);

        expect($decisionMatch)->toBeTrue("Expected {$row['expected_decision']} but got {$decisionSlug} for: {$row['input']}");
        expect($withinBudget)->toBeTrue("Pipeline took {$durationMs}ms exceeding budget {$budgetMs}ms for: {$row['input']}");

        if (! empty($row['expected_must_cite_domains'])) {
            expect($citedHighAuthority)->toBeTrue("No high-authority citation for: {$row['input']}");
        }
    }

    $report->write();
});
