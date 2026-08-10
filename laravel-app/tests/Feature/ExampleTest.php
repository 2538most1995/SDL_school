<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_frontend_urls_support_subdirectory_hosting(): void
    {
        config([
            'app.url' => 'https://krumost.com/SDL_school',
            'app.asset_url' => 'https://krumost.com/SDL_school',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('meta name="app-base-path" content="/SDL_school"', false)
            ->assertSee('https://krumost.com/SDL_school/build/assets/app-', false);
    }

    public function test_request_subdirectory_overrides_a_stale_root_asset_url(): void
    {
        config([
            'app.url' => 'https://krumost.com',
            'app.asset_url' => 'https://krumost.com',
        ]);

        $this->withServerVariables([
            'SCRIPT_NAME' => '/SDL_school/index.php',
            'SCRIPT_FILENAME' => base_path('../index.php'),
        ])->get('/SDL_school/login')
            ->assertOk()
            ->assertSee('meta name="app-base-path" content="/SDL_school"', false)
            ->assertSee('https://krumost.com/SDL_school/build/assets/app-', false)
            ->assertDontSee('https://krumost.com/build/assets/app-', false);
    }
}
