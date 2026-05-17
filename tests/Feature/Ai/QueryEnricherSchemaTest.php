<?php

use App\Ai\Agents\QueryEnricher;
use App\Enums\Intent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('schema contains every required key', function () {
    $schema = (new QueryEnricher)->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys([
        'raw_query',
        'product_name',
        'brand',
        'category',
        'region',
        'use_case',
        'budget_hint',
        'intent',
        'sub_queries',
        'hyde_passages',
    ]);
});

it('intent is enumerated over the four Intent cases', function () {
    $schema = (new QueryEnricher)->schema(new JsonSchemaTypeFactory);
    $intentSchema = $schema['intent']->toArray();

    $expected = array_map(fn (Intent $case) => $case->value, Intent::cases());

    expect($intentSchema['enum'])->toEqualCanonicalizing($expected);
    expect($intentSchema['enum'])->toHaveCount(4);
});

it('sub_queries enforces min/max bounds and string items', function () {
    config()->set('worthly.harness.query_enricher.sub_query_count', 4);

    $schema = (new QueryEnricher)->schema(new JsonSchemaTypeFactory);
    $subQueriesSchema = $schema['sub_queries']->toArray();

    expect($subQueriesSchema['type'])->toBe('array');
    expect($subQueriesSchema['minItems'])->toBe(4);
    expect($subQueriesSchema['maxItems'])->toBe(5);

    $itemsSchema = $subQueriesSchema['items'];
    if (is_object($itemsSchema)) {
        $itemsSchema = $itemsSchema->toArray();
    }
    expect($itemsSchema['type'])->toBe('string');
});

it('sub_queries minimum is clamped between 3 and 5 regardless of config', function () {
    config()->set('worthly.harness.query_enricher.sub_query_count', 2);
    $low = (new QueryEnricher)->schema(new JsonSchemaTypeFactory);
    expect($low['sub_queries']->toArray()['minItems'])->toBe(3);

    config()->set('worthly.harness.query_enricher.sub_query_count', 99);
    $high = (new QueryEnricher)->schema(new JsonSchemaTypeFactory);
    expect($high['sub_queries']->toArray()['minItems'])->toBe(5);
});
