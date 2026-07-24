<?php

namespace Tests\Feature\Dashboard;

use App\Enums\SubmissionStatus;
use App\Livewire\Dashboard\SubmissionShow;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubmissionShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_displays_submission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->ownedBy($user)->create();

        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
            'submission_data' => ['name' => 'Jane', 'message' => 'Hello world'],
        ]);

        $this->get(route('dashboard.submissions.show', $submission))->assertOk();
    }

    public function test_mount_marks_submission_as_read(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->ownedBy($user)->create();

        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
            'status' => SubmissionStatus::Received->value,
        ]);

        Livewire::test(SubmissionShow::class, ['submission' => $submission]);

        $this->assertSame(SubmissionStatus::Read->value, $submission->fresh()->status);
        $this->assertNotNull($submission->fresh()->read_at);
    }

    public function test_mark_spam(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->ownedBy($user)->create();

        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
        ]);

        Livewire::test(SubmissionShow::class, ['submission' => $submission])
            ->call('markSpam')
            ->assertHasNoErrors();

        $this->assertSame(SubmissionStatus::Spam->value, $submission->fresh()->status);
    }

    public function test_archive(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->ownedBy($user)->create();

        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
        ]);

        Livewire::test(SubmissionShow::class, ['submission' => $submission])
            ->call('archive')
            ->assertHasNoErrors();

        $this->assertSame(SubmissionStatus::Archived->value, $submission->fresh()->status);
    }

    public function test_delete(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Form::factory()->ownedBy($user)->create();

        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
        ]);

        Livewire::test(SubmissionShow::class, ['submission' => $submission])
            ->call('delete')
            ->assertHasNoErrors();

        $this->assertNull(FormSubmission::query()->find($submission->id));
    }
}
