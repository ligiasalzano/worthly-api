<?php

use App\Ai\Agents\ProductReviewer;
use App\Enums\InputType as InputTypeEnum;
use App\Enums\RecommendationDecision as RecommendationDecisionEnum;
use App\Models\Analysis;
use App\Models\User;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Tests\Support\FakesProductReviewer;

uses(FakesProductReviewer::class);

beforeEach(function () {
    $this->seed([
        InputTypeSeeder::class,
        RecommendationDecisionSeeder::class,
    ]);
});

it('returns 201 with the full AnalysisResource for a text analysis', function () {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $response = $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Text->value,
        'query' => 'Sony WH-1000XM5 headphones — should I buy?',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'id',
                'product' => ['name', 'category', 'estimated_price_range'],
                'summary',
                'similar_products' => [['name', 'reason', 'price_reference']],
                'cost_benefit_analysis',
                'recommendation' => ['decision', 'reason'],
                'input_type',
                'image_url',
                'created_at',
            ],
        ])
        ->assertJsonPath('data.input_type', InputTypeEnum::Text->value)
        ->assertJsonPath('data.image_url', null);

    expect(Analysis::count())->toBe(1);
    expect(Analysis::first()->user_id)->toBe($user->id);
    expect(Analysis::first()->id)->toBe($response->json('data.id'));
});

it('returns 422 when query is missing', function () {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $response = $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Text->value,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['query']);

    expect(Analysis::count())->toBe(0);
});

it('returns 422 when query exceeds 1000 characters', function () {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $response = $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Text->value,
        'query' => str_repeat('a', 1001),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['query']);

    expect(Analysis::count())->toBe(0);
});

it('returns 422 when input_type is invalid', function () {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $response = $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => 'audio',
        'query' => 'Some query',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['input_type']);

    expect(Analysis::count())->toBe(0);
});

it('returns 502 with error_code and message when the ProductReviewer fails, and does not persist anything', function () {
    $user = User::factory()->create();

    $fake = new class extends ProductReviewer
    {
        public function analyzeText(string $query): StructuredAgentResponse
        {
            throw new FailoverableException('Provider unavailable');
        }
    };

    $this->app->instance(ProductReviewer::class, $fake);

    $response = $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Text->value,
        'query' => 'Some product query',
    ]);

    $response->assertStatus(502)
        ->assertJsonStructure(['error_code', 'message']);

    expect(Analysis::count())->toBe(0);
});

it('persists the analysis with the seeded text input_type slug', function () {
    $user = User::factory()->create();
    $this->bindFakeProductReviewer();

    $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputTypeEnum::Text->value,
        'query' => 'Test query',
    ])->assertCreated();

    $analysis = Analysis::first();
    expect($analysis->inputType->slug)->toBe(InputTypeEnum::Text->value);
    expect($analysis->recommendationDecision->slug)->toBeIn(array_map(
        fn (RecommendationDecisionEnum $case) => $case->value,
        RecommendationDecisionEnum::cases(),
    ));
});
