<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Registrar una nueva tienda (tenant) + usuario administrador
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_name'     => 'required|string|max:200',
            'slug'           => [
                'required',
                'string',
                'max:80',
                'unique:tenants,slug',
                'regex:/^[a-z0-9\-]+$/',
                function ($attribute, $value, $fail) {
                    $reserved = ['admin', 'dashboard', 'login', 'register', 'api', 'public', 'settings', 'config', 'home', 'main'];
                    if (in_array(strtolower($value), $reserved)) {
                        $fail('El slug elegido está reservado por la plataforma.');
                    }
                }
            ],
            'whatsapp'       => 'required|string|max:20',
            'name'           => 'required|string|max:200',
            'email'          => 'required|email|unique:users,email',
            'password'       => 'required|string|min:8|confirmed',
        ]);

        // No pasar 'id' manualmente — HasUuids + newUniqueId() genera UUID v7 automáticamente
        $tenant = Tenant::create([
            'slug'           => $data['slug'],
            'name'           => $data['store_name'],
            'whatsapp_number'=> $data['whatsapp'],
        ]);

        // tenant_id se asigna explícitamente (está en $guarded, no en $fillable)
        // password se pasa en texto plano — el cast 'hashed' lo hashea automáticamente
        $user = new User([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => $data['password'],
            'role'      => 'admin',
        ]);
        $user->tenant_id = $tenant->id;
        $user->save();

        $token = $user->createToken('spa-token', ['admin'], now()->addDays(7));

        return response()->json([
            'token'  => $token->plainTextToken,
            'user'   => $user,
            'tenant' => $tenant,
        ], 201);
    }

    /**
     * Login de usuario existente
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        $user = Auth::user();

        // Solo administradores activos pueden usar el panel.
        // Mismo mensaje genérico para no filtrar la existencia del correo.
        if ($user->role !== 'admin' || !$user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        // Revocar tokens anteriores (una sesión activa por usuario)
        $user->tokens()->delete();

        $token = $user->createToken('spa-token', ['admin'], now()->addDays(7));

        return response()->json([
            'token'  => $token->plainTextToken,
            'user'   => $user,
            'tenant' => $user->tenant,
        ]);
    }

    /**
     * Cerrar sesión
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /**
     * Devolver datos del usuario autenticado
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user'   => $request->user(),
            'tenant' => $request->user()->tenant,
        ]);
    }
}
