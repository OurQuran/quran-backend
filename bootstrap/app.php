<?php

require_once __DIR__ . '/../app/Helpers.php';

use App\Http\Middleware\AcceptJsonHeader;
use App\Http\Middleware\ConvertEmptyStringsToNullInQuery;
use App\Http\Middleware\CorsMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(CorsMiddleware::class);
        $middleware->prepend(ConvertEmptyStringsToNullInQuery::class);
        $middleware->prepend(AcceptJsonHeader::class);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (HttpException $e, Request $request) {
            $message = $e->getMessage();
            if ($e->getStatusCode() == 404 && str_contains($message, 'App\Model')) {
                [$model, $id] = extractModelAndIdFromNotFoundMessage($message);
                $modelName = convertFromPascalCaseToNormalCase($model);
                $message = "No $modelName found";
            }

            return response()->json(['message' => $message], $e->getStatusCode());
        });
    })->create();
