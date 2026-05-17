<?php

use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Rerank\Filters\ChannelDiversityFilter;

function diversityItem(string $title, string $channel, float $rerank): EvidenceItem
{
    return new EvidenceItem(
        sourceChannel: $channel,
        url: 'https://example.test/'.urlencode($title),
        title: $title,
        snippet: 'snippet for '.$title,
        publishedAt: null,
        authorityScore: 0.8,
        rawRelevance: 0.5,
        rerankScore: $rerank,
    );
}

it('promotes a shopping item into the top-K when no shopping is present', function () {
    $items = [
        diversityItem('review-1', 'reviews', 0.95),
        diversityItem('review-2', 'reviews', 0.90),
        diversityItem('review-3', 'reviews', 0.85),
        diversityItem('review-4', 'reviews', 0.80),
        diversityItem('shopping-1', 'shopping', 0.50),
    ];

    $kept = (new ChannelDiversityFilter)->apply($items, 4);

    expect($kept)->toHaveCount(4);

    $titles = array_map(fn ($i) => $i->title, $kept);

    expect($titles)->toBe(['review-1', 'review-2', 'review-3', 'shopping-1']);
});

it('returns items untouched when both required channels are already present in top-K', function () {
    $items = [
        diversityItem('review-1', 'reviews', 0.95),
        diversityItem('shopping-1', 'shopping', 0.90),
        diversityItem('review-2', 'reviews', 0.85),
        diversityItem('review-3', 'reviews', 0.80),
    ];

    $kept = (new ChannelDiversityFilter)->apply($items, 4);

    expect(array_map(fn ($i) => $i->title, $kept))
        ->toBe(['review-1', 'shopping-1', 'review-2', 'review-3']);
});

it('returns the input unchanged when the input does not exceed topK', function () {
    $items = [
        diversityItem('review-1', 'reviews', 0.95),
        diversityItem('review-2', 'reviews', 0.85),
    ];

    expect((new ChannelDiversityFilter)->apply($items, 4))->toHaveCount(2);
});

it('does not promote a required channel that is not present anywhere in the pool', function () {
    $items = [
        diversityItem('review-1', 'reviews', 0.95),
        diversityItem('review-2', 'reviews', 0.90),
        diversityItem('review-3', 'reviews', 0.85),
        diversityItem('review-4', 'reviews', 0.80),
        diversityItem('review-5', 'reviews', 0.70),
    ];

    $kept = (new ChannelDiversityFilter)->apply($items, 4);

    expect(array_map(fn ($i) => $i->title, $kept))
        ->toBe(['review-1', 'review-2', 'review-3', 'review-4']);
});
