<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Los usuarios que pueden entrar al panel de una tienda (FUN-4).
 *
 * Hasta aqui una tienda era **un solo administrador**: el que se creaba en el
 * alta y ninguno mas. No habia endpoint ni pantalla para dar de alta a otro, asi
 * que el dueno que tenia un vendedor le pasaba su propia contrasenia — y como el
 * login **borra los tokens anteriores** (una sesion activa por usuario), los dos
 * se echaban mutuamente todo el dia. La tienda con dos personas no estaba
 * incomoda: no funcionaba.
 *
 * **Todas las consultas filtran por `tenant_id` A MANO.** `User` es la excepcion
 * al fallo en cerrado de AUD-4 —sin tienda resuelta su global scope lo ve todo,
 * y el porque esta escrito en el modelo—. Aqui la tienda si esta resuelta y el
 * scope tambien filtra, pero estos `where` no se apoyan en eso: son lo que aisla
 * las tiendas si algun dia esta ruta se quedara sin el middleware de tienda. Es
 * el mismo motivo por el que existe `TenantIsolationTest`.
 *
 * **Solo se ven los usuarios del PANEL.** Los clientes del catalogo viven en
 * esta misma tabla con rol `customer`; listarlos aqui mezclaria a los
 * compradores con el equipo (para verlos hace falta `MOD-10`, que no existe).
 *
 * **Dos roles: `admin` y `staff`** (segunda mitad de FUN-4). Todo este
 * controlador va detras del middleware `admin`: el equipo lo gestiona solo un
 * admin, y un staff ni lo ve. Que puede hacer cada rol en el resto del panel
 * esta en `routes/api.php`.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $usuarios = User::where('tenant_id', app('currentTenant')->id)
            ->whereIn('role', User::ROLES_DE_PANEL)
            ->orderBy('created_at')
            ->get();

        return response()->json(UserResource::collection($usuarios));
    }

    /**
     * Invitar a alguien al panel.
     *
     * **No se le pone contrasenia.** Se crea con una aleatoria que nadie conoce
     * -ni quien invita- y se le manda un enlace para que elija la suya. La
     * alternativa, que el dueno escriba una y se la pase por WhatsApp, significa
     * una contrasenia compartida por un canal que queda escrito en un chat.
     *
     * El enlace es el del broker de recuperacion (`SAAS-2`): mismo token, misma
     * caducidad, misma pantalla del SPA. Ver `TeamInvitationNotification`.
     */
    public function store(Request $request): JsonResponse
    {
        PlanGate::ensureCanCreate('users');

        $tenant = app('currentTenant');

        $data = $request->validate([
            'name'  => 'required|string|max:200',
            // Dos reglas distintas, y el closure es para poder decir POR QUE esta
            // ocupado:
            //
            // 1. Dentro de la tienda, unico frente a cualquiera (SEC-4). El caso
            //    que confunde es real: el correo suele estar cogido por un
            //    CLIENTE del catalogo -que vive en esta misma tabla- y un "ya hay
            //    alguien con ese correo" a secas deja al dueno buscando entre su
            //    equipo a una persona que no esta ahi.
            // 2. Fuera de la tienda, unico frente al PANEL de cualquier otra
            //    (FUN-14). El login, el reset y el enlace de esta misma
            //    invitacion resuelven el correo sin saber la tienda y se quedan
            //    con la cuenta mas antigua: invitar a quien ya es del equipo de
            //    otra tienda le cambiaba la contrasenia de ALLI y dejaba la
            //    cuenta de aqui sin poder entrar nunca. Ser cliente de otra
            //    tienda si se permite: el login del panel no mira clientes.
            'email' => [
                'required',
                'email',
                'max:200',
                function ($atributo, $valor, $fallar) use ($tenant) {
                    $existente = User::where('tenant_id', $tenant->id)
                        ->where('email', $valor)
                        ->first();

                    if ($existente) {
                        $fallar($existente->role === 'customer'
                            ? 'Ese correo ya lo usa un cliente registrado en tu catálogo, así que no se puede reutilizar para el panel. Invita a esta persona con otra dirección.'
                            : 'Ya hay alguien de tu equipo con ese correo.');

                        return;
                    }

                    // `withoutTenant()` es imprescindible, no decorativo: con la
                    // tienda resuelta el scope de `User` SI filtra por ella (lo
                    // que no hace es fallar en cerrado sin tienda), y esta
                    // consulta pregunta justo por las demas. Sin el, devolvia
                    // siempre vacio y la regla no cerraba nada.
                    $enOtroPanel = User::withoutTenant()
                        ->where('tenant_id', '!=', $tenant->id)
                        ->where('email', $valor)
                        ->whereIn('role', User::ROLES_DE_PANEL)
                        ->exists();

                    if ($enOtroPanel) {
                        $fallar('Ese correo ya tiene acceso al panel de otra tienda, y por ahora cada correo solo puede estar en una. Invita a esta persona con otra dirección.');
                    }
                },
            ],
            // Por defecto `staff`: el acceso minimo que sirve para trabajar. Dar
            // poder de admin tiene que ser una decision, no lo que pasa si nadie
            // toca el desplegable.
            'role' => ['sometimes', Rule::in(User::ROLES_DE_PANEL)],
        ], [
            'name.required'  => 'Escribe el nombre de la persona.',
            'email.required' => 'Escribe su correo electrónico.',
            'email.email'    => 'Ese correo no parece válido.',
            'role.in'        => 'Elige un rol válido: administrador o colaborador.',
        ]);

        $usuario = new User([
            'name'     => $data['name'],
            'email'    => $data['email'],
            // Aleatoria y desechable: el invitado la sustituye al aceptar. No se
            // deja nula porque la columna no lo admite y porque una contrasenia
            // vacia seria una puerta abierta si algun dia se compara mal.
            'password' => Str::random(40),
            'role'     => $data['role'] ?? 'staff',
            'is_active' => true,
        ]);
        $usuario->tenant_id = $tenant->id;
        $usuario->save();

        $this->enviarInvitacion($usuario, $tenant->name, (string) $request->user()->name);

        return response()->json(new UserResource($usuario), 201);
    }

    /**
     * Activar o desactivar a alguien del equipo, o cambiarle el rol.
     *
     * Desactivar es la forma de echar a un vendedor sin borrar su rastro: sus
     * pedidos y sus cambios siguen siendo suyos. `is_active` ya lo comprueban el
     * login y `EnsurePanelUser`, asi que basta con esto para cerrarle la puerta.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $usuario = $this->delEquipo($id);

        $data = $request->validate([
            'name'      => 'sometimes|string|max:200',
            'is_active' => 'sometimes|boolean',
            'role'      => ['sometimes', Rule::in(User::ROLES_DE_PANEL)],
        ], [
            'role.in' => 'Elige un rol válido: administrador o colaborador.',
        ]);

        $seApaga   = array_key_exists('is_active', $data) && ! $data['is_active'];
        $cambiaRol = isset($data['role']) && $data['role'] !== $usuario->role;
        $seDegrada = $cambiaRol && $usuario->role === 'admin';

        // Quien llega aqui es admin, asi que cambiarse el rol a si mismo solo
        // puede ser bajarse a staff: quedarse fuera de esta misma pantalla.
        if ($cambiaRol && $usuario->id === $request->user()->id) {
            abort(422, 'No puedes cambiar tu propio rol.');
        }

        // Bajar a un admin a staff le quita el poder de admin igual que
        // desactivarlo, asi que pasa por las mismas protecciones: la tienda no
        // puede quedarse sin ningun admin activo.
        //
        // Comprobar y escribir van en la MISMA transaccion, con las filas de
        // los admins bloqueadas (`lockForUpdate` dentro del metodo de abajo).
        // Sin esto hay una carrera: dos admins bajandose el uno al otro a la
        // vez pueden pasar los dos la comprobacion -cada peticion cuenta "2"
        // antes de que la otra escriba- y la tienda se queda sin ninguno. Con
        // el bloqueo, la segunda peticion espera a que la primera termine de
        // escribir y entonces cuenta "1" de verdad.
        if ($seApaga || $seDegrada) {
            DB::transaction(function () use ($usuario, $request, $data) {
                $this->impedirQuedarseSinAdmins($usuario, $request);
                $usuario->update($data);
            });
        } else {
            $usuario->update($data);
        }

        // Un usuario desactivado con la sesion abierta seguiria dentro hasta que
        // caducara su token: `EnsurePanelUser` lo mira en cada peticion, pero el
        // token sigue siendo valido. Se le cierran las sesiones al apagarlo, y
        // tambien al cambiarle el rol: los middleware ya leen el rol nuevo en su
        // siguiente peticion, pero el panel que tiene abierto se pinto con el
        // viejo -menus y botones de admin que ahora le darian 403-, y volver a
        // entrar es la forma de que lo recargue entero.
        if ($seApaga || $cambiaRol) {
            $usuario->tokens()->delete();
        }

        return response()->json(new UserResource($usuario->fresh()));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $usuario = $this->delEquipo($id);

        // Misma transaccion con candado que en update(): dos borrados a la vez
        // tienen la misma carrera que dos bajadas de rol.
        DB::transaction(function () use ($usuario, $request) {
            $this->impedirQuedarseSinAdmins($usuario, $request);

            $usuario->tokens()->delete();
            $usuario->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * Reenviar la invitacion a quien todavia no ha entrado.
     *
     * El enlace caduca (60 minutos por defecto), asi que la invitacion que se
     * queda en el spam un dia entero no sirve de nada sin esto.
     */
    public function resend(Request $request, string $id): JsonResponse
    {
        $usuario = $this->delEquipo($id);

        $this->enviarInvitacion(
            $usuario,
            (string) app('currentTenant')->name,
            (string) $request->user()->name,
        );

        return response()->json([
            'message' => "Le reenviamos la invitación a {$usuario->email}.",
        ]);
    }

    /**
     * Un usuario del panel de ESTA tienda, o 404.
     *
     * A mano y sin route model binding a proposito: el binding usaria el modelo
     * sin scope (AUD-4) y resolveria usuarios de cualquier tienda, que es
     * exactamente el IDOR que `TenantIsolationTest` vigila. Un usuario de otra
     * tienda tiene que responder 404, no 403: que exista tampoco es asunto de
     * quien pregunta.
     */
    private function delEquipo(string $id): User
    {
        return User::where('tenant_id', app('currentTenant')->id)
            ->whereIn('role', User::ROLES_DE_PANEL)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Que nadie se quede fuera de su propia tienda.
     *
     * Dos protecciones distintas y las dos hacen falta: **no puedes apagarte a ti
     * mismo** (el error de dedo mas facil de cometer y el mas caro) y **no puede
     * quedarse la tienda sin ningun admin activo**. Sin la segunda, dos personas
     * pueden desactivarse la una a la otra y dejar el panel cerrado para todos;
     * recuperarlo exigiria al operador de la plataforma, que es un rescate
     * manual y a destiempo (para cuando de verdad hace falta, esta `PlatformController::rescueAdmin`).
     *
     * **SIEMPRE se llama dentro de un `DB::transaction()`.** El `lockForUpdate()`
     * de abajo bloquea las filas de los admins activos hasta que esa transaccion
     * termina; fuera de una transaccion el bloqueo se libera nada mas terminar
     * la consulta y no protege nada. Es lo que cierra la carrera de dos admins
     * bajandose el uno al otro exactamente a la vez: cada peticion, por
     * separado, cuenta "2" y las dos se dejarian pasar si contaran a la vez.
     * Con el candado, la segunda espera a que la primera escriba y entonces
     * cuenta "1" de verdad. No se puede probar con concurrencia real en la
     * suite -SQLite en memoria no bloquea filas-, asi que esto se verifico a
     * mano contra MySQL, no con un test.
     */
    private function impedirQuedarseSinAdmins(User $usuario, Request $request): void
    {
        if ($usuario->id === $request->user()->id) {
            abort(422, 'No puedes quitarte a ti mismo el acceso al panel.');
        }

        $activos = User::where('tenant_id', app('currentTenant')->id)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->lockForUpdate()
            ->count();

        if ($usuario->role === 'admin' && $usuario->is_active && $activos <= 1) {
            abort(422, 'Esta tienda se quedaría sin ningún administrador activo.');
        }
    }

    private function enviarInvitacion(User $usuario, string $tienda, string $invitadoPor): void
    {
        $token = Password::broker()->createToken($usuario);

        $usuario->notify(new TeamInvitationNotification($token, $tienda, $invitadoPor));
    }
}
