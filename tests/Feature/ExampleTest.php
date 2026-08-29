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

    public function test_private_network_origins_are_allowed_for_cors(): void
    {
        $response = $this->withHeader('Origin', 'http://192.168.1.50:3000')->get('/');

        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://192.168.1.50:3000');
    }

    public function test_production_frontend_is_allowed_for_cors(): void
    {
        $origin = 'https://pizzeria-production-fcab.up.railway.app';

        $response = $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,authorization',
        ])->options('/api/login');

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', $origin);
    }

    public function test_unauthenticated_api_requests_return_unauthorized_instead_of_redirecting(): void
    {
        $this->get('/api/catalogs/suppliers')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
