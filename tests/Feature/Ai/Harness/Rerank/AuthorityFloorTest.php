<?php

use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Rerank\Filters\AuthorityFloorFilter;

function authorityItem(string $title, float $authority, string $channel): EvidenceItem
{
    return new EvidenceItem(
        sourceChannel: $channel,
        url: 'https://example.test/'.urlencode($title),
        title: $title,
        snippet: 'snippet for '.$title,
        publishedAt: null,
        authorityScore: $authority,
        rawRelevance: 0.5,
    );
}

it('drops items below 0.3 unless they are the only evidence for their channel', function () {
    $items = [
        authorityItem('review-strong', 0.95, 'reviews'),
        authorityItem('review-weak', 0.25, 'reviews'),
        authorityItem('shopping-weak', 0.20, 'shopping'),
    ];

    $kept = (new AuthorityFloorFilter)->apply($items);

    $titles = array_map(fn ($i) => $i->title, $kept);

    expect($titles)->toBe(['review-strong', 'shopping-weak']);
});

it('keeps items at or above the authority floor', function () {
    $items = [
        authorityItem('boundary', 0.30, 'reviews'),
        authorityItem('above', 0.50, 'shopping'),
    ];

    $kept = (new AuthorityFloorFilter)->apply($items);

    expect($kept)->toHaveCount(2);
});

it('drops every low-authority item when none of them is a sole-channel survivor', function () {
    $items = [
        authorityItem('a', 0.10, 'reviews'),
        authorityItem('b', 0.10, 'reviews'),
        authorityItem('c', 0.10, 'reviews'),
    ];

    expect((new AuthorityFloorFilter)->apply($items))->toBe([]);
});
