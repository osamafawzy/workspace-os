<?php

use App\Exceptions\DuplicateValue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A value the database will not take twice is a thing to be told, not a
        // page of stack trace. The screens check what they can before saving;
        // this catches the race and anything they missed. On the command line
        // it stays as it is, where the trace is what somebody wants.
        $exceptions->map(fn (UniqueConstraintViolationException $exception) => app()->runningInConsole() && ! app()->runningUnitTests()
            ? $exception
            : DuplicateValue::asValidation($exception));
    })->create();
