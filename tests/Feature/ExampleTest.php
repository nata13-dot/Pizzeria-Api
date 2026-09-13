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
        $origin = 'http://192.168.1.50:3000';
        $response = $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/login');

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', $origin);
    }

    public function test_configured_production_frontend_can_preflight_login(): void
    {
        $origin = 'https://espinazodeldiablo.site';

        $response = $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,authorization',
        ])->options('/api/login');

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', $origin);
        $response->assertHeader('Access-Control-Allow-Methods');
        $response->assertHeader('Access-Control-Allow-Headers');
    }

    public function test_unconfigured_origin_does_not_receive_cors_allow_origin_header(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://unauthorized.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,authorization',
        ])->options('/api/login');

        $response->assertNoContent();
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_unauthenticated_api_requests_return_unauthorized_instead_of_redirecting(): void
    {
        $this->get('/api/catalogs/suppliers')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
