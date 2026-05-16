<?php

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->specPath = base_path('docs/openapi.yaml');
    $this->spec = Yaml::parseFile($this->specPath);
});

it('exists and parses as valid YAML', function () {
    expect(is_file($this->specPath))->toBeTrue();
    expect($this->spec)->toBeArray()->toHaveKeys(['openapi', 'info', 'paths', 'components']);
});

it('declares a bearer auth security scheme', function () {
    expect($this->spec)
        ->toHaveKey('components.securitySchemes.bearerAuth.type', 'http')
        ->toHaveKey('components.securitySchemes.bearerAuth.scheme', 'bearer');
});

it('documents every endpoint registered in routes/api.php', function () {
    $specPaths = $this->spec['paths'] ?? [];

    $expectedRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteInstance $route) => str_starts_with($route->uri(), 'api/'))
        ->flatMap(function (RouteInstance $route) {
            $path = '/'.$route->uri();

            return collect($route->methods())
                ->reject(fn (string $method) => in_array($method, ['HEAD', 'OPTIONS'], true))
                ->map(fn (string $method) => [$path, strtolower($method)]);
        });

    expect($expectedRoutes)->not->toBeEmpty();

    foreach ($expectedRoutes as [$path, $method]) {
        expect(array_key_exists($path, $specPaths))
            ->toBeTrue("Missing path {$path} in OpenAPI spec");

        expect(array_key_exists($method, $specPaths[$path]))
            ->toBeTrue("Missing {$method} operation on {$path} in OpenAPI spec");
    }
});

it('AnalysisResource schema mentions every key returned by Phase 4.1', function () {
    $schema = $this->spec['components']['schemas']['AnalysisResource'] ?? null;

    expect($schema)->toBeArray()->toHaveKey('properties');

    $expectedKeys = [
        'id',
        'product',
        'summary',
        'similar_products',
        'cost_benefit_analysis',
        'recommendation',
        'input_type',
        'image_url',
        'created_at',
    ];

    foreach ($expectedKeys as $key) {
        expect($schema['properties'])->toHaveKey($key);
    }

    expect($schema['properties']['product']['properties'])
        ->toHaveKeys(['name', 'category', 'estimated_price_range']);

    expect($schema['properties']['recommendation']['properties'])
        ->toHaveKeys(['decision', 'reason']);

    expect($schema['properties']['similar_products']['items'])->toBeArray();
});

it('serves the spec at GET /api/openapi.yaml', function () {
    $response = $this->get('/api/openapi.yaml');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('yaml');

    $parsed = Yaml::parse($response->getContent());
    expect($parsed)->toBeArray()->toHaveKey('openapi');
});
