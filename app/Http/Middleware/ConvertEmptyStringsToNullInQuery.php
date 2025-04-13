<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConvertEmptyStringsToNullInQuery
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next)
    {
        $request->query->replace(
            collect($request->query->all())
                ->map(fn($value) => $value === '' ? null : $value)
                ->toArray()
        );

        return $next($request);
    }
}
