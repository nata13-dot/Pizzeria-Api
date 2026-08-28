<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $user?->refresh()->load('branch');

        if (! $user?->active || ! $user->branch?->active) {
            $user?->tokens()->delete();
            abort(401, 'La cuenta o sucursal está inactiva.');
        }

        return $next($request);
    }
}
