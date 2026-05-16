<?php

use App\Models\Analysis;
use App\Models\SimilarProduct;
use App\Models\User;
use App\Services\AnalyzeProductService;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;
use Tests\Support\FakesProductReviewer;

uses(FakesProductReviewer::class);

beforeEach(function () {
    $this->seed([
        InputTypeSeeder::class,
        RecommendationDecisionSeeder::class,
    ]);
});

it('persists one analysis row and N similar product rows on success', function () {
    $user = User::factory()->create();

    $this->bindFakeProductReviewer();

    $service = $this->app->make(AnalyzeProductService::class);
    $analysis = $service->analyzeText($user, 'Test product query');

    expect($analysis)->toBeInstanceOf(Analysis::class);
    expect(Analysis::count())->toBe(1);
    expect(SimilarProduct::count())->toBe(1);
    expect($analysis->similarProducts)->toHaveCount(1);
});

it('stores raw_response as JSON', function () {
    $user = User::factory()->create();

    $this->bindFakeProductReviewer();

    $service = $this->app->make(AnalyzeProductService::class);
    $analysis = $service->analyzeText($user, 'Test query');

    expect($analysis->raw_response)->toBeArray();
    expect($analysis->raw_response['product']['name'])->toBe('Test Product');
});
