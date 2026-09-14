<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
// La fachada `Password` de abajo es el broker de recuperacion; esta es la regla
// de validacion (AUD-17). Comparten nombre, asi que una de las dos va con alias
// -mismo criterio que AuthController, que tiene la misma colision.
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
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
            // Misma politica que el panel (AUD-17): la cuenta de un cliente
            // guarda su historial de pedidos y sus datos de contacto.
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
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
            'user'  => new UserResource($user),
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
            'user'  => new UserResource($user),
        ]);
    }

    /**
     * Pedir el enlace de recuperación de contraseña del CLIENTE (FUN-11).
     *
     * Hasta aquí sólo existía este flujo para el panel (`AuthController`, SAAS-2),
     * que filtra por `role => admin/staff` sin mirar la tienda: el correo del
     * panel es único en TODA la plataforma (FUN-14), así que ahí basta. El de un
     * cliente NO lo es —sólo es único DENTRO de su tienda (SEC-4)—, así que
     * reutilizar aquel filtro habría podido resolver a la cuenta de otra tienda
     * con el mismo correo. Por eso se manda también `tenant_id` como credencial:
     * el broker de Laravel añade cualquier clave del array como un `where` más al
     * buscar al usuario, y con `tenant_id` de por medio dos clientes con el mismo
     * correo en tiendas distintas ya no compiten por el mismo enlace.
     */
    public function forgotPassword(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email'    => 'Ese correo no parece válido.',
        ]);

        Password::sendResetLink([
            'email'     => $request->input('email'),
            'tenant_id' => $tenant->id,
            'role'      => 'customer',
            'is_active' => true,
        ]);

        // Respuesta siempre idéntica, se haya enviado o no (mismo motivo que
        // AuthController::forgotPassword): distinguir "no existe" convertiría
        // esto en un detector de correos registrados en esta tienda.
        return response()->json([
            'message' => 'Si el correo pertenece a una cuenta de esta tienda, te enviamos un enlace para restablecer la contraseña.',
        ]);
    }

    /**
     * Fijar la nueva contraseña del cliente con el token recibido por correo (FUN-11).
     */
    public function resetPassword(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ], [
            'email.required'     => 'Escribe tu correo electrónico.',
            'email.email'        => 'Ese correo no parece válido.',
            'password.required'  => 'Elige una contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token')
                + ['tenant_id' => $tenant->id, 'role' => 'customer', 'is_active' => true],
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Igual que el reset del panel: cierra las sesiones abiertas,
                // por si perdio el control de la cuenta.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['El enlace de recuperación no es válido o ya caducó. Solicita uno nuevo.'],
            ]);
        }

        return response()->json([
            'message' => 'Contraseña actualizada. Ya puedes entrar con la nueva.',
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
            'user' => new UserResource($request->user()),
        ]);
    }
}
