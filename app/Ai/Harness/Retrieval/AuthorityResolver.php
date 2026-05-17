<?php

namespace App\Ai\Harness\Retrieval;

class AuthorityResolver
{
    public const DEFAULT_SCORE = 0.4;

    public function scoreFor(string $url): float
    {
        $host = $this->host($url);

        if ($host === '') {
            return self::DEFAULT_SCORE;
        }

        $map = (array) config('worthly.harness.authority', []);

        foreach ($map as $domain => $score) {
            $domain = strtolower((string) $domain);

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return (float) $score;
            }
        }

        return self::DEFAULT_SCORE;
    }

    protected function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return '';
        }

        return strtolower(preg_replace('/^www\./', '', $host) ?? $host);
    }
}
