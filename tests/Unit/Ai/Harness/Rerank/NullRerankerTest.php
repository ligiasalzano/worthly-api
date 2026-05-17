<?php

use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Rerank\NullReranker;
use App\Enums\Intent;

function nullRerankerQuery(): EnrichedQuery
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

function nullRerankerItem(string $title, float $rawRelevance = 0.5, string $channel = 'reviews'): EvidenceItem
{
    return new EvidenceItem(
        sourceChannel: $channel,
        url: 'https://example.test/'.urlencode($title),
        title: $title,
        snippet: 'snippet for '.$title,
        publishedAt: null,
        authorityScore: 0.8,
        rawRelevance: $rawRelevance,
    );
}

it('preserves input order and truncates the output to topK', function () {
    $items = [
        nullRerankerItem('A'),
        nullRerankerItem('B'),
        nullRerankerItem('C'),
        nullRerankerItem('D'),
        nullRerankerItem('E'),
    ];

    $reranked = (new NullReranker)->rerank(nullRerankerQuery(), $items, 3);

    expect($reranked)->toHaveCount(3);
    expect(array_map(fn ($i) => $i->title, $reranked))->toBe(['A', 'B', 'C']);
});

it('returns the full list when topK exceeds the number of items', function () {
    $items = [
        nullRerankerItem('A'),
        nullRerankerItem('B'),
    ];

    $reranked = (new NullReranker)->rerank(nullRerankerQuery(), $items, 10);

    expect(array_map(fn ($i) => $i->title, $reranked))->toBe(['A', 'B']);
});

it('returns an empty list when topK is zero or negative', function () {
    $items = [nullRerankerItem('A'), nullRerankerItem('B')];

    expect((new NullReranker)->rerank(nullRerankerQuery(), $items, 0))->toBe([]);
    expect((new NullReranker)->rerank(nullRerankerQuery(), $items, -1))->toBe([]);
});
