<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

class LlmProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode = 'llm_provider_error',
        string $message = 'The LLM provider failed to respond.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(
            [
                'error_code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
            JsonResponse::HTTP_BAD_GATEWAY,
        );
    }
}
