<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_users_roles_and_permissions_without_clearing_password(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $cashierRole = Role::where('slug', 'cajero')->firstOrFail();
        $kitchenRole = Role::where('slug', 'cocina')->firstOrFail();
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/users', [
            'name' => 'Operador',
            'username' => 'Operador',
            'email' => 'OPERADOR@test.local',
            'password' => 'password-seguro',
            'role_id' => $cashierRole->id,
        ])->assertCreated()->json();

        $user = User::findOrFail($created['id']);
        $password = $user->password;
        $this->putJson("/api/users/{$user->id}", [
            'name' => 'Operador actualizado',
            'password' => null,
        ])->assertOk()->assertJsonPath('name', 'Operador actualizado');
        $this->assertSame($password, $user->fresh()->password);
        $this->assertTrue(Hash::check('password-seguro', $user->fresh()->password));

        $this->postJson('/api/login', [
            'login' => 'operador',
            'password' => 'password-seguro',
            'device_name' => 'prueba',
        ])->assertOk()->assertJsonPath('user.id', $user->id);

        $this->putJson("/api/users/{$user->id}/roles", ['role_id' => $kitchenRole->id])
            ->assertOk()
            ->assertJsonPath('role.id', $kitchenRole->id);

        $permission = Permission::where('slug', 'inventory.view')->firstOrFail();
        $this->putJson("/api/roles/{$kitchenRole->id}", [
            'name' => 'Cocina',
            'permission_ids' => [$permission->id],
        ])->assertOk()->assertJsonCount(1, 'permissions');
        $this->getJson('/api/permissions')->assertOk()->assertJsonFragment(['slug' => 'inventory.view']);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Role::class,
            'auditable_id' => $kitchenRole->id,
            'action' => 'permissions_updated',
            'branch_id' => $admin->branch_id,
        ]);
    }

    public function test_admin_cannot_lock_out_its_branch_or_change_its_own_role(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $cashierRole = Role::where('slug', 'cajero')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$admin->id}", ['active' => false])
            ->assertUnprocessable();
        $this->putJson("/api/users/{$admin->id}/roles", ['role_id' => $cashierRole->id])
            ->assertUnprocessable();
        $this->deleteJson("/api/users/{$admin->id}")
            ->assertUnprocessable();

        $this->assertTrue($admin->fresh()->active);
        $this->assertSame('administrador', $admin->fresh()->role->slug);
    }

    public function test_user_management_is_scoped_to_the_current_branch(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $other = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$other->id}", ['name' => 'Intrusión'])->assertNotFound();
        $this->putJson("/api/users/{$other->id}/roles", ['role_id' => $admin->role_id])->assertNotFound();
        $this->deleteJson("/api/users/{$other->id}")->assertNotFound();
    }
}
