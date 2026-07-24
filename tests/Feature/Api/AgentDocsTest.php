<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AgentDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The controller caches the Markdown for an hour; clear the
        // cache between tests so any in-test edits are visible.
        Cache::flush();
    }

    public function test_llms_txt_returns_markdown_with_expected_sections(): void
    {
        $response = $this->get('/api/llms.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=utf-8');

        $body = $response->getContent();

        $this->assertStringContainsString('# forms-app agent API', $body);
        $this->assertStringContainsString('## Conventions', $body);
        $this->assertStringContainsString('## Authentication', $body);
        $this->assertStringContainsString('### POST /api/agent/forms', $body);
        $this->assertStringContainsString('### POST /api/submit/{slug}', $body);
    }

    public function test_llms_txt_documents_three_auth_methods(): void
    {
        $body = $this->get('/api/llms.txt')->getContent();

        $this->assertStringContainsString('Authorization: Bearer', $body);
        $this->assertStringContainsString('?user_api=', $body);
        $this->assertStringContainsString('_user_api', $body);
    }

    public function test_docs_endpoint_returns_json_wrapper(): void
    {
        $response = $this->getJson('/api/agent/docs');

        $response->assertOk();
        $response->assertJsonStructure(['content', 'format']);
        $response->assertJsonPath('format', 'markdown');
        $this->assertStringContainsString('# forms-app agent API', $response->json('content'));
    }

    public function test_llms_response_is_cached(): void
    {
        // First request populates the cache.
        $first = $this->get('/api/llms.txt')->getContent();
        $this->assertNotEmpty($first);

        // Second request should hit the cache and still work.
        $second = $this->get('/api/llms.txt')->getContent();
        $this->assertSame($first, $second);

        $this->assertTrue(Cache::has('agent-llms:v1'));
    }
}
