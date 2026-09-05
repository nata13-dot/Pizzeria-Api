<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return User::with(['role', 'branch'])->where('branch_id', $request->user()->branch_id)->paginate(30);
    }

    public function store(Request $request)
    {
        $data = $this->data($request);
        $data['branch_id'] = $request->user()->branch_id;

        return response()->json(User::create($data)->load(['role', 'branch']), 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->data($request, true, $user);
        $user = DB::transaction(function () use ($request, $user, $data): User {
            Branch::query()->whereKey($request->user()->branch_id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->own($request, $user);
            $this->guardAdministrativeAccess($request, $user, $data);

            $deactivating = array_key_exists('active', $data) && ! (bool) $data['active'];
            $user->update($data);
            if ($deactivating) {
                $user->tokens()->delete();
            }

            return $user->fresh();
        });

        return $user->load('role');
    }

    public function destroy(Request $request, User $user)
    {
        DB::transaction(function () use ($request, $user): void {
            Branch::query()->whereKey($request->user()->branch_id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->own($request, $user);
            $this->guardAdministrativeAccess($request, $user, ['active' => false]);
            $user->update(['active' => false]);
            $user->tokens()->delete();
        });

        return response()->noContent();
    }

    public function assignRole(Request $request, User $user)
    {
        $request->merge(['role_id' => $request->input('role_id')]);

        return $this->update($request, $user);
    }

    public function roles()
    {
        return Role::with('permissions')->get();
    }

    public function permissions()
    {
        return Permission::query()->orderBy('name')->get();
    }

    public function updateRole(Request $request, Role $role)
    {
        $systemAdminEmail = mb_strtolower(trim((string) config('services.system_admin_email')));
        abort_if(
            ! (
                ($systemAdminEmail !== '' && mb_strtolower((string) $request->user()->email) === $systemAdminEmail)
                || (Branch::query()->count() === 1 && $request->user()->role?->slug === 'administrador')
            ),
            403,
            'Solo el administrador del sistema puede editar los roles globales.',
        );

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'description' => 'sometimes|nullable|string|max:500',
            'permission_ids' => 'required|array',
            'permission_ids.*' => 'integer|distinct|exists:permissions,id',
        ]);

        // El administrador es un superusuario del sistema. Su acceso total no
        // puede reducirse accidentalmente desde el editor de roles.
        if ($role->slug === 'administrador') {
            $data['permission_ids'] = Permission::query()->pluck('id')->all();
        }

        DB::transaction(function () use ($request, $role, $data): void {
            $role = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $oldPermissionIds = $role->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all();
            $role->update(collect($data)->only(['name', 'description'])->all());
            $role->permissions()->sync($data['permission_ids']);
            $newPermissionIds = $role->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all();
            if ($oldPermissionIds !== $newPermissionIds) {
                AuditLog::create([
                    'branch_id' => $request->user()->branch_id,
                    'user_id' => $request->user()->id,
                    'action' => 'permissions_updated',
                    'auditable_type' => Role::class,
                    'auditable_id' => $role->id,
                    'old_values' => ['permission_ids' => $oldPermissionIds],
                    'new_values' => ['permission_ids' => $newPermissionIds],
                    'ip' => $request->ip(),
                ]);
            }
        });

        return $role->fresh()->load('permissions');
    }

    public function device(Request $r)
    {
        $d = $r->validate(['push_token' => 'required|string|max:255', 'name' => 'required|string|max:100', 'platform' => 'nullable|string|max:30']);

        return response()->json($r->user()->devices()->updateOrCreate(['push_token' => $d['push_token']], $d + ['last_seen_at' => now(), 'active' => true]), 201);
    }

    public function notifications(Request $r)
    {
        return $r->user()->notifications()->latest()->paginate(30);
    }

    public function preferences(Request $request): array
    {
        return [
            'system_font_size' => $request->user()->system_font_size ?? 'medium',
            'receipt_font_size' => $request->user()->receipt_font_size ?? 'small',
        ];
    }

    public function updatePreferences(Request $request): array
    {
        $data = $request->validate([
            'system_font_size' => ['sometimes', 'required', Rule::in(['small', 'medium', 'large'])],
            'receipt_font_size' => ['sometimes', 'required', Rule::in(['small', 'medium', 'large'])],
        ]);
        if ($data === []) {
            abort(422, 'Debes indicar al menos una preferencia de fuente.');
        }
        $request->user()->update($data);

        $user = $request->user()->fresh();

        return [
            'system_font_size' => $user->system_font_size ?? 'medium',
            'receipt_font_size' => $user->receipt_font_size ?? 'small',
        ];
    }

    public function read(Request $r, string $id)
    {
        $n = $r->user()->notifications()->findOrFail($id);
        $n->markAsRead();

        return $n;
    }

    private function data(Request $request, bool $partial = false, ?User $user = null): array
    {
        $sometimes = $partial ? 'sometimes|' : '';
        $request->merge(array_filter([
            'email' => $request->has('email') ? mb_strtolower(trim((string) $request->input('email'))) : null,
            'username' => $request->has('username') && $request->input('username') !== null
                ? mb_strtolower(trim((string) $request->input('username')))
                : null,
        ], fn ($value) => $value !== null));

        $data = $request->validate([
            'name' => $sometimes.'required|string|max:150',
            'username' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'string', 'alpha_dash', 'max:50', Rule::unique('users', 'username')->ignore($user)],
            'email' => [$partial ? 'sometimes' : 'required', 'email', Rule::unique('users', 'email')->ignore($user)],
            'password' => $partial ? 'nullable|string|min:8' : 'required|string|min:8',
            'role_id' => $sometimes.'required|exists:roles,id',
            'active' => 'sometimes|boolean',
        ]);

        if ($partial && empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }

    private function own(Request $request, User $user): void
    {
        abort_unless($user->branch_id === $request->user()->branch_id, 404);
    }

    private function guardAdministrativeAccess(Request $request, User $user, array $data): void
    {
        $deactivating = array_key_exists('active', $data) && ! (bool) $data['active'];
        $changingRole = array_key_exists('role_id', $data) && (int) $data['role_id'] !== (int) $user->role_id;

        abort_if($request->user()->is($user) && $deactivating, 422, 'No puedes desactivar tu propia cuenta.');
        abort_if($request->user()->is($user) && $changingRole, 422, 'No puedes cambiar tu propio rol.');

        if (! $user->active || $user->role?->slug !== 'administrador' || (! $deactivating && ! $changingRole)) {
            return;
        }

        $activeAdministrators = User::query()
            ->where('branch_id', $user->branch_id)
            ->where('active', true)
            ->whereHas('role', fn ($query) => $query->where('slug', 'administrador'))
            ->count();

        abort_if($activeAdministrators <= 1, 422, 'Debe permanecer al menos un administrador activo en la sucursal.');
    }
}
