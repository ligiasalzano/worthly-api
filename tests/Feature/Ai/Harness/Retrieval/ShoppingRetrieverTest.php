<?php

use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\RetrievalContext;
use App\Ai\Harness\Retrieval\Adapters\ShoppingRetriever;
use App\Ai\Harness\Retrieval\Clients\MercadoLivreClient;
use App\Ai\Harness\Retrieval\Clients\SearchApiClient;
use App\Enums\Intent;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->previousSearchToken = Env::getRepository()->get('SEARCH_TOKEN');
    Env::getRepository()->set('SEARCH_TOKEN', 'sa-fake-token');
});

afterEach(function () {
    if ($this->previousSearchToken === false) {
        Env::getRepository()->clear('SEARCH_TOKEN');
    } else {
        Env::getRepository()->set('SEARCH_TOKEN', $this->previousSearchToken);
    }
});

function shoppingEnriched(): EnrichedQuery
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
        subQueries: ['iPhone 15 preço'],
        hydePassages: [],
    );
}

it('calls SearchApi and Mercado Livre exactly once and merges results dedup by URL ordered by rawRelevance', function () {
    Http::fake([
        SearchApiClient::BASE_URL.'*' => Http::response([
            'shopping_results' => [
                ['title' => 'iPhone 15 128GB at Magalu', 'product_link' => 'https://magalu.com/iphone', 'price' => '5999', 'source' => 'Magalu'],
                ['title' => 'iPhone 15 ML link', 'product_link' => 'https://mercadolivre.com/MLB-1', 'price' => '6000', 'source' => 'ML'],
            ],
        ], 200),
        MercadoLivreClient::BASE_URL.'/sites/MLB/search*' => Http::response([
            'results' => [
                ['title' => 'iPhone 15 ML link', 'permalink' => 'https://mercadolivre.com/MLB-1', 'price' => 6000],
                ['title' => 'iPhone 15 Pro ML link', 'permalink' => 'https://mercadolivre.com/MLB-2', 'price' => 8999],
            ],
        ], 200),
    ]);

    $items = (new ShoppingRetriever)->retrieve(shoppingEnriched(), new RetrievalContext);

    Http::assertSentCount(2);

    Http::assertSent(fn ($req) => str_starts_with($req->url(), SearchApiClient::BASE_URL));
    Http::assertSent(fn ($req) => str_starts_with($req->url(), MercadoLivreClient::BASE_URL.'/sites/MLB/search'));

    expect($items)->toHaveCount(3);

    $urls = array_map(fn ($i) => $i->url, $items);
    expect($urls)->toContain('https://magalu.com/iphone');
    expect($urls)->toContain('https://mercadolivre.com/MLB-1');
    expect($urls)->toContain('https://mercadolivre.com/MLB-2');

    for ($i = 1; $i < count($items); $i++) {
        expect($items[$i - 1]->rawRelevance)->toBeGreaterThanOrEqual($items[$i]->rawRelevance);
    }
});
