<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \App\Http\Middleware\CorsMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $exception) {
            $messages = collect($exception->errors())
                ->flatten()
                ->values();

            return response()->json([
                'message' => 'Error de validacion.',
                'errors' => $messages,
            ], 422);
        });

        $exceptions->render(function (QueryException $exception) {
            return response()->json([
                'message' => 'No se pudo completar la operacion por una restriccion de la base de datos.',
            ], 409);
        });

        $exceptions->render(function (HttpExceptionInterface $exception) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'No se pudo completar la solicitud.',
            ], $exception->getStatusCode());
        });
    })->create();
