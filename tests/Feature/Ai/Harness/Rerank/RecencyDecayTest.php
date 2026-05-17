<?php

use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Rerank\Filters\RecencyDecayFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

function recencyItem(string $title, string $channel, ?CarbonImmutable $publishedAt): EvidenceItem
{
    return new EvidenceItem(
        sourceChannel: $channel,
        url: 'https://example.test/'.urlencode($title),
        title: $title,
        snippet: 'snippet for '.$title,
        publishedAt: $publishedAt,
        authorityScore: 0.8,
        rawRelevance: 0.5,
    );
}

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-05-17 12:00:00'));
});

afterEach(function () {
    Date::setTestNow();
});

it('drops shopping items older than 60 days but leaves reviews untouched', function () {
    $now = CarbonImmutable::parse('2026-05-17 12:00:00');

    $items = [
        recencyItem('shopping-recent', 'shopping', $now->subDays(30)),
        recencyItem('shopping-old', 'shopping', $now->subDays(90)),
        recencyItem('review-old', 'reviews', $now->subDays(400)),
    ];

    $kept = (new RecencyDecayFilter)->apply($items);

    $titles = array_map(fn ($i) => $i->title, $kept);

    expect($titles)->toBe(['shopping-recent', 'review-old']);
});

it('keeps shopping items without a publishedAt date', function () {
    $items = [
        recencyItem('shopping-dateless', 'shopping', null),
        recencyItem('shopping-recent', 'shopping', CarbonImmutable::parse('2026-05-17')->subDays(5)),
    ];

    $kept = (new RecencyDecayFilter)->apply($items);

    expect($kept)->toHaveCount(2);
});

it('does not touch non-shopping channels even if very old', function () {
    $items = [
        recencyItem('review-ancient', 'reviews', CarbonImmutable::parse('2010-01-01')),
        recencyItem('general-ancient', 'general', CarbonImmutable::parse('2010-01-01')),
    ];

    expect((new RecencyDecayFilter)->apply($items))->toHaveCount(2);
});
