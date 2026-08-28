<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $r, Closure $next, string ...$permissions): Response
    {
        abort_unless($r->user() && collect($permissions)->contains(fn ($p) => $r->user()->hasPermission($p)), 403, 'No tienes permiso para realizar esta acción.');

        return $next($r);
    }
}
