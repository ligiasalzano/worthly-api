<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->specPath = base_path('docs/openapi.yaml');
    $this->spec = Yaml::parseFile($this->specPath);
});

it('marks the legacy POST /api/analyses operation as deprecated', function () {
    $operation = $this->spec['paths']['/api/analyses']['post'] ?? null;

    expect($operation)
        ->toBeArray()
        ->toHaveKey('deprecated', true);
});

it('describes the legacy ProductReviewer shims in the deprecated operation description', function () {
    $description = $this->spec['paths']['/api/analyses']['post']['description'] ?? '';

    expect($description)
        ->toContain('analyzeText')
        ->toContain('analyzeImage');
});

it('serves the deprecated marker through the served openapi.yaml endpoint', function () {
    $response = $this->get('/api/openapi.yaml');
    $response->assertOk();

    $parsed = Yaml::parse($response->getContent());

    expect($parsed['paths']['/api/analyses']['post']['deprecated'] ?? null)->toBeTrue();
});
