<?php

it('exposes a non-empty default llm model', function () {
    $model = config('worthly.llm.model');

    expect($model)->toBeString()->not->toBe('');
});

it('reads the llm model from the WORTHLY_LLM_MODEL env var', function () {
    $source = file_get_contents(config_path('worthly.php'));

    expect($source)
        ->toContain("env('WORTHLY_LLM_MODEL'")
        ->and($source)
        ->toMatch("/env\\('WORTHLY_LLM_MODEL'\\s*,\\s*'[^']+'\\s*\\)/");
});
