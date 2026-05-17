<?php

use App\Ai\Harness\Retrieval\AuthorityResolver;

it('resolves the configured score for each whitelisted high-authority domain', function (string $domain, float $expected) {
    $resolver = app(AuthorityResolver::class);

    expect($resolver->scoreFor("https://{$domain}/some/path"))->toBe($expected);
    expect($resolver->scoreFor("https://www.{$domain}/another"))->toBe($expected);
})->with([
    'rtings.com' => ['rtings.com', 0.95],
    'wirecutter.com' => ['wirecutter.com', 0.92],
    'techradar.com' => ['techradar.com', 0.85],
    'gsmarena.com' => ['gsmarena.com', 0.85],
    'tomshardware.com' => ['tomshardware.com', 0.88],
    'cnet.com' => ['cnet.com', 0.82],
]);

it('returns the Reddit baseline of 0.5', function () {
    $resolver = app(AuthorityResolver::class);

    expect($resolver->scoreFor('https://reddit.com/r/iphone'))->toBe(0.5);
    expect($resolver->scoreFor('https://www.reddit.com/r/iphone'))->toBe(0.5);
});

it('returns the default 0.4 for unknown domains', function () {
    $resolver = app(AuthorityResolver::class);

    expect($resolver->scoreFor('https://example.com/something'))->toBe(0.4);
    expect($resolver->scoreFor('https://random-blog.test/post'))->toBe(0.4);
});

it('exposes every Phase E domain in worthly.harness.authority', function () {
    $authority = (array) config('worthly.harness.authority');

    expect($authority)->toMatchArray([
        'rtings.com' => 0.95,
        'wirecutter.com' => 0.92,
        'techradar.com' => 0.85,
        'gsmarena.com' => 0.85,
        'tomshardware.com' => 0.88,
        'cnet.com' => 0.82,
        'reddit.com' => 0.5,
    ]);
});
