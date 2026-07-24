<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate every `/admin/*` route behind the `view-admin-panel` permission.
 *
 * The permission is granted by the `admin` role in the Spatie seeders.
 * Using a permission (not a role string) means future roles (e.g. a
 * read-only "support" role) can be granted partial admin access
 * without touching middleware.
 */
class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            // Should already be covered by the `auth` middleware, but
            // fail closed if the middleware order is ever reordered.
            abort(401);
        }

        if (! $user->can('view-admin-panel')) {
            abort(403);
        }

        return $next($request);
    }
}
