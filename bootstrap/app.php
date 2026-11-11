<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\ValidateQueryFilters;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'auth.apikey' => AuthenticateApiKey::class,
            'permission' => EnsureAdminPermission::class,
        ]);
        $middleware->api(append: [ValidateQueryFilters::class]);

        // API guests get 401 JSON. Do not send Horizon / web to a named "login" route.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::make(true, 'Unauthenticated.', ApiCode::UNAUTHENTICATED, ApiReason::UNAUTHENTICATED);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::make(
                    true,
                    'Validation failed.',
                    ApiCode::VALIDATION,
                    ApiReason::VALIDATION_ERROR,
                    $e->errors(),
                );
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::make(true, 'Resource not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
            }
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::make(true, 'Too many requests.', ApiCode::TOO_MANY, ApiReason::TOO_MANY_REQUESTS);
            }
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::make(true, 'You do not have permission to perform this action.', ApiCode::FORBIDDEN, ApiReason::FORBIDDEN);
            }
        });

        // Remaining HTTP errors (405, 413, 419, 503 maintenance...) with a fixed, safe message per status.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $status = $e->getStatusCode();
            $message = match ($status) {
                405 => 'This method is not allowed for this endpoint.',
                413 => 'The request is too large.',
                419 => 'Your session has expired. Please try again.',
                503 => 'Service temporarily unavailable. Please try again shortly.',
                default => $status >= 500 ? 'Something went wrong. Please try again.' : 'The request could not be processed.',
            };
            $reason = match (true) {
                $status === 503 => ApiReason::GATEWAY_UNAVAILABLE,
                $status >= 500 => ApiReason::SERVER_ERROR,
                default => ApiReason::INVALID,
            };

            return ApiResponse::make(true, $message, $status, $reason);
        });

        // Same id in the log entry and in the client response so support can find the trace.
        $errorId = function (): string {
            $request = request();

            if (! $request->attributes->has('error_id')) {
                $request->attributes->set('error_id', (string) Str::uuid());
            }

            return (string) $request->attributes->get('error_id');
        };

        $exceptions->context(fn () => [
            'error_id' => $errorId(),
            'method' => request()->method(),
            'path' => request()->path(),
        ]);

        // Anything else (SQL errors, code bugs, gateway SDK failures): reported to the log, never echoed to the client.
        $exceptions->render(function (Throwable $e, Request $request) use ($errorId) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $data = ['error_id' => $errorId()];

            if (config('app.debug') && app()->environment('local')) {
                $data['debug'] = [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                ];
            }

            return ApiResponse::make(
                true,
                'Something went wrong. Please try again or contact support with this reference: '.$data['error_id'],
                ApiCode::SERVER,
                ApiReason::SERVER_ERROR,
                $data,
            );
        });
    })->create();
