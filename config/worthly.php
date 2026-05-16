<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LLM Configuration
    |--------------------------------------------------------------------------
    |
    | Defines which model the Laravel AI SDK Agents will use when running
    | product analysis prompts. Override via the WORTHLY_LLM_MODEL env var.
    |
    */

    'llm' => [
        'model' => env('WORTHLY_LLM_MODEL', 'gpt-5.5'),
    ],

];
