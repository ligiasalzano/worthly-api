<?php

use App\Ai\Agents\ProductReviewer;
use App\Enums\InputType;
use App\Models\Analysis;
use App\Models\User;
use Database\Seeders\InputTypeSeeder;
use Database\Seeders\RecommendationDecisionSeeder;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Responses\StructuredAgentResponse;

it('returns the Laravel validation error contract on 422', function () {
    $this->seed([InputTypeSeeder::class, RecommendationDecisionSeeder::class]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/analyses', []);

    $response->assertStatus(422)
        ->assertJsonStructure([
            'message',
            'errors' => ['input_type'],
        ]);

    expect($response->json('errors.input_type'))->toBeArray()->not->toBeEmpty();
    expect($response->json('errors.input_type.0'))->toBeString();
});

it('returns the Unauthenticated shape on a protected route without a token', function () {
    $response = $this->getJson('/api/me');

    $response->assertStatus(401)
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('returns the generic Not Found shape on a missing analysis', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/analyses/999999');

    $response->assertStatus(404)
        ->assertExactJson(['message' => 'Not Found.']);
});

it('returns the generic Not Found shape on an unknown route under /api', function () {
    $response = $this->getJson('/api/this-route-does-not-exist');

    $response->assertStatus(404)
        ->assertExactJson(['message' => 'Not Found.']);
});

it('does not leak model names when route-binding a non-existing record', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/analyses/999999');

    $body = $response->getContent();

    expect($body)
        ->not->toContain('App\\Models\\Analysis')
        ->and($body)->not->toContain('No query results');
});

it('returns error_code/message with no stack trace or SDK type names on 5xx', function () {
    $this->seed([InputTypeSeeder::class, RecommendationDecisionSeeder::class]);
    $user = User::factory()->create();

    $fake = new class extends ProductReviewer
    {
        public function analyzeText(string $query): StructuredAgentResponse
        {
            throw new FailoverableException('SDK internal detail that must not leak');
        }
    };
    $this->app->instance(ProductReviewer::class, $fake);

    $response = $this->actingAs($user)->postJson('/api/analyses', [
        'input_type' => InputType::Text->value,
        'query' => 'Some product',
    ]);

    $response->assertStatus(502)
        ->assertJsonStructure(['error_code', 'message']);

    $payload = $response->json();
    expect(array_keys($payload))->toEqualCanonicalizing(['error_code', 'message']);

    $body = $response->getContent();
    expect($body)
        ->not->toContain('trace')
        ->and($body)->not->toContain('exception')
        ->and($body)->not->toContain('SDK internal detail')
        ->and($body)->not->toContain('Laravel\\Ai')
        ->and($body)->not->toContain('FailoverableException');

    expect(Analysis::count())->toBe(0);
});
