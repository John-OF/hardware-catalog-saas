<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlanGate;
use App\Support\Suplantacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        // Mismo motivo que en el login de tiendas: `last_login_at` no es
        // fillable, asi que `update()` lo tiraba sin decir nada.
        $user->forceFill(['last_login_at' => now()])->save();
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

        // Se lee ANTES de escribir: la bitácora tiene que poder decir de qué
        // plan a cuál, y después del update el valor anterior ya no existe.
        $planAnterior   = $tenant->plan;
        $estabaActiva   = $tenant->is_active;

        $tenant->update($data);

        $this->anotarCambio($request, $tenant, $data, $planAnterior, $estabaActiva);

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
    public function sendAdminPasswordReset(Request $request, Tenant $tenant): JsonResponse
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

        ActivityLog::registrar(
            ActivityLog::TIENDA_RESET,
            "Mandó el enlace de recuperación a {$admins->count()} admin(s) de {$tenant->name}.",
            $tenant,
            $request->user(),
            ['destinatarios' => $admins->pluck('email')->all()],
            $request,
        );

        return response()->json([
            'message' => $admins->count() === 1
                ? "Enviamos el enlace de recuperación a {$admins->first()->email}."
                : "Enviamos el enlace de recuperación a {$admins->count()} administradores.",
        ]);
    }

    /**
     * Resumen del negocio: cómo va la plataforma de un vistazo (INF-2).
     *
     * Hasta aquí el operador sabía cuántas tiendas había contándolas en el
     * listado. Lo que responde esta pantalla es lo que no se ve en una lista:
     * si entran altas, cuántas arrancan de verdad y qué se está usando.
     *
     * **Aquí no hay ni una cifra de dinero, y no es un olvido.** Cada tienda
     * factura en SU moneda (`tenants.currency`), así que sumar los totales de
     * los pedidos de todas daría un número sin significado —soles con dólares—
     * que encima parecería un ingreso. El dinero de verdad de la plataforma es
     * la suscripción, y eso llega con 7.7b; hasta entonces, mejor ninguna cifra
     * que una inventada.
     */
    public function stats(): JsonResponse
    {
        $activas = Tenant::where('is_active', true)->count();
        $total   = Tenant::count();

        // `withoutTenant()` en todos los conteos de modelos con scope: sin él
        // saldrían a cero, porque en estas rutas no hay tienda actual (AUD-4).
        return response()->json([
            'tiendas' => [
                'total'       => $total,
                'activas'     => $activas,
                'suspendidas' => $total - $activas,
                'publicadas'  => Tenant::publica()->count(),
                // Altas activas que no han subido ni un producto: son las que
                // se registraron y no llegaron a empezar. Es el número que dice
                // si el problema está en atraer gente o en el primer día de uso.
                'sin_arrancar' => Tenant::where('is_active', true)
                    ->whereDoesntHave('products', fn ($q) => $q->withoutTenant())
                    ->count(),
            ],

            'altas' => [
                'hoy'    => Tenant::where('created_at', '>=', now()->startOfDay())->count(),
                'semana' => Tenant::where('created_at', '>=', now()->subDays(7))->count(),
                'mes'    => Tenant::where('created_at', '>=', now()->subDays(30))->count(),
            ],

            // Con qué plan se queda la gente. Cuando haya cobro, esta es la
            // línea que se convierte en MRR.
            'planes' => Tenant::query()
                ->select('plan', DB::raw('count(*) as total'))
                ->groupBy('plan')
                ->pluck('total', 'plan'),

            'catalogo' => [
                'productos' => Product::withoutTenant()->count(),
                'pedidos'   => Order::withoutTenant()->count(),
                // Del equipo, no del catálogo: los clientes registrados viven en
                // la misma tabla `users` con rol `customer` y contarlos aquí
                // daría "usuarios de la plataforma" cuando lo que se pregunta es
                // cuánta gente trabaja dentro de los paneles.
                'equipo'    => User::whereIn('role', User::ROLES_DE_PANEL)->count(),
            ],

            'actividad' => [
                'pedidos_semana' => Order::withoutTenant()
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
            ],

            'ultimas_altas' => Tenant::query()
                ->orderByDesc('created_at')
                ->take(5)
                ->get(['id', 'name', 'slug', 'plan', 'is_active', 'is_published', 'created_at']),
        ]);
    }

    /**
     * Ficha de una tienda: todo lo que hace falta para atender un problema.
     *
     * El listado sirve para encontrar la tienda; esto para entenderla. Antes,
     * saber quién es su admin o por qué no puede subir más productos pedía
     * abrir la base de datos.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        $limites = PlanGate::limitsDe($tenant->plan);

        // El uso se cuenta con las mismas claves de la matriz de planes, para
        // poder pintar "18 / 20" al lado de cada tope sin traducir nombres.
        $uso = [
            'products'   => Product::withoutTenant()->where('tenant_id', $tenant->id)->count(),
            'categories' => Category::withoutTenant()->where('tenant_id', $tenant->id)->count(),
            'pages'      => Page::withoutTenant()->where('tenant_id', $tenant->id)->count(),
            'users'      => User::where('tenant_id', $tenant->id)
                ->whereIn('role', User::ROLES_DE_PANEL)
                ->count(),
        ];

        // `User` es la excepción al fallo en cerrado y aquí no hay tienda
        // resuelta, así que estas consultas SÍ ven todas las tiendas: el
        // `where('tenant_id')` explícito es lo único que las acota (FUN-14).
        $equipo = User::where('tenant_id', $tenant->id)
            ->whereIn('role', User::ROLES_DE_PANEL)
            ->orderBy('role')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'tenant' => $tenant->loadCount([
                'products' => fn ($q) => $q->withoutTenant(),
                'orders'   => fn ($q) => $q->withoutTenant(),
                'users'    => fn ($q) => $q->withoutTenant(),
            ]),

            'plan' => [
                // El plan efectivo, no el que diga la columna: uno escrito a
                // mano o sobrante de otra versión cae al plan por defecto.
                'clave'   => PlanGate::planDe($tenant->plan),
                'label'   => PlanGate::labelDe($tenant->plan),
                'limites' => $limites,
                'uso'     => $uso,
            ],

            'equipo' => UserResource::collection($equipo),

            // Los clientes del catálogo, aparte del equipo: comparten tabla pero
            // no son lo mismo ni cuentan para el tope del plan.
            'clientes' => User::where('tenant_id', $tenant->id)
                ->where('role', 'customer')
                ->count(),

            'ultimos_pedidos' => Order::withoutTenant()
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('created_at')
                ->take(5)
                ->get(['id', 'number', 'customer_name', 'status', 'total', 'created_at']),

            'bitacora' => ActivityLog::deTienda($tenant->id)
                ->orderByDesc('created_at')
                ->take(10)
                ->get(),
        ]);
    }

    /**
     * Entrar en una tienda como soporte (INF-2).
     *
     * Es la función que de verdad se usa cuando alguien escribe "no me
     * funciona": hasta ahora la única forma de ver su panel era pedirle la
     * contraseña, que es exactamente lo que nunca hay que hacer.
     *
     * Lo que se emite es un token del admin de la tienda, o sea una llave
     * prestada. Los cerrojos y el porqué de cada uno están en
     * `App\Support\Suplantacion`: caduca en 15 minutos, **solo lee** y queda
     * anotado. Se anota ANTES de emitirlo: si algo fallara al crear el token,
     * prefiero una línea de más en la bitácora que una entrada sin rastro.
     */
    public function impersonate(Request $request, Tenant $tenant): JsonResponse
    {
        // Una tienda suspendida tiene el panel cerrado por middleware, así que
        // el token entraría y toparía con un 403 en la primera pantalla. Mejor
        // decirlo aquí que mandar al operador a un panel que no carga.
        if (! $tenant->is_active) {
            return response()->json([
                'message' => 'La tienda está suspendida: reactívala antes de entrar como soporte.',
            ], 422);
        }

        $admin = User::where('tenant_id', $tenant->id)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->orderBy('created_at')
            ->first();

        if (! $admin) {
            return response()->json([
                'message' => 'Esta tienda no tiene ningún administrador activo.',
            ], 422);
        }

        ActivityLog::registrar(
            ActivityLog::TIENDA_SOPORTE,
            "Entró como soporte en {$tenant->name} (como {$admin->email}, solo lectura).",
            $tenant,
            $request->user(),
            ['como' => $admin->email, 'minutos' => Suplantacion::MINUTOS],
            $request,
        );

        // Sin `tokens()->delete()`, a diferencia de los dos logins: esto no es
        // iniciar sesión como esa persona, es mirar por encima de su hombro. Si
        // borrara sus tokens, echaría del panel al dueño en mitad de su trabajo
        // justo cuando llama pidiendo ayuda.
        $token = $admin->createToken(
            'support-token',
            [Suplantacion::ABILITY],
            now()->addMinutes(Suplantacion::MINUTOS),
        );

        return response()->json([
            'token'      => $token->plainTextToken,
            'user'       => new UserResource($admin),
            'tenant'     => $tenant,
            'expira_en'  => Suplantacion::MINUTOS,
            'message'    => 'Sesión de soporte en '.$tenant->name.': '.Suplantacion::MINUTOS.' minutos y solo lectura.',
        ]);
    }

    /**
     * Rescate cuando una tienda se queda sin NINGÚN administrador activo.
     *
     * Puede pasar por accidente —dos admins bajándose el uno al otro
     * exactamente a la vez, la carrera que cierra el `lockForUpdate` de
     * `UserController::impedirQuedarseSinAdmins`— o porque alguien desactivó al
     * último a mano. Sin admin nadie dentro de la tienda puede arreglarlo:
     * `EnsureAdmin` le cierra configuración, categorías, páginas, plan y equipo
     * a cualquier staff, y `sendAdminPasswordReset` no tiene a quién mandarle el
     * enlace —busca admins y no encuentra ninguno—. Antes de esto la única
     * salida era editar la base a mano.
     *
     * **Solo funciona si de verdad no hay ningún admin activo.** No es una
     * forma de que el operador reparta roles dentro de una tienda que ya se
     * gestiona sola —eso es cosa del propio equipo, en `UserController`—: es la
     * puerta de emergencia para el caso en que nadie puede.
     *
     * Se asciende a un **colaborador ya existente y activo**, nunca a un
     * cliente ni se crea una cuenta nueva: la persona ya demostró que puede
     * entrar (tiene contraseña, o la está esperando) y no hace falta inventar
     * una identidad ni saltarse el flujo de invitación.
     */
    public function rescueAdmin(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|string',
        ]);

        if (User::where('tenant_id', $tenant->id)->where('role', 'admin')->where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'Esta tienda ya tiene un administrador activo.',
            ], 422);
        }

        // Filtrado por tenant_id A MANO y no por route model binding, igual que
        // en UserController: sin tienda actual resuelta el scope de `User` no
        // protege nada por sí solo (FUN-14).
        $usuario = User::where('tenant_id', $tenant->id)
            ->where('id', $data['user_id'])
            ->where('role', 'staff')
            ->first();

        if (! $usuario) {
            return response()->json([
                'message' => 'Elige a alguien del equipo de esta tienda que sea colaborador.',
            ], 422);
        }

        if (! $usuario->is_active) {
            return response()->json([
                'message' => 'Esa persona está desactivada: actívala primero desde el equipo de la tienda.',
            ], 422);
        }

        $usuario->update(['role' => 'admin']);

        // Mismo motivo que en UserController::update: los middleware ya leen el
        // rol nuevo en su siguiente petición, pero su panel abierto se pintó
        // como staff, así que volver a entrar es la forma de que lo recargue.
        $usuario->tokens()->delete();

        ActivityLog::registrar(
            ActivityLog::TIENDA_RESCATE_ADMIN,
            "Rescató {$tenant->name} nombrando administrador a {$usuario->email} (se había quedado sin ninguno).",
            $tenant,
            $request->user(),
            ['nuevo_admin' => $usuario->email],
            $request,
        );

        return response()->json(new UserResource($usuario->fresh()));
    }

    /**
     * Bitácora del operador: qué se ha tocado, quién y cuándo (INF-2).
     *
     * Sin esto, "entrar como soporte" no se podría haber añadido: una llave que
     * abre tiendas ajenas sin dejar rastro no debe existir, ni siquiera siendo
     * de solo lectura.
     */
    public function logs(Request $request): JsonResponse
    {
        $logs = ActivityLog::query()
            // La tienda puede haberse borrado (la clave es `nullOnDelete`); para
            // ese caso el nombre está guardado dentro de `context`.
            ->with('tenant:id,name,slug')
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->string('tenant_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->orderByDesc('created_at')
            // Con tope: es la tabla que más crece de la plataforma —una línea por
            // cada acción del operador, para siempre— y un `per_page=100000`
            // la volcaría entera, con su tienda cargada, en una sola respuesta.
            ->paginate(min(max($request->integer('per_page', 30), 1), 100));

        return response()->json($logs);
    }

    /**
     * Anota en la bitácora lo que acaba de cambiar `updateTenant()`.
     *
     * Una línea por cosa cambiada y no una por petición: suspender una tienda y
     * bajarle el plan son dos decisiones distintas aunque viajen en el mismo
     * PUT, y luego se buscan por separado.
     */
    private function anotarCambio(
        Request $request,
        Tenant $tenant,
        array $data,
        ?string $planAnterior,
        bool $estabaActiva,
    ): void {
        $operador = $request->user();

        if (array_key_exists('is_active', $data) && $data['is_active'] !== $estabaActiva) {
            $data['is_active']
                ? ActivityLog::registrar(
                    ActivityLog::TIENDA_REACTIVADA,
                    "Reactivó {$tenant->name}.",
                    $tenant, $operador, [], $request,
                )
                : ActivityLog::registrar(
                    ActivityLog::TIENDA_SUSPENDIDA,
                    "Suspendió {$tenant->name}: su catálogo y su panel quedan cerrados.",
                    $tenant, $operador, [], $request,
                );
        }

        if (array_key_exists('plan', $data) && $data['plan'] !== $planAnterior) {
            ActivityLog::registrar(
                ActivityLog::TIENDA_PLAN,
                "Cambió el plan de {$tenant->name}: {$planAnterior} → {$data['plan']}.",
                $tenant,
                $operador,
                ['antes' => $planAnterior, 'despues' => $data['plan']],
                $request,
            );
        }
    }

    private function forgetPublicCache(Tenant $tenant): void
    {
        \Illuminate\Support\Facades\Cache::forget("tenant:{$tenant->slug}");
    }
}
