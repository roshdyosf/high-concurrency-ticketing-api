<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $group): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new AuthenticationException();
        }

        $allowed = match ($group) {
            'admin' => $user->role === UserRole::Admin,
            'organizer' => $user->role === UserRole::Organizer,
            'customer' => in_array($user->role, [UserRole::Customer, UserRole::Organizer], true),
            default => throw new InvalidArgumentException("Unknown role group [{$group}]."),
        };

        if (! $allowed) {
            return $this->deny('FORBIDDEN_ROLE', 'You do not have permission to access this resource.');
        }

        if ($group === 'organizer' && ! $user->is_approved) {
            return $this->deny('ORGANIZER_NOT_APPROVED', 'Your organizer request has not been approved yet.');
        }

        return $next($request);
    }

    private function deny(string $code, string $message): Response
    {
        return response()->json(['code' => $code, 'message' => $message], 403);
    }
}
