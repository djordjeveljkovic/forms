<?php

use App\Http\Controllers\Api\AgentDocsController;
use App\Http\Controllers\Api\AgentFormController;
use App\Http\Controllers\Api\SubmissionController;
use App\Http\Controllers\Api\SubmissionV2Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'forms-api',
]))->name('api.health');

Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum');

// AI-discoverable documentation. Both endpoints return the same body;
// the Markdown variant lives at /llms.txt (the emerging convention
// that most agent tooling auto-fetches), the JSON variant at
// /api/agent/docs for tooling that prefers structured responses.
Route::get('/llms.txt', [AgentDocsController::class, 'llms'])
    ->name('api.agent.llms');

Route::get('/agent/docs', [AgentDocsController::class, 'docs'])
    ->name('api.agent.docs');

// Agent-facing endpoints. The `agent.key` middleware resolves the
// calling user from the forms-agent token they carried (Authorization
// header, ?user_api=, or _user_api body field).
// NOTE: routes/api.php is auto-prefixed with `api/`, so the URL
// `/agent/forms` here resolves to `POST /api/agent/forms`.
Route::middleware(['agent.key'])->group(function (): void {
    Route::post('/agent/forms', [AgentFormController::class, 'store'])
        ->name('api.agent.forms.store');

    Route::post('/submit/{form:slug}', [SubmissionV2Controller::class, 'store'])
        ->middleware('throttle:forms')
        ->name('api.submit.store');
});

Route::prefix('forms/{form:slug}')->group(function (): void {
    Route::get('/', [SubmissionController::class, 'show'])
        ->middleware('form.key')
        ->name('api.forms.show');

    Route::post('/', [SubmissionController::class, 'store'])
        ->middleware(['throttle:forms', 'form.key'])
        ->name('api.forms.submissions.store');
});
