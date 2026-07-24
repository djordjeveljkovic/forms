<?php

use App\Livewire\Dashboard\AgentKey;
use App\Livewire\Dashboard\Analytics;
use App\Livewire\Dashboard\EmailJobs;
use App\Livewire\Dashboard\EmailJobShow;
use App\Livewire\Dashboard\FormCreate;
use App\Livewire\Dashboard\FormDemo;
use App\Livewire\Dashboard\FormEdit;
use App\Livewire\Dashboard\FormImport;
use App\Livewire\Dashboard\FormsIndex;
use App\Livewire\Dashboard\SubmissionShow;
use App\Livewire\Dashboard\SubmissionsIndex;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::redirect('dashboard', 'dashboard/')
        ->name('dashboard');

    Route::prefix('dashboard')->name('dashboard.')->group(function (): void {
        Route::get('/', Analytics::class)->name('index');

        Route::livewire('/forms', FormsIndex::class)->name('forms.index');
        Route::livewire('/forms/create', FormCreate::class)->name('forms.create');
        Route::livewire('/forms/import', FormImport::class)->name('forms.import');
        Route::livewire('/forms/{form}/edit', FormEdit::class)->name('forms.edit');
        Route::livewire('/forms/{form}/demo', FormDemo::class)->name('forms.demo');

        Route::livewire('/agent-key', AgentKey::class)->name('agent-key');

        Route::livewire('/submissions', SubmissionsIndex::class)->name('submissions.index');
        Route::livewire('/submissions/{submission}', SubmissionShow::class)->name('submissions.show');

        Route::livewire('/email-jobs', EmailJobs::class)->name('email-jobs.index');
        Route::livewire('/email-jobs/{job}', EmailJobShow::class)->name('email-jobs.show');
    });
});

require __DIR__.'/settings.php';
