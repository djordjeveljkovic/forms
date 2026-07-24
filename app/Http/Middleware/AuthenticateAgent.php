<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate a request using the user's personal "forms-agent" key.
 *
 * Resolves the calling user from one of three sources, in priority order:
 *   1. `Authorization: Bearer forms_sk_…` (preferred — never written to logs)
 *   2. `?user_api=forms_sk_…` query string (needed for HTML form posts)
 *   3. `_user_api=forms_sk_…` body field (fallback for HTML form posts)
 *
 * The token MUST be a Sanctum personal access token whose name matches
 * `User::FORMS_AGENT_TOKEN_NAME`. Any other token (including the legacy
 * per-form `api_key`) is rejected, so the agent key is a strict
 * superset of capability and cannot be confused with the per-form key.
 */
class AuthenticateAgent
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $this->extractKey($request);

        if ($provided === null) {
            return $this->unauthenticated($request, 'Missing forms key.');
        }

        $token = PersonalAccessToken::findToken($provided);

        if ($token === null
            || $token->name !== User::FORMS_AGENT_TOKEN_NAME
            || ! ($token->tokenable instanceof User)
        ) {
            return $this->unauthenticated($request, 'Invalid or missing forms key.');
        }

        $user = $token->tokenable;

        // Bind the resolved user to the request so downstream controllers
        // can call $request->user().
        $request->setUserResolver(static fn (): User => $user);

        // Mirror Sanctum's authenticateAs() behaviour so the dashboard can
        // show "last used" on the AgentKey page.
        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    /**
     * Read the key from the request, in priority order: Authorization
     * header, query string, then body field.
     */
    protected function extractKey(Request $request): ?string
    {
        $authorization = $request->header('Authorization');
        if (is_string($authorization) && $authorization !== '') {
            $lower = strtolower($authorization);
            if (str_starts_with($lower, 'bearer ')) {
                $candidate = trim(substr($authorization, 7));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        $query = $request->query('user_api');
        if (is_string($query) && $query !== '') {
            return $query;
        }

        $body = $request->input('_user_api');
        if (is_string($body) && $body !== '') {
            return $body;
        }

        return null;
    }

    /**
     * Build a 401 response. Always JSON for `api/*` routes so
     * programmatic clients get a structured error.
     */
    protected function unauthenticated(Request $request, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], 401);
        }

        abort(401, $message);
    }
}
