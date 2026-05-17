<?php

use App\Ai\Harness\Retrieval\Clients\MercadoLivreClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('queries Mercado Livre without an Authorization header and normalizes the row shape', function () {
    Http::fake([
        MercadoLivreClient::BASE_URL.'/sites/MLB/search*' => Http::response([
            'results' => [
                [
                    'title' => 'Apple iPhone 15 128GB',
                    'permalink' => 'https://produto.mercadolivre.com.br/MLB-123',
                    'price' => 5999.0,
                ],
                [
                    'title' => 'Apple iPhone 15 Pro 256GB',
                    'permalink' => 'https://produto.mercadolivre.com.br/MLB-456',
                    'price' => 8999.0,
                ],
            ],
        ], 200),
    ]);

    $rows = (new MercadoLivreClient)->search(query: 'iPhone 15');

    Http::assertSent(function (Request $request) {
        return str_starts_with($request->url(), MercadoLivreClient::BASE_URL.'/sites/MLB/search')
            && ! $request->hasHeader('Authorization');
    });

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toMatchArray([
        'title' => 'Apple iPhone 15 128GB',
        'url' => 'https://produto.mercadolivre.com.br/MLB-123',
        'price' => '5999',
        'source' => 'mercadolivre',
    ]);
    expect($rows[1]['title'])->toBe('Apple iPhone 15 Pro 256GB');
});
