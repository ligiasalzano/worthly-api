<?php

use App\Ai\Harness\Retrieval\Clients\SearchApiClient;
use Illuminate\Http\Client\Request;
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

it('hits SearchApi google_shopping engine with api_key and decodes results', function () {
    Http::fake([
        SearchApiClient::BASE_URL.'*' => Http::response([
            'shopping_results' => [
                [
                    'title' => 'iPhone 15 128GB',
                    'product_link' => 'https://example.com/iphone',
                    'price' => 'R$ 6.000',
                    'source' => 'Magalu',
                ],
                [
                    'title' => 'iPhone 15 256GB',
                    'link' => 'https://other.com/iphone',
                    'extracted_price' => 7000,
                    'seller' => 'Casas Bahia',
                ],
            ],
        ], 200),
    ]);

    $rows = (new SearchApiClient)->shoppingSearch(query: 'iPhone 15 preço');

    Http::assertSent(function (Request $request) {
        $url = $request->url();

        return str_starts_with($url, SearchApiClient::BASE_URL)
            && str_contains($url, 'engine=google_shopping')
            && str_contains($url, 'api_key=sa-fake-token')
            && str_contains($url, 'q=iPhone%2015%20pre%C3%A7o');
    });

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toMatchArray([
        'title' => 'iPhone 15 128GB',
        'url' => 'https://example.com/iphone',
        'price' => 'R$ 6.000',
        'source' => 'Magalu',
    ]);
    expect($rows[1]['title'])->toBe('iPhone 15 256GB');
    expect($rows[1]['url'])->toBe('https://other.com/iphone');
    expect($rows[1]['source'])->toBe('Casas Bahia');
});
