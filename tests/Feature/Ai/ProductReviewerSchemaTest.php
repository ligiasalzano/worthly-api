<?php

use App\Ai\Agents\ProductReviewer;
use App\Enums\RecommendationDecision;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('schema contains every required key', function () {
    $agent = new ProductReviewer;
    $schema = $agent->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys([
        'product',
        'summary',
        'similar_products',
        'cost_benefit_analysis',
        'recommendation',
    ]);
});

it('product schema has required structure', function () {
    $agent = new ProductReviewer;
    $schema = $agent->schema(new JsonSchemaTypeFactory);
    $productSchema = $schema['product']->toArray();

    expect($productSchema['properties'])->toHaveKeys(['name', 'category', 'estimated_price_range']);
    expect($productSchema['required'])->toContain('name');
});

it('similar_products has maxItems of 5', function () {
    $agent = new ProductReviewer;
    $schema = $agent->schema(new JsonSchemaTypeFactory);
    $similarProductsSchema = $schema['similar_products']->toArray();

    expect($similarProductsSchema['maxItems'])->toBe(5);
});

it('recommendation decision enum lists exactly the cases of RecommendationDecision', function () {
    $agent = new ProductReviewer;
    $schema = $agent->schema(new JsonSchemaTypeFactory);
    $recommendationSchema = $schema['recommendation']->toArray();
    $decisionSchema = $recommendationSchema['properties']['decision'];

    if (is_object($decisionSchema)) {
        $decisionSchema = $decisionSchema->toArray();
    }

    $enumValues = $decisionSchema['enum'];
    $expectedValues = array_map(fn (RecommendationDecision $case) => $case->value, RecommendationDecision::cases());

    expect($enumValues)->toEqualCanonicalizing($expectedValues);
});
