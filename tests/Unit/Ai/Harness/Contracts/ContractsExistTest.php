<?php

use App\Ai\Harness\Contracts\CitationStore;
use App\Ai\Harness\Contracts\Reranker;
use App\Ai\Harness\Contracts\Retriever;

it('Retriever is an interface in App\\Ai\\Harness\\Contracts', function () {
    $reflection = new ReflectionClass(Retriever::class);
    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->getNamespaceName())->toBe('App\\Ai\\Harness\\Contracts');
});

it('Reranker is an interface in App\\Ai\\Harness\\Contracts', function () {
    $reflection = new ReflectionClass(Reranker::class);
    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->getNamespaceName())->toBe('App\\Ai\\Harness\\Contracts');
});

it('CitationStore is an interface in App\\Ai\\Harness\\Contracts', function () {
    $reflection = new ReflectionClass(CitationStore::class);
    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->getNamespaceName())->toBe('App\\Ai\\Harness\\Contracts');
});

it('no class outside App\\Ai\\Harness implements Retriever or Reranker', function () {
    $appPath = dirname(__DIR__, 5).'/app';
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace([$appPath.DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR], ['', '', '\\'], $file->getPathname());
        $fqcn = 'App\\'.$relative;

        if (! class_exists($fqcn)) {
            continue;
        }

        $reflection = new ReflectionClass($fqcn);

        if ($reflection->isInterface() || $reflection->isAbstract()) {
            continue;
        }

        $implements = array_keys($reflection->getInterfaces());
        $implementsTargeted = array_intersect($implements, [Retriever::class, Reranker::class]);

        if ($implementsTargeted !== [] && ! str_starts_with($fqcn, 'App\\Ai\\Harness\\')) {
            $offenders[] = $fqcn;
        }
    }

    expect($offenders)->toBe([]);
});
