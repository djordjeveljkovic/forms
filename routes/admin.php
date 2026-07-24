<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Livewire\Admin\AdminDashboard;
use App\Livewire\Admin\PlanCreate;
use App\Livewire\Admin\PlanEdit;
use App\Livewire\Admin\PlansIndex;
use App\Livewire\Admin\UserCreate;
use App\Livewire\Admin\UserEdit;
use App\Livewire\Admin\UserShow;
use App\Livewire\Admin\UsersIndex;
use Illuminate\Support\Facades\Route;

/*
 * Admin routes.
 *
 * Mounted under `/admin` and gated by the `auth` + `verified` + `admin`
 * middleware stack. The `admin` middleware checks the `view-admin-panel`
 * permission (granted to the `admin` role) so adding new roles to the
 * admin panel later does not require route changes.
 */
Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', AdminDashboard::class)->name('dashboard');

        Route::livewire('/users', UsersIndex::class)->name('users.index');
        Route::livewire('/users/create', UserCreate::class)->name('users.create');
        Route::livewire('/users/{user}', UserShow::class)->name('users.show');
        Route::livewire('/users/{user}/edit', UserEdit::class)->name('users.edit');

        Route::livewire('/plans', PlansIndex::class)->name('plans.index');
        Route::livewire('/plans/create', PlanCreate::class)->name('plans.create');
        Route::livewire('/plans/{plan}/edit', PlanEdit::class)->name('plans.edit');

        // Impersonation start requires admin permission. The matching
        // stop endpoint is intentionally registered outside the admin
        // middleware stack — by the time the admin is impersonating,
        // `auth()->user()` is the impersonated user (no admin role),
        // so the admin middleware would reject them.
        Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])
            ->name('users.impersonate.start');
    });

// Stop-impersonation must be reachable while acting as the
// impersonated user (who doesn't have admin permission). The
// controller enforces its own session check.
Route::middleware(['auth'])
    ->post('/admin/impersonate/stop', [ImpersonationController::class, 'stop'])
    ->name('admin.users.impersonate.stop');
