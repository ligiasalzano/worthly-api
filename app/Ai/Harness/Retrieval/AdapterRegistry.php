<?php

namespace App\Ai\Harness\Retrieval;

use App\Ai\Harness\Contracts\Retriever;
use App\Ai\Harness\Retrieval\Adapters\GeneralWebRetriever;
use App\Ai\Harness\Retrieval\Adapters\ProfessionalReviewRetriever;
use App\Ai\Harness\Retrieval\Adapters\ShoppingRetriever;
use Illuminate\Contracts\Container\Container;

class AdapterRegistry
{
    /**
     * @var array<string, class-string<Retriever>>
     */
    public const ADAPTERS = [
        ShoppingRetriever::CHANNEL => ShoppingRetriever::class,
        ProfessionalReviewRetriever::CHANNEL => ProfessionalReviewRetriever::class,
        GeneralWebRetriever::CHANNEL => GeneralWebRetriever::class,
    ];

    public function __construct(protected Container $container) {}

    /**
     * @return list<Retriever>
     */
    public function enabled(): array
    {
        $list = [];

        foreach (self::ADAPTERS as $channel => $class) {
            if (! (bool) config("worthly.harness.retrievers.{$channel}.enabled", false)) {
                continue;
            }

            $list[] = $this->container->make($class);
        }

        return $list;
    }
}
