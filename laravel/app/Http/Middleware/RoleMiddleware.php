<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('login');
        }

        $role = strtolower((string) $user->role);
        $allowed = array_map('strtolower', $roles);

        if (!in_array($role, $allowed, true)) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
            }
            abort(403, 'Unauthorized');
        }

        return $next($request);
    }
}
