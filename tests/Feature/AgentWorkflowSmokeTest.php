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
 *   1. User signs in.
 *   2. User generates a forms-agent token (the creation-only key).
 *   3. External agent POSTs an HTML snippet to /api/agent/forms
 *      authenticated with the forms-agent token.
 *   4. Agent receives form_url + per-form api_key + embed_html.
 *   5. Visitor submits the embed snippet — the snippet carries the
 *      per-form api_key as a hidden body field, and posts to the
 *      legacy /api/forms/{slug} endpoint.
 *   6. The submission lands in the DB and queues an email job.
 *
 * Verifies that:
 *   - The forms-agent token authenticates the create-form request.
 *   - The per-form api_key returned by the response authenticates
 *     the submission (the forms-agent token is NOT used for it).
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

        // 2. User generates a forms-agent (creation-only) token.
        $formsAgentKey = $user->createToken(User::FORMS_AGENT_TOKEN_NAME, ['*'])->plainTextToken;
        $this->assertIsString($formsAgentKey);

        // 3. AI agent POSTs an HTML snippet, authenticated with the
        //    forms-agent key. The agent does NOT have a per-form key
        //    yet — that comes back in the response.
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
            'Authorization' => 'Bearer '.$formsAgentKey,
            'Accept' => 'application/json',
        ])->post('/api/agent/forms', [
            'form_name' => 'contact',
            'html' => $snippet,
            'description' => 'Public contact form',
        ]);

        $agentResponse->assertCreated();
        $formUrl = $agentResponse->json('form_url');
        $perFormKey = $agentResponse->json('api_key');
        $embedHtml = $agentResponse->json('embed_html');
        $slug = $agentResponse->json('slug');

        $this->assertSame('contact', $slug);
        $this->assertIsString($perFormKey);
        $this->assertNotSame('', $perFormKey);
        $this->assertStringContainsString('/api/forms/contact', $formUrl);
        $this->assertStringNotContainsString('/api/submit/', $formUrl);

        // The high-privilege forms-agent key never appears in the
        // snippet; only the lower-privilege per-form key does.
        $this->assertStringContainsString('name="api_key"', $embedHtml);
        $this->assertStringContainsString($perFormKey, $embedHtml);
        $this->assertStringNotContainsString($formsAgentKey, $embedHtml);

        // 4. Visitor submits the embed snippet. The snippet posts to
        //    the legacy /api/forms/{slug} endpoint, carrying the
        //    per-form api_key as a hidden body field.
        $submissionResponse = $this->call(
            'POST',
            $formUrl,
            [
                'api_key' => $perFormKey,
                'email' => 'visitor@example.com',
                'message' => 'Hello from the smoke test',
            ],
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

        // 6. The forms-agent token cannot be used to submit — it is
        //    creation-only. A visitor sending it instead of the
        //    per-form key gets 401 from the per-form middleware.
        $this->withHeaders([
            'Authorization' => 'Bearer '.$formsAgentKey,
            'Accept' => 'application/json',
        ])->post($formUrl, [
            'email' => 'evil@example.com',
            'message' => 'should fail',
        ])->assertStatus(401);
    }
}
