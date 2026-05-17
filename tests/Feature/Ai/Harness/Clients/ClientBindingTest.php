<?php

use App\Ai\Harness\Retrieval\Clients\MercadoLivreClient;
use App\Ai\Harness\Retrieval\Clients\SearchApiClient;
use App\Ai\Harness\Retrieval\Clients\TavilyClient;

it('binds TavilyClient as a singleton', function () {
    $first = app(TavilyClient::class);
    $second = app(TavilyClient::class);

    expect($first)->toBe($second);
});

it('binds SearchApiClient as a singleton', function () {
    $first = app(SearchApiClient::class);
    $second = app(SearchApiClient::class);

    expect($first)->toBe($second);
});

it('binds MercadoLivreClient as a singleton', function () {
    $first = app(MercadoLivreClient::class);
    $second = app(MercadoLivreClient::class);

    expect($first)->toBe($second);
});

it('lets tests override each client via instance()', function () {
    foreach ([TavilyClient::class, SearchApiClient::class, MercadoLivreClient::class] as $class) {
        $fake = new $class;
        app()->instance($class, $fake);

        expect(app($class))->toBe($fake);
    }
});
