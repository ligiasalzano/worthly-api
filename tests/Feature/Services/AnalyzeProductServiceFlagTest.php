<?php

use App\Ai\Harness\AnalysisPipeline;
use App\Models\Analysis;
use App\Models\User;
use App\Services\AnalyzeProductService;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;

beforeEach(function () {
    $this->seed([
        InputTypeSeeder::class,
        RecommendationDecisionSeeder::class,
    ]);
});

it('treats worthly.harness.enabled as always on, ignoring any attempt to disable it', function () {
    config()->set('worthly.harness.enabled', false);

    expect(config('worthly.harness.enabled'))->toBeFalse();

    $reloaded = require config_path('worthly.php');

    expect($reloaded['harness']['enabled'])->toBeTrue();
});

it('routes AnalyzeProductService through AnalysisPipeline regardless of runtime config flips', function () {
    $user = User::factory()->create();
    $expected = Analysis::factory()->for($user)->make();
    $expected->id = 12345;

    $pipeline = new class($expected) extends AnalysisPipeline
    {
        public bool $analyzeTextCalled = false;

        public bool $analyzeImageCalled = false;

        public function __construct(private Analysis $analysis) {}

        public function analyzeText(User $user, string $query): Analysis
        {
            $this->analyzeTextCalled = true;

            return $this->analysis;
        }

        public function analyzeImage(User $user, string $imagePath): Analysis
        {
            $this->analyzeImageCalled = true;

            return $this->analysis;
        }
    };

    $this->app->instance(AnalysisPipeline::class, $pipeline);

    config()->set('worthly.harness.enabled', false);

    $service = $this->app->make(AnalyzeProductService::class);

    $service->analyzeText($user, 'Some product query');
    $service->analyzeImage($user, 'analyses/some-image.png');

    expect($pipeline->analyzeTextCalled)->toBeTrue();
    expect($pipeline->analyzeImageCalled)->toBeTrue();
});
