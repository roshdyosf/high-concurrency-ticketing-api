<?php

namespace App\Http\Middleware;

use App\Exceptions\AccountBannedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_banned) {
            throw new AccountBannedException();
        }

        return $next($request);
    }
}
