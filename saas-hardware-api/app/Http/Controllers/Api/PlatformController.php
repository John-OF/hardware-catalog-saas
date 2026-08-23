<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administracion de la plataforma para el operador del SaaS (SAAS-4).
 *
 * Antes no habia forma de gestionar morosos o abusos salvo editar la base a
 * mano. Aqui el operador lista tiendas, las suspende o reactiva, cambia el plan
 * y le manda a un dueño el enlace de recuperacion de contrasenia.
 *
 * Todo esto vive FUERA del scope de tenant: el super-admin no pertenece a
 * ninguna tienda (`tenant_id` null) y no debe pasar por el middleware `tenant`.
 */
class PlatformController extends Controller
{
    /**
     * Login del operador.
     *
     * Endpoint aparte del panel de tiendas a proposito: `AuthController::login`
     * filtra por `role => 'admin'` y devuelve un tenant, que aqui no existe.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('email', 'password') + ['role' => 'superadmin'];

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        $user = Auth::user();

        // Defensa en profundidad, igual que en el login de tiendas.
        if ($user->role !== 'superadmin' || ! $user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);
        $user->tokens()->delete();

        $token = $user->createToken('platform-token', ['superadmin'], now()->addDay());

        return response()->json([
            'token' => $token->plainTextToken,
            'user'  => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    /**
     * Listado de tiendas con lo mínimo para decidir: tamaño, actividad y estado.
     */
    public function tenants(Request $request): JsonResponse
    {
        $tenants = Tenant::query()
            // AUD-4: antes esto funcionaba SIN pedir nada, porque en estas rutas
            // no se hace makeCurrent() y el global scope quedaba inerte. Ahora el
            // scope falla en cerrado, así que sin `withoutTenant()` los tres
            // contadores saldrían a cero: exactamente el tipo de suposición
            // tácita que el cambio pretende sacar a la luz.
            //
            // Aquí mirar por encima de las tiendas es lo correcto —es el panel
            // del operador del SaaS— y ahora queda dicho en el código.
            ->withCount([
                'products' => fn ($q) => $q->withoutTenant(),
                'orders'   => fn ($q) => $q->withoutTenant(),
                'users'    => fn ($q) => $q->withoutTenant(),
            ])
            ->when($request->filled('search'), function ($query) use ($request) {
                $termino = '%'.$request->string('search').'%';

                // Agrupado en su propio closure: suelto se mezclaría con el
                // filtro de estado de abajo y devolvería tiendas de más.
                $query->where(function ($sub) use ($termino) {
                    $sub->where('name', 'like', $termino)
                        ->orWhere('slug', 'like', $termino)
                        ->orWhere('custom_domain', 'like', $termino);
                });
            })
            ->when($request->input('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->input('status') === 'suspended', fn ($q) => $q->where('is_active', false))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($tenants);
    }

    /**
     * Suspender/reactivar una tienda y ajustar su plan.
     *
     * Suspender basta para dejarla inaccesible: `InitializeTenantByHeader` y el
     * catálogo público ya exigen `is_active`.
     */
    public function updateTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'is_active' => 'sometimes|boolean',
            'plan'      => ['sometimes', 'string', Rule::in(['free', 'pro', 'enterprise'])],
        ]);

        $tenant->update($data);

        // La caché pública guarda el tenant 5 minutos; sin esto una tienda
        // suspendida seguiría sirviéndose desde caché.
        $this->forgetPublicCache($tenant);

        // Mismo motivo que en tenants(): sin `withoutTenant()` los contadores
        // saldrian a cero, porque aqui no hay tienda actual (AUD-4).
        return response()->json($tenant->fresh()->loadCount([
            'products' => fn ($q) => $q->withoutTenant(),
            'orders'   => fn ($q) => $q->withoutTenant(),
            'users'    => fn ($q) => $q->withoutTenant(),
        ]));
    }

    /**
     * Mandar el enlace de recuperación a los admins de una tienda (rescate).
     *
     * Reutiliza el flujo de 7.2, así que el operador nunca ve ni fija la
     * contraseña de nadie: solo dispara el correo.
     */
    public function sendAdminPasswordReset(Tenant $tenant): JsonResponse
    {
        $admins = User::where('tenant_id', $tenant->id)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get();

        if ($admins->isEmpty()) {
            return response()->json([
                'message' => 'Esta tienda no tiene ningún administrador activo.',
            ], 422);
        }

        foreach ($admins as $admin) {
            Password::sendResetLink([
                'email'     => $admin->email,
                'role'      => 'admin',
                'is_active' => true,
            ]);
        }

        return response()->json([
            'message' => $admins->count() === 1
                ? "Enviamos el enlace de recuperación a {$admins->first()->email}."
                : "Enviamos el enlace de recuperación a {$admins->count()} administradores.",
        ]);
    }

    private function forgetPublicCache(Tenant $tenant): void
    {
        \Illuminate\Support\Facades\Cache::forget("tenant:{$tenant->slug}");
    }
}
