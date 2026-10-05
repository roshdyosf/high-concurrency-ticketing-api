<?php

namespace App\Http\Middleware;

use App\Exceptions\EmailNotVerifiedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException();
        }

        return $next($request);
    }
}
