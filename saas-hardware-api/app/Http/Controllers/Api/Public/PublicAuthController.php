<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PublicAuthController extends Controller
{
    /**
     * Registro de cliente asociado al tenant (slug)
     */
    public function register(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $data = $request->validate([
            'name'     => 'required|string|max:200',
            'email'    => 'required|email',
            'phone'    => 'nullable|string|max:30',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Asegurar que el correo sea único DENTRO del mismo tenant (multi-tenancy)
        $exists = User::where('tenant_id', $tenant->id)
            ->where('email', $data['email'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'email' => ['Este correo ya está registrado en esta tienda.'],
            ]);
        }

        // Crear el usuario con rol de cliente
        $user = new User([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'phone'    => $data['phone'] ?? null,
            'password' => $data['password'], // El cast 'hashed' se encarga de hashear en User model
            'role'     => 'customer',
            'is_active'=> true,
        ]);
        $user->tenant_id = $tenant->id;
        $user->save();

        $token = $user->createToken('customer-token', ['customer'], now()->addDays(30));

        return response()->json([
            'token' => $token->plainTextToken,
            'user'  => $user,
        ], 201);
    }

    /**
     * Login de cliente para un tenant específico
     */
    public function login(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // Buscar el usuario del tenant
        $user = User::where('tenant_id', $tenant->id)
            ->where('email', $data['email'])
            ->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas para esta tienda.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta se encuentra inactiva. Contacta al administrador.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        // Revocar tokens anteriores de cliente
        $user->tokens()->where('name', 'customer-token')->delete();

        $token = $user->createToken('customer-token', ['customer'], now()->addDays(30));

        return response()->json([
            'token' => $token->plainTextToken,
            'user'  => $user,
        ]);
    }

    /**
     * Cerrar sesión del cliente
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /**
     * Retornar los datos del cliente autenticado
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }
}
