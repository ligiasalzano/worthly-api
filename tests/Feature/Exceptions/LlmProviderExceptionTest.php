<?php

use App\Exceptions\LlmProviderException;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/__test__/llm-failure', function () {
        throw new LlmProviderException(
            errorCode: 'llm_provider_unavailable',
            message: 'The LLM provider failed to respond.',
            previous: new RuntimeException('Underlying SDK detail that must not leak'),
        );
    });
});

it('renders as 502 with error_code and message shape', function () {
    $response = $this->getJson('/__test__/llm-failure');

    $response->assertStatus(502)
        ->assertExactJson([
            'error_code' => 'llm_provider_unavailable',
            'message' => 'The LLM provider failed to respond.',
        ]);
});

it('does not leak stack traces or SDK-internal types', function () {
    $response = $this->getJson('/__test__/llm-failure');

    $payload = $response->json();

    expect($payload)
        ->toHaveKeys(['error_code', 'message'])
        ->and(array_keys($payload))->toEqualCanonicalizing(['error_code', 'message']);

    $body = $response->getContent();

    expect($body)
        ->not->toContain('Underlying SDK detail')
        ->and($body)->not->toContain('trace')
        ->and($body)->not->toContain('Laravel\\Ai')
        ->and($body)->not->toContain('RuntimeException');
});
