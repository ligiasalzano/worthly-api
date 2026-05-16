<?php

use App\Exceptions\LlmProviderException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (LlmProviderException $e, Request $request): JsonResponse {
            return new JsonResponse(
                [
                    'error_code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ],
                JsonResponse::HTTP_BAD_GATEWAY,
            );
        });
    })->create();
