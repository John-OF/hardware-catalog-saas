<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Support\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

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
 * al fallo en cerrado de AUD-4 —su global scope esta desactivado, y el porque
 * esta escrito en el modelo—, asi que aqui no hay red debajo: lo que aisla las
 * tiendas es cada uno de estos `where`. Es el mismo motivo por el que existe
 * `TenantIsolationTest`.
 *
 * **Solo se ven los usuarios del PANEL.** Los clientes del catalogo viven en
 * esta misma tabla con rol `customer`; listarlos aqui mezclaria a los
 * compradores con el equipo (para verlos hace falta `MOD-10`, que no existe).
 */
class UserController extends Controller
{
    /** Roles que dan acceso al panel. `staff` es la segunda fase de FUN-4. */
    private const ROLES_DE_PANEL = ['admin', 'staff'];

    public function index(Request $request): JsonResponse
    {
        $usuarios = User::where('tenant_id', app('currentTenant')->id)
            ->whereIn('role', self::ROLES_DE_PANEL)
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
            // Unico DENTRO de la tienda, como manda SEC-4: el mismo correo puede
            // ser cliente en otra tienda, o incluso dueno de otra.
            //
            // Se comprueba con un closure y no con `Rule::unique` para poder
            // decir POR QUE esta ocupado. El caso que confunde es real: el correo
            // suele estar cogido por un CLIENTE del catalogo -que vive en esta
            // misma tabla- y un "ya hay alguien con ese correo" a secas deja al
            // dueno buscando entre su equipo a una persona que no esta ahi.
            'email' => [
                'required',
                'email',
                'max:200',
                function ($atributo, $valor, $fallar) use ($tenant) {
                    $existente = User::where('tenant_id', $tenant->id)
                        ->where('email', $valor)
                        ->first();

                    if (! $existente) {
                        return;
                    }

                    $fallar($existente->role === 'customer'
                        ? 'Ese correo ya lo usa un cliente registrado en tu catálogo, así que no se puede reutilizar para el panel. Invita a esta persona con otra dirección.'
                        : 'Ya hay alguien de tu equipo con ese correo.');
                },
            ],
        ], [
            'name.required'  => 'Escribe el nombre de la persona.',
            'email.required' => 'Escribe su correo electrónico.',
            'email.email'    => 'Ese correo no parece válido.',
        ]);

        $usuario = new User([
            'name'     => $data['name'],
            'email'    => $data['email'],
            // Aleatoria y desechable: el invitado la sustituye al aceptar. No se
            // deja nula porque la columna no lo admite y porque una contrasenia
            // vacia seria una puerta abierta si algun dia se compara mal.
            'password' => Str::random(40),
            'role'     => 'admin',
            'is_active' => true,
        ]);
        $usuario->tenant_id = $tenant->id;
        $usuario->save();

        $this->enviarInvitacion($usuario, $tenant->name, (string) $request->user()->name);

        return response()->json(new UserResource($usuario), 201);
    }

    /**
     * Activar o desactivar a alguien del equipo.
     *
     * Desactivar es la forma de echar a un vendedor sin borrar su rastro: sus
     * pedidos y sus cambios siguen siendo suyos. `is_active` ya lo comprueban el
     * login y `EnsureAdmin`, asi que basta con esto para cerrarle la puerta.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $usuario = $this->delEquipo($id);

        $data = $request->validate([
            'name'      => 'sometimes|string|max:200',
            'is_active' => 'sometimes|boolean',
        ]);

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $this->impedirQuedarseSinAdmins($usuario, $request);
        }

        $usuario->update($data);

        // Un usuario desactivado con la sesion abierta seguiria dentro hasta que
        // caducara su token: `EnsureAdmin` lo mira en cada peticion, pero el
        // token sigue siendo valido. Se le cierran las sesiones al apagarlo.
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $usuario->tokens()->delete();
        }

        return response()->json(new UserResource($usuario->fresh()));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $usuario = $this->delEquipo($id);

        $this->impedirQuedarseSinAdmins($usuario, $request);

        $usuario->tokens()->delete();
        $usuario->delete();

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
            ->whereIn('role', self::ROLES_DE_PANEL)
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
     * manual y a destiempo.
     */
    private function impedirQuedarseSinAdmins(User $usuario, Request $request): void
    {
        if ($usuario->id === $request->user()->id) {
            abort(422, 'No puedes quitarte a ti mismo el acceso al panel.');
        }

        $activos = User::where('tenant_id', app('currentTenant')->id)
            ->where('role', 'admin')
            ->where('is_active', true)
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
