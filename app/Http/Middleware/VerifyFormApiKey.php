<?php

namespace App\Http\Middleware;

use App\Models\Form;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify the per-form `api_key` for requests to `/api/forms/{slug}`.
 *
 * The key may be presented in any of these forms:
 *   1. `X-Form-Key: …` header (preferred — never logged).
 *   2. `X-Api-Key: …` header.
 *   3. `?api_key=…` query string.
 *   4. `api_key=…` POST body field (for plain HTML form posts that
 *      embed the snippet with a hidden input).
 *
 * The form is resolved by slug from the route parameter; the route
 * is responsible for the slug binding.
 */
class VerifyFormApiKey
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Form|null $form */
        $form = $request->route('form');

        if (! $form instanceof Form) {
            return response()->json(['message' => 'Form not found.'], 404);
        }

        $provided = $this->extractKey($request);

        if (! $provided || ! hash_equals((string) $form->api_key, (string) $provided)) {
            return response()->json(['message' => 'Invalid or missing API key.'], 401);
        }

        return $next($request);
    }

    /**
     * Read the per-form api_key from the request, in priority order:
     * header (X-Form-Key / X-Api-Key), query string, then POST body.
     */
    protected function extractKey(Request $request): ?string
    {
        $header = $request->header('X-Form-Key') ?? $request->header('X-Api-Key');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $query = $request->query('api_key');
        if (is_string($query) && $query !== '') {
            return $query;
        }

        // POST body field — used by the embed snippet generated for
        // agent-created forms. Plain HTML forms can only send body
        // fields, never headers, so this fallback is what makes
        // "drop the snippet on a static site" work.
        $body = $request->input('api_key');
        if (is_string($body) && $body !== '') {
            return $body;
        }

        return null;
    }
}
