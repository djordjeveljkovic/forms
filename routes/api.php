<?php

use App\Http\Controllers\Api\SubmissionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'forms-api',
]))->name('api.health');

Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum');

Route::prefix('forms/{form:slug}')->group(function (): void {
    Route::get('/', [SubmissionController::class, 'show'])
        ->middleware('form.key')
        ->name('api.forms.show');

    Route::post('/', [SubmissionController::class, 'store'])
        ->middleware(['throttle:forms', 'form.key'])
        ->name('api.forms.submissions.store');
});
