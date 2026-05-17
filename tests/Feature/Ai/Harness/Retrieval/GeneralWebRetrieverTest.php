<?php

use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\RetrievalContext;
use App\Ai\Harness\Retrieval\Adapters\GeneralWebRetriever;
use App\Ai\Harness\Retrieval\Clients\TavilyClient;
use App\Enums\Intent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->previousTavilyToken = Env::getRepository()->get('TAVILY_TOKEN');
    Env::getRepository()->set('TAVILY_TOKEN', 'tvly-fake-token');

    config()->set('worthly.harness.authority', [
        'rtings.com' => 0.95,
    ]);
});

afterEach(function () {
    if ($this->previousTavilyToken === false) {
        Env::getRepository()->clear('TAVILY_TOKEN');
    } else {
        Env::getRepository()->set('TAVILY_TOKEN', $this->previousTavilyToken);
    }
});

it('does not send include_domains, tags items with the general channel, and defaults unknown-domain authority to 0.4', function () {
    Http::fake([
        TavilyClient::BASE_URL.'/search' => Http::response([
            'results' => [
                ['url' => 'https://random-blog.example/post', 'title' => 'Random review', 'content' => 'words', 'score' => 0.6],
                ['url' => 'https://rtings.com/some-page', 'title' => 'RTINGS opinion', 'content' => 'words', 'score' => 0.7],
            ],
        ], 200),
    ]);

    $items = (new GeneralWebRetriever(app(TavilyClient::class)))->retrieve(
        new EnrichedQuery(
            rawQuery: 'iPhone 15',
            productName: 'iPhone 15',
            brand: 'Apple',
            category: 'smartphone',
            region: 'BR',
            useCase: null,
            budgetHint: null,
            intent: Intent::BuyDecision,
        ),
        new RetrievalContext,
    );

    Http::assertSent(function (Request $request) {
        return ! array_key_exists('include_domains', $request->data());
    });

    expect($items)->toHaveCount(2);
    foreach ($items as $item) {
        expect($item->sourceChannel)->toBe('general');
    }

    $byUrl = collect($items)->keyBy(fn ($i) => $i->url);
    expect($byUrl['https://random-blog.example/post']->authorityScore)->toBe(0.4);
    expect($byUrl['https://rtings.com/some-page']->authorityScore)->toBe(0.95);
});
