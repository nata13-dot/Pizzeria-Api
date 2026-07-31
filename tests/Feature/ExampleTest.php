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
}
