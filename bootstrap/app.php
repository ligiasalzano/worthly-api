<?php

use App\Exceptions\LlmProviderException;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);
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

        $exceptions->render(function (NotFoundHttpException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse(['message' => 'Not Found.'], JsonResponse::HTTP_NOT_FOUND);
        });

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof LlmProviderException
                || $e instanceof NotFoundHttpException
                || $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException
            ) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : JsonResponse::HTTP_INTERNAL_SERVER_ERROR;

            if ($status < 500) {
                return null;
            }

            return new JsonResponse(
                [
                    'error_code' => 'server_error',
                    'message' => 'An unexpected error occurred.',
                ],
                $status,
            );
        });
    })->create();
