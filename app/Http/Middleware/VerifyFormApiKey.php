<?php

namespace App\Http\Middleware;

use App\Models\Form;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyFormApiKey
{
    /**
     * Handle an incoming request.
     *
     * Verifies the X-Form-Key header (or ?api_key=) matches the form's key.
     * The form is resolved by slug from the route parameter.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Form|null $form */
        $form = $request->route('form');

        if (! $form instanceof Form) {
            return response()->json(['message' => 'Form not found.'], 404);
        }

        $provided = $request->header('X-Form-Key')
            ?? $request->header('X-Api-Key')
            ?? $request->query('api_key');

        if (! $provided || ! hash_equals((string) $form->api_key, (string) $provided)) {
            return response()->json(['message' => 'Invalid or missing API key.'], 401);
        }

        return $next($request);
    }
}
