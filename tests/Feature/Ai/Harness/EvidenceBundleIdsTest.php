<?php

use App\Ai\Agents\ProductReviewer;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceBundle;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Enums\Intent;

function bundleItem(string $title, string $channel = 'reviews'): EvidenceItem
{
    return new EvidenceItem(
        sourceChannel: $channel,
        url: 'https://example.test/'.urlencode($title),
        title: $title,
        snippet: 'snippet for '.$title,
        publishedAt: null,
        authorityScore: 0.7,
        rawRelevance: 0.5,
    );
}

function bundleQuery(): EnrichedQuery
{
    return new EnrichedQuery(
        rawQuery: 'iPhone 15',
        productName: 'iPhone 15',
        brand: 'Apple',
        category: 'smartphone',
        region: 'BR',
        useCase: null,
        budgetHint: null,
        intent: Intent::BuyDecision,
    );
}

it('assigns S1..Sn IDs in final order for a bundle of 8 items', function () {
    $items = [];
    for ($i = 1; $i <= 8; $i++) {
        $items[] = bundleItem('item-'.$i);
    }

    $bundle = new EvidenceBundle($items);

    expect($bundle->ids())->toBe(['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8']);
    expect($bundle->count())->toBe(8);

    foreach ($items as $index => $item) {
        expect($bundle->idFor($item))->toBe('S'.($index + 1));
    }
});

it('idFor() is bijective — each item maps to exactly one unique ID', function () {
    $items = [
        bundleItem('a'),
        bundleItem('b'),
        bundleItem('c'),
        bundleItem('d'),
        bundleItem('e'),
        bundleItem('f'),
        bundleItem('g'),
        bundleItem('h'),
    ];

    $bundle = new EvidenceBundle($items);

    $assigned = [];
    foreach ($items as $item) {
        $id = $bundle->idFor($item);
        expect($assigned)->not->toContain($id);
        $assigned[] = $id;
    }

    expect($assigned)->toBe($bundle->ids());
    expect(array_unique($assigned))->toHaveCount(count($items));
});

it('idFor() throws when given an item not in the bundle', function () {
    $bundle = new EvidenceBundle([bundleItem('a'), bundleItem('b')]);

    $bundle->idFor(bundleItem('not-in-bundle'));
})->throws(InvalidArgumentException::class);

it('serializing the bundle to the agent prompt includes each ID exactly once', function () {
    $items = [];
    for ($i = 1; $i <= 8; $i++) {
        $items[] = bundleItem('item-'.$i);
    }
    $bundle = new EvidenceBundle($items);

    $reviewer = new ProductReviewer;
    $reflection = new ReflectionMethod($reviewer, 'buildGroundedPrompt');
    $reflection->setAccessible(true);

    $prompt = $reflection->invoke($reviewer, bundleQuery(), $bundle);

    foreach ($bundle->ids() as $id) {
        expect(substr_count($prompt, '['.$id.']'))->toBe(1);
    }
});
