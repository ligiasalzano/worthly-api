<?php

use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceBundle;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Dto\PipelineResult;
use App\Ai\Harness\Dto\RetrievalContext;
use App\Ai\Harness\Dto\VerificationReport;
use App\Enums\Intent;
use Carbon\CarbonImmutable;

it('DTOs are final readonly classes', function (string $class) {
    $reflection = new ReflectionClass($class);

    expect($reflection->isFinal())->toBeTrue("{$class} must be final");
    expect($reflection->isReadOnly())->toBeTrue("{$class} must be readonly");
})->with([
    EnrichedQuery::class,
    EvidenceItem::class,
    EvidenceBundle::class,
    PipelineResult::class,
    RetrievalContext::class,
    VerificationReport::class,
]);

it('EnrichedQuery constructor parameter types match spec', function () {
    $reflection = new ReflectionClass(EnrichedQuery::class);
    $params = $reflection->getConstructor()->getParameters();

    $byName = collect($params)->keyBy(fn ($p) => $p->getName());

    expect((string) $byName['rawQuery']->getType())->toBe('string');
    expect((string) $byName['productName']->getType())->toBe('?string');
    expect((string) $byName['brand']->getType())->toBe('?string');
    expect((string) $byName['category']->getType())->toBe('?string');
    expect((string) $byName['region']->getType())->toBe('?string');
    expect((string) $byName['useCase']->getType())->toBe('?string');
    expect((string) $byName['budgetHint']->getType())->toBe('?string');
    expect((string) $byName['intent']->getType())->toBe(Intent::class);
    expect((string) $byName['subQueries']->getType())->toBe('array');
    expect((string) $byName['hydePassages']->getType())->toBe('array');
});

it('EvidenceItem constructor parameter types match spec', function () {
    $reflection = new ReflectionClass(EvidenceItem::class);
    $params = $reflection->getConstructor()->getParameters();
    $byName = collect($params)->keyBy(fn ($p) => $p->getName());

    expect((string) $byName['sourceChannel']->getType())->toBe('string');
    expect((string) $byName['url']->getType())->toBe('string');
    expect((string) $byName['title']->getType())->toBe('string');
    expect((string) $byName['snippet']->getType())->toBe('string');
    expect((string) $byName['publishedAt']->getType())->toBe('?'.CarbonImmutable::class);
    expect((string) $byName['authorityScore']->getType())->toBe('float');
    expect((string) $byName['rawRelevance']->getType())->toBe('float');
});

it('EvidenceBundle exposes stable integer IDs via idFor()', function () {
    $a = new EvidenceItem('reviews', 'https://x', 'A', 'snippet a', null, 0.9, 0.8);
    $b = new EvidenceItem('shopping', 'https://y', 'B', 'snippet b', null, 0.7, 0.6);
    $c = new EvidenceItem('general', 'https://z', 'C', 'snippet c', null, 0.5, 0.4);

    $bundle = new EvidenceBundle([$a, $b, $c]);

    expect($bundle->idFor($a))->toBe('S1');
    expect($bundle->idFor($b))->toBe('S2');
    expect($bundle->idFor($c))->toBe('S3');
    expect($bundle->ids())->toBe(['S1', 'S2', 'S3']);
});

it('Intent enum is a string-backed enum with the expected cases', function () {
    expect(Intent::BuyDecision)->toBeInstanceOf(Intent::class);
    expect(Intent::Compare)->toBeInstanceOf(Intent::class);
    expect(Intent::SpecLookup)->toBeInstanceOf(Intent::class);
    expect(Intent::Unknown)->toBeInstanceOf(Intent::class);
});
