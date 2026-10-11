<?php

namespace Tests\Unit;

use App\Services\PushService;
use ReflectionMethod;
use Tests\TestCase;

class PushServiceTest extends TestCase
{
    public function test_firebase_credentials_can_be_loaded_from_a_protected_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'firebase-test-');
        file_put_contents($path, json_encode([
            'project_id' => 'pizza-project',
            'client_email' => 'firebase@example.test',
            'private_key' => 'private-key',
        ], JSON_THROW_ON_ERROR));

        config([
            'services.firebase_service_account_json' => '',
            'services.firebase_service_account_path' => $path,
        ]);

        try {
            $method = new ReflectionMethod(PushService::class, 'firebaseCredentials');
            $credentials = $method->invoke(new PushService());

            $this->assertSame('pizza-project', $credentials['project_id']);
            $this->assertSame('firebase@example.test', $credentials['client_email']);
        } finally {
            @unlink($path);
        }
    }

    public function test_incomplete_firebase_credentials_are_rejected(): void
    {
        config([
            'services.firebase_service_account_json' => json_encode(['project_id' => 'incomplete'], JSON_THROW_ON_ERROR),
            'services.firebase_service_account_path' => '',
        ]);

        $method = new ReflectionMethod(PushService::class, 'firebaseCredentials');

        $this->assertNull($method->invoke(new PushService()));
    }
}
