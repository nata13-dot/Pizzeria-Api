<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_generate_and_download_a_complete_backup(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        BusinessProfile::create(['branch_id' => $admin->branch_id, 'name' => 'Negocio persistente']);
        Sanctum::actingAs($admin);

        $url = $this->postJson('/api/database-backups')
            ->assertOk()
            ->assertJsonStructure(['download_url', 'expires_in_minutes'])
            ->json('download_url');

        $response = $this->get($url)->assertOk();
        $response->assertHeader('content-type', 'application/json; charset=UTF-8');
        $backup = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('pizzeria-database-backup-v1', $backup['format']);
        $this->assertArrayHasKey('business_profiles', $backup['tables']);
        $this->assertContains('Negocio persistente', array_column($backup['tables']['business_profiles'], 'name'));
    }

    public function test_non_administrator_cannot_generate_a_backup(): void
    {
        $this->seed();
        $cashier = User::create([
            'name' => 'Caja',
            'email' => 'caja-backup@test.local',
            'password' => 'password',
            'role_id' => Role::where('slug', 'cajero')->value('id'),
            'branch_id' => User::first()->branch_id,
        ]);
        Sanctum::actingAs($cashier);

        $this->postJson('/api/database-backups')->assertForbidden();
    }
}
