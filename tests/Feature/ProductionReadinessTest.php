<?php

namespace Tests\Feature;

use App\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sanity check that the framework boots cleanly. This catches regressions
     * where the bootstrap starts reading config() before the config
     * repository is bound (which surfaces as "Target class [config] does
     * not exist" in production).
     */
    public function test_artisan_can_run_a_command(): void
    {
        $exit = Artisan::call('inspire');
        $this->assertSame(0, $exit);
        $this->assertNotEmpty(Artisan::output());
    }

    /**
     * The API health endpoint must respond OK with no auth required.
     */
    public function test_health_endpoint_is_public(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    /**
     * The detailed health endpoint Laravel registers at /up must respond.
     */
    public function test_up_health_endpoint_is_public(): void
    {
        $this->get('/up')->assertSuccessful();
    }

    /**
     * Forms API must reject unauthenticated requests.
     */
    public function test_forms_api_rejects_missing_api_key(): void
    {
        $form = Form::factory()->create();

        $this->postJson("/api/forms/{$form->slug}", ['data' => ['x' => 'y']])
            ->assertStatus(401);
    }

    /**
     * Forms API must reject unknown slugs with a JSON 404.
     */
    public function test_forms_api_returns_json_for_unknown_slug(): void
    {
        $this->postJson('/api/forms/this-does-not-exist', ['data' => ['x' => 'y']])
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json');
    }

    /**
     * Dashboard pages must redirect to login when unauthenticated.
     */
    public function test_dashboard_redirects_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    /**
     * Config and routes must be cacheable without errors.
     */
    public function test_production_caches_build_cleanly(): void
    {
        $this->assertSame(0, Artisan::call('config:cache'));
        $this->assertSame(0, Artisan::call('view:cache'));
        $this->assertSame(0, Artisan::call('event:cache'));
        $this->assertSame(0, Artisan::call('route:cache'));

        Artisan::call('config:clear');
        Artisan::call('view:clear');
        Artisan::call('event:clear');
        Artisan::call('route:clear');
    }
}
