<?php

use App\Ai\Harness\Retrieval\Clients\TavilyClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->previousTavilyToken = Env::getRepository()->get('TAVILY_TOKEN');
    Env::getRepository()->set('TAVILY_TOKEN', 'tvly-fake-token');
});

afterEach(function () {
    if ($this->previousTavilyToken === false) {
        Env::getRepository()->clear('TAVILY_TOKEN');
    } else {
        Env::getRepository()->set('TAVILY_TOKEN', $this->previousTavilyToken);
    }
});

it('posts to the Tavily search endpoint with Authorization header and include_domains', function () {
    Http::fake([
        TavilyClient::BASE_URL.'/search' => Http::response(['results' => []], 200),
    ]);

    (new TavilyClient)->search(
        query: 'iPhone 15 review',
        includeDomains: ['rtings.com', 'wirecutter.com'],
        maxResults: 5,
    );

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->method() === 'POST'
            && str_starts_with($request->url(), TavilyClient::BASE_URL.'/search')
            && $request->hasHeader('Authorization', 'Bearer tvly-fake-token')
            && $body['query'] === 'iPhone 15 review'
            && $body['include_domains'] === ['rtings.com', 'wirecutter.com']
            && $body['max_results'] === 5;
    });
});

it('omits include_domains when none are provided', function () {
    Http::fake([
        TavilyClient::BASE_URL.'/search' => Http::response(['results' => []], 200),
    ]);

    (new TavilyClient)->search(query: 'iPhone 15');

    Http::assertSent(function (Request $request) {
        return ! array_key_exists('include_domains', $request->data());
    });
});
