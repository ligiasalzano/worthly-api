<?php

namespace App\Providers;

use App\Ai\Harness\Retrieval\AdapterRegistry;
use App\Ai\Harness\Retrieval\Clients\MercadoLivreClient;
use App\Ai\Harness\Retrieval\Clients\SearchApiClient;
use App\Ai\Harness\Retrieval\Clients\TavilyClient;
use App\Ai\Harness\Retrieval\RetrievalRouter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TavilyClient::class);
        $this->app->singleton(SearchApiClient::class);
        $this->app->singleton(MercadoLivreClient::class);

        $this->app->bind(RetrievalRouter::class, function ($app) {
            $registry = $app->make(AdapterRegistry::class);

            return new RetrievalRouter($registry->enabled());
        });
    }

    public function boot(): void
    {
        //
    }
}
