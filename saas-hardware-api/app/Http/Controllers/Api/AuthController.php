<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
// La fachada `Password` de arriba es el broker de recuperacion; esta es la regla
// de validacion (AUD-17). Comparten nombre, asi que una de las dos va con alias.
use Illuminate\Validation\Rules\Password as PasswordRule;
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
                    $reserved = ['admin', 'dashboard', 'login', 'register', 'api', 'public', 'settings', 'config', 'home', 'main', 'forgot-password', 'reset-password', 'platform'];
                    if (in_array(strtolower($value), $reserved)) {
                        $fail('El slug elegido está reservado por la plataforma.');
                    }
                }
            ],
            'whatsapp'       => 'required|string|max:20',
            'name'           => 'required|string|max:200',
            // Unico solo frente a OTROS ADMINS, no frente a clientes: desde SEC-4 el
            // correo es unico por tenant, asi que un dueño puede usar un correo que ya
            // existe como cliente en otra tienda. Entre admins debe seguir siendo unico
            // porque el login del panel resuelve por email sin saber la tienda.
            'email'          => [
                'required',
                'email',
                Rule::unique('users', 'email')->where(fn ($query) => $query->where('role', 'admin')),
            ],
            'password'       => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ], [
            // El locale de la app es 'en' y no hay carpeta lang/, asi que sin esto
            // el alta self-service muestra "The slug has already been taken." en una
            // interfaz en español.
            'store_name.required' => 'Ponle un nombre a tu tienda.',
            'slug.required'       => 'Elige la dirección de tu catálogo.',
            'slug.unique'         => 'Esa dirección ya está en uso. Prueba con otra.',
            'slug.regex'          => 'Usa solo minúsculas, números y guiones.',
            'whatsapp.required'   => 'Necesitamos un WhatsApp de contacto.',
            'name.required'       => 'Escribe tu nombre.',
            'email.required'      => 'Escribe tu correo electrónico.',
            'email.email'         => 'Ese correo no parece válido.',
            'email.unique'        => 'Ya hay una tienda registrada con ese correo.',
            'password.required'   => 'Elige una contraseña.',
            'password.min'        => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed'  => 'Las contraseñas no coinciden.',
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
            // refresh() para que la respuesta incluya los campos con valor por
            // defecto en la base (tenant.plan, is_active...). Sin esto el modelo
            // recien creado los omite y el panel recibe un tenant incompleto.
            'user'   => new UserResource($user->refresh()),
            'tenant' => $tenant->refresh(),
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

        // El rol va DENTRO de las credenciales, no solo en el chequeo posterior:
        // desde SEC-4 el correo es unico por tenant, asi que un mismo correo puede
        // pertenecer a un admin y a clientes de otras tiendas. Sin filtrar por rol,
        // Auth::attempt resolveria al primero que coincida y un admin legitimo no
        // podria entrar si un cliente se registro antes con ese correo.
        $credentials = $request->only('email', 'password') + ['role' => 'admin'];

        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        $user = Auth::user();

        // Defensa en profundidad: si alguien quita el rol de las credenciales de
        // arriba, esta comprobacion sigue cerrando el panel a no-admins.
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
            'user'   => new UserResource($user),
            'tenant' => $user->tenant,
        ]);
    }

    /**
     * Solicitar el enlace de recuperación de contraseña (SAAS-2).
     *
     * Solo para admins activos: las credenciales que se le pasan al broker
     * llevan `role`/`is_active`, así que un cliente (o un admin suspendido) con
     * ese mismo correo no recibe nada. Es la misma restricción que aplica
     * `login`, y hace falta porque desde SEC-4 el correo es único por tienda:
     * sin filtrar, el broker podría resolver al cliente de otra tienda.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email'    => 'Ese correo no parece válido.',
        ]);

        Password::sendResetLink([
            'email'     => $request->input('email'),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        // Respuesta siempre idéntica, se haya enviado o no. Distinguir "no existe"
        // de "enviado" convertiría este endpoint en un detector de correos
        // registrados. Ojo: el broker tampoco reenvía si ya se pidió hace menos
        // de 60s (auth.passwords.users.throttle) y ese caso también cae aquí.
        return response()->json([
            'message' => 'Si el correo pertenece a una tienda registrada, te enviamos un enlace para restablecer la contraseña.',
        ]);
    }

    /**
     * Fijar la nueva contraseña con el token recibido por correo (SAAS-2).
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ], [
            'email.required'     => 'Escribe tu correo electrónico.',
            'email.email'        => 'Ese correo no parece válido.',
            'password.required'  => 'Elige una contraseña.',
            'password.min'       => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token')
                + ['role' => 'admin', 'is_active' => true],
            function (User $user, string $password) {
                // El cast 'hashed' del modelo se encarga del bcrypt.
                $user->forceFill([
                    'password'       => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Quien pide un reset suele hacerlo porque perdió el control de
                // la cuenta, así que cerramos las sesiones abiertas: los tokens
                // de Sanctum sobrevivirían al cambio de contraseña.
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
            'user'   => new UserResource($request->user()),
            'tenant' => $request->user()->tenant,
        ]);
    }
}
