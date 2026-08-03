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
}
