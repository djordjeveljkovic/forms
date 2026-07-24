<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * Toggle admin → user impersonation.
 *
 * Sessions are used as the storage mechanism so the impersonation
 * state survives across requests but is wiped on logout (see
 * `AppServiceProvider::registerImpersonationAuditHook`).
 */
class ImpersonationController extends Controller
{
    /**
     * Begin impersonating the given user.
     */
    public function start(Request $request, User $user): RedirectResponse
    {
        $caller = $request->user();

        abort_unless($caller !== null, 401);
        abort_unless($caller->can('impersonate-users'), 403);

        // Admins may impersonate other admins; nobody may impersonate
        // themselves.
        abort_if($caller->getKey() === $user->getKey(), 400, 'You cannot impersonate yourself.');

        AuditLog::query()->create([
            'user_id' => $caller->getKey(),
            'action' => 'admin.impersonation.started',
            'metadata' => [
                'impersonated_user_id' => $user->getKey(),
                'reason' => $request->input('reason'),
            ],
            'ip_address' => $request->ip(),
        ]);

        session(['impersonator_id' => $caller->getKey()]);

        Auth::login($user);

        return redirect()->route('dashboard.index');
    }

    /**
     * Stop impersonating and restore the original admin session.
     */
    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = session('impersonator_id');

        abort_unless($impersonatorId, 400, 'Not currently impersonating.');

        $impersonator = User::query()->find($impersonatorId);
        abort_unless($impersonator !== null, 400, 'Original admin no longer exists.');

        AuditLog::query()->create([
            'user_id' => $impersonatorId,
            'action' => 'admin.impersonation.stopped',
            'metadata' => [
                'impersonated_user_id' => $request->user()?->getKey(),
            ],
            'ip_address' => $request->ip(),
        ]);

        Auth::login($impersonator);
        session()->forget('impersonator_id');

        return redirect()->route('admin.users.index');
    }
}
