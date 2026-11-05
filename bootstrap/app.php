<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            if ($e instanceof ValidationException) {
                return response()->json(['error' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
            }
            if ($e instanceof \Illuminate\Http\Exceptions\PostTooLargeException) {
                return response()->json(['error' => 'Upload exceeds the server limit. Try one file at a time within the displayed image or video limits.'], 413);
            }
            if ($e instanceof QueryException) {
                report($e);

                return response()->json(['error' => 'The database request could not be completed. Please check the database configuration.'], 503);
            }
            if ($e instanceof HttpExceptionInterface) {
                return response()->json(['error' => $e->getMessage() ?: 'Request could not be completed.'], $e->getStatusCode());
            }
            report($e);

            return response()->json(['error' => 'The request could not be completed. Please try again.'], 500);
        });
    })->create();
