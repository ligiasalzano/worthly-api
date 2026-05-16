<?php

use App\Enums\InputType as InputTypeEnum;
use App\Enums\RecommendationDecision as RecommendationDecisionEnum;
use App\Http\Resources\AnalysisListResource;
use App\Http\Resources\AnalysisResource;
use App\Models\Analysis;
use App\Models\InputType;
use App\Models\RecommendationDecision;
use App\Models\User;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;

beforeEach(function () {
    $this->seed([
        InputTypeSeeder::class,
        RecommendationDecisionSeeder::class,
    ]);
});

it('AnalysisResource shape matches the US-5.1 JSON contract', function () {
    $user = User::factory()->create();
    $analysis = Analysis::factory()->for($user)->create([
        'product_name' => 'Sony WH-1000XM5',
        'product_category' => 'Headphones',
        'estimated_price_range' => '$300 - $400',
        'summary' => 'Excellent noise cancellation.',
        'cost_benefit_analysis' => 'Top-tier value.',
        'recommendation_reason' => 'Best in class ANC.',
        'recommendation_decision_id' => RecommendationDecision::firstWhere('slug', RecommendationDecisionEnum::Buy->value)->id,
        'input_type_id' => InputType::firstWhere('slug', InputTypeEnum::Text->value)->id,
        'image_path' => null,
    ]);

    $analysis->similarProducts()->create([
        'name' => 'Bose QC45',
        'reason' => 'Comparable ANC.',
        'price_reference' => '$280 - $330',
        'sort_order' => 0,
    ]);

    $analysis->refresh()->load(['similarProducts', 'inputType', 'recommendationDecision']);

    $payload = AnalysisResource::make($analysis)->resolve(request());

    expect(array_keys($payload))->toEqualCanonicalizing([
        'id',
        'product',
        'summary',
        'similar_products',
        'cost_benefit_analysis',
        'recommendation',
        'input_type',
        'image_url',
        'created_at',
    ]);

    expect($payload['id'])->toBe($analysis->id);
    expect($payload['product'])->toEqual([
        'name' => 'Sony WH-1000XM5',
        'category' => 'Headphones',
        'estimated_price_range' => '$300 - $400',
    ]);
    expect($payload['summary'])->toBe('Excellent noise cancellation.');
    expect($payload['similar_products'])->toBe([[
        'name' => 'Bose QC45',
        'reason' => 'Comparable ANC.',
        'price_reference' => '$280 - $330',
    ]]);
    expect($payload['cost_benefit_analysis'])->toBe('Top-tier value.');
    expect($payload['recommendation'])->toEqual([
        'decision' => RecommendationDecisionEnum::Buy->value,
        'reason' => 'Best in class ANC.',
    ]);
    expect($payload['input_type'])->toBe(InputTypeEnum::Text->value);
    expect($payload['image_url'])->toBeNull();
});

it('AnalysisListResource exposes exactly id, product_name, input_type, recommendation, created_at', function () {
    $user = User::factory()->create();
    $analysis = Analysis::factory()->for($user)->create();
    $analysis->load(['inputType', 'recommendationDecision']);

    $payload = AnalysisListResource::make($analysis)->resolve(request());

    expect(array_keys($payload))->toEqualCanonicalizing([
        'id',
        'product_name',
        'input_type',
        'recommendation',
        'created_at',
    ]);
});

it('AnalysisResource decision is always one of the known slugs', function () {
    $knownSlugs = array_map(fn (RecommendationDecisionEnum $case) => $case->value, RecommendationDecisionEnum::cases());

    foreach ($knownSlugs as $slug) {
        $user = User::factory()->create();
        $decisionId = RecommendationDecision::firstWhere('slug', $slug)->id;
        $analysis = Analysis::factory()->for($user)->create([
            'recommendation_decision_id' => $decisionId,
        ]);
        $analysis->load(['similarProducts', 'inputType', 'recommendationDecision']);

        $payload = AnalysisResource::make($analysis)->resolve(request());

        expect($payload['recommendation']['decision'])->toBeIn($knownSlugs);
    }
});

it('AnalysisResource returns null image_url for text analyses', function () {
    $user = User::factory()->create();
    $analysis = Analysis::factory()->for($user)->create(['image_path' => null]);
    $analysis->load(['similarProducts', 'inputType', 'recommendationDecision']);

    $payload = AnalysisResource::make($analysis)->resolve(request());

    expect($payload['image_url'])->toBeNull();
});
