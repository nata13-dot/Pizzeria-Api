<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_espinazo_remains_allowed_when_environment_lists_only_other_clients(): void
    {
        $environment = \Illuminate\Support\Env::getRepository();
        $previous = $environment->get('CORS_ALLOWED_ORIGINS');
        try {
            foreach (['', 'https://localhost'] as $configured) {
                $environment->set('CORS_ALLOWED_ORIGINS', $configured);
                $cors = require config_path('cors.php');
                $this->assertContains('https://espinazodeldiablo.site', $cors['allowed_origins']);
                $this->assertNotContains('*', $cors['allowed_origins']);
            }
        } finally {
            $previous === null
                ? $environment->clear('CORS_ALLOWED_ORIGINS')
                : $environment->set('CORS_ALLOWED_ORIGINS', $previous);
        }
    }

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
