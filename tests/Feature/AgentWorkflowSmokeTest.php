<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Models\EmailJob;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * End-to-end smoke test for the agent workflow:
 *
 *   1. User has a forms-agent token.
 *   2. External agent POSTs HTML to /api/agent/forms.
 *   3. Agent receives form_url + embed_html back.
 *   4. A visitor submits the embed snippet (i.e. the embed_html).
 *   5. The submission lands in the DB + queues an email job.
 */
class AgentWorkflowSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_agent_workflow_end_to_end(): void
    {
        Queue::fake();

        // 1. Sign up a user.
        $user = User::factory()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
        ]);

        // 2. User generates a forms-agent token from the dashboard.
        $plain = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;
        $this->assertIsString($plain);

        // 3. AI agent POSTs an HTML snippet to /api/agent/forms.
        $snippet = <<<'HTML'
<form action="/x" method="POST">
    <label for="email">Email</label>
    <input id="email" type="email" name="email" required>
    <label for="message">Message</label>
    <textarea id="message" name="message" required></textarea>
    <button type="submit">Send</button>
</form>
HTML;

        $agentResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$plain,
            'Accept' => 'application/json',
        ])->post('/api/agent/forms', [
            'form_name' => 'contact',
            'html' => $snippet,
            'description' => 'Public contact form',
        ]);

        $agentResponse->assertCreated();
        $formUrl = $agentResponse->json('form_url');
        $embedHtml = $agentResponse->json('embed_html');
        $slug = $agentResponse->json('slug');

        $form = Form::query()->where('slug', 'contact')->firstOrFail();
        $this->assertSame(0, (int) $form->min_submission_seconds, 'min_submission_seconds should be 0 for the smoke test');

        $this->assertSame('contact', $slug);
        $this->assertStringContainsString('/api/submit/contact', $formUrl);
        $this->assertStringContainsString('name="_user_api"', $embedHtml);
        $this->assertStringContainsString($plain, $embedHtml);

        // 4. Visitor submits the embed snippet — hidden _user_api
        //    rides along with the form fields.
        $visitorPayload = [
            '_user_api' => $plain,
            'email' => 'visitor@example.com',
            'message' => 'Hello from the smoke test',
        ];

        $submissionResponse = $this->call(
            'POST',
            $formUrl,
            $visitorPayload,
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        // 5. The submission lands in the DB and queues an email job.
        $submissionResponse->assertCreated();
        $submissionResponse->assertJsonPath('submission.data.email', 'visitor@example.com');

        $this->assertDatabaseHas('form_submissions', [
            'form_id' => Form::query()->where('slug', 'contact')->firstOrFail()->id,
            'status' => SubmissionStatus::Received->value,
        ]);

        $this->assertSame(1, EmailJob::query()->count());
    }
}
