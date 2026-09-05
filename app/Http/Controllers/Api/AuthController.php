<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['nullable', 'required_without:email', 'string', 'max:255'],
            'email' => ['nullable', 'required_without:login', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $identifier = mb_strtolower(trim($credentials['login'] ?? $credentials['email']));
        $user = User::with(['role', 'branch'])
            ->where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();
        if (! $user || ! $user->active || ! $user->branch?->active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => ['Las credenciales no son válidas.']]);
        }

        return response()->json([
            'token' => $user->createToken(
                $credentials['device_name'] ?? 'pizzeria-app',
                ['*'],
                now()->addHours(9),
            )->plainTextToken,
            'expires_at' => now()->addHours(9)->toIso8601String(),
            'user' => $this->withEffectivePermissions($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->withEffectivePermissions($request->user()->load(['role', 'branch'])));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    private function withEffectivePermissions(User $user): User
    {
        $user->loadMissing(['role.permissions', 'branch']);
        $permissions = $user->role?->slug === 'administrador'
            ? ['*']
            : $user->role?->permissions->pluck('slug')->values()->all();
        $user->setAttribute('permissions', $permissions);

        return $user;
    }
}
