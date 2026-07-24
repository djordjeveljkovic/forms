<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps the impersonator's user id onto audit log entries written
 * while an admin is impersonating another user.
 *
 * Registered as a global `creating` listener on the AuditLog model
 * via the ServiceProvider — it intercepts the Eloquent creating event
 * and only runs if a session-level `impersonator_id` is set.
 */
class RecordImpersonator
{
    /**
     * Handle an incoming request.
     *
     * The actual Eloquent-event listening is wired up in
     * `AppServiceProvider::bootImpersonation()`. This middleware
     * exists so the impersonation banner middleware can sit alongside
     * it in the global stack if we need to enrich the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Mutate an AuditLog model that is about to be created: if the
     * session has an `impersonator_id`, stamp it into `metadata`.
     */
    public static function stampAuditLog(Model $auditLog): void
    {
        $impersonatorId = session('impersonator_id');

        if ($impersonatorId === null) {
            return;
        }

        $metadata = $auditLog->metadata ?? [];
        $metadata['impersonator_id'] = (int) $impersonatorId;

        $auditLog->metadata = $metadata;
    }
}
