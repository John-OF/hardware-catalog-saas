<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quién es quién en el directorio de clientes de una tienda (MOD-24).
 *
 * Cada pedido —del catálogo o de mostrador— y cada cuenta de cliente que se abre
 * pasa por aquí, que busca a la persona y, si no está, la crea. **Se junta por
 * los últimos 8 dígitos del teléfono o por el correo**: "+51 987 654 321" de un
 * pedido del catálogo y "987654321" de una venta de mostrador son la misma
 * persona. Son los mismos 8 dígitos que usan las reseñas para saber si un
 * teléfono compró (`ACC-2`): dejan fuera las coincidencias de azar y caben en
 * los números más cortos de la región sin prefijo.
 *
 * Reglas, en orden:
 * - **Con cuenta**, manda la cuenta: se busca la fila de ese `user_id`.
 * - Si no, la más antigua con ese teléfono y, si no, con ese correo.
 * - **Un invitado se junta con una cuenta** que tenga su teléfono o su correo
 *   (es el dueño de la cuenta comprando sin entrar), pero **dos cuentas no se
 *   juntan nunca**: una fila que ya es de otra cuenta no se le da a esta.
 * - De una fila que ya existe no se pisa nada: solo se rellenan el teléfono, el
 *   correo o la cuenta que le falten. El nombre se queda el primero.
 * - Sin teléfono, correo ni cuenta (una venta de mostrador a «Cliente») no hay a
 *   quién apuntar el pedido, y no se crea nadie.
 *
 * Funciona con y sin tienda resuelta (lo llama también la migración), así que
 * todas las consultas van con `withoutTenant()` y el `tenant_id` a mano.
 */
class Directorio
{
    /** Los dígitos del final que identifican un teléfono (los mismos que `ACC-2`). */
    public const DIGITOS_DEL_TELEFONO = 8;

    /** Lo que mide como mucho una etiqueta. */
    public const LARGO_DE_ETIQUETA = 30;

    public static function claveDeTelefono(?string $telefono): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefono);

        return strlen($digitos) >= self::DIGITOS_DEL_TELEFONO ? substr($digitos, -self::DIGITOS_DEL_TELEFONO) : null;
    }

    public static function claveDeCorreo(?string $correo): ?string
    {
        $correo = mb_strtolower(trim((string) $correo));

        return filter_var($correo, FILTER_VALIDATE_EMAIL) ? $correo : null;
    }

    /**
     * La persona de unos datos, creándola si no está. `null` si no hay con qué
     * identificarla (ni cuenta, ni teléfono, ni correo).
     */
    public static function dePersona(
        string $tenantId,
        ?string $userId,
        ?string $nombre,
        ?string $telefono,
        ?string $correo,
        ?Carbon $desde = null,
    ): ?Customer {
        $claveTelefono = self::claveDeTelefono($telefono);
        $claveCorreo = self::claveDeCorreo($correo);

        if (! $userId && ! $claveTelefono && ! $claveCorreo) {
            return null;
        }

        $persona = self::buscar($tenantId, $userId, $claveTelefono, $claveCorreo);
        $nombre = trim((string) $nombre);

        if (! $persona) {
            $persona = new Customer;
            $persona->tenant_id = $tenantId;
            $persona->name = $nombre !== '' ? mb_substr($nombre, 0, 200) : 'Sin nombre';

            if ($desde) {
                $persona->created_at = $desde;
            }
        }

        if ($persona->name === 'Sin nombre' && $nombre !== '') {
            $persona->name = mb_substr($nombre, 0, 200);
        }

        if (! $persona->phone_key && $claveTelefono) {
            $persona->phone = mb_substr(trim((string) $telefono), 0, 30);
            $persona->phone_key = $claveTelefono;
        }

        if (! $persona->email_key && $claveCorreo) {
            $persona->email = mb_substr(trim((string) $correo), 0, 200);
            $persona->email_key = $claveCorreo;
        }

        if (! $persona->user_id && $userId) {
            $persona->user_id = $userId;
        }

        // «Cliente desde» es lo primero que se supo de ella, aunque llegue después.
        if ($desde && $persona->exists && $persona->created_at && $desde->lt($persona->created_at)) {
            $persona->created_at = $desde;
        }

        if ($persona->isDirty()) {
            $persona->save();
        }

        return $persona;
    }

    /** La persona de un pedido; deja el pedido apuntándole. */
    public static function registrarPedido(Order $pedido): ?Customer
    {
        $persona = self::dePersona(
            $pedido->tenant_id,
            $pedido->user_id,
            $pedido->customer_name,
            $pedido->customer_phone,
            $pedido->customer_email,
            $pedido->created_at,
        );

        if ($persona && $pedido->customer_id !== $persona->id) {
            // Sin pasar por el modelo: apuntar el pedido no es editarlo, y no
            // tiene por qué mover su `updated_at` ni disparar nada.
            DB::table('orders')->where('id', $pedido->id)->update(['customer_id' => $persona->id]);
            $pedido->customer_id = $persona->id;
            $pedido->syncOriginalAttribute('customer_id');
        }

        return $persona;
    }

    /**
     * `registrarPedido()` sin tumbar nada: el pedido ya está guardado, y que el
     * directorio falle no puede devolverle un error al comprador ni hacerle
     * creer que su pedido no entró. Queda en el log, y `clientes:reconstruir`
     * enlaza después lo que se quedó suelto.
     */
    public static function registrarPedidoSinFallar(Order $pedido): void
    {
        try {
            self::registrarPedido($pedido);
        } catch (\Throwable $e) {
            Log::error('No se pudo apuntar el pedido en el directorio de clientes', [
                'tenant_id' => $pedido->tenant_id,
                'order_id'  => $pedido->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /** Lo mismo con una cuenta que se acaba de abrir. */
    public static function deCuentaSinFallar(User $cuenta): void
    {
        try {
            self::deCuenta($cuenta);
        } catch (\Throwable $e) {
            Log::error('No se pudo apuntar la cuenta en el directorio de clientes', [
                'tenant_id' => $cuenta->tenant_id,
                'user_id'   => $cuenta->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /** La persona de una cuenta de cliente que se acaba de abrir. */
    public static function deCuenta(User $cuenta): ?Customer
    {
        return self::dePersona($cuenta->tenant_id, $cuenta->id, $cuenta->name, $cuenta->phone, $cuenta->email, $cuenta->created_at);
    }

    /**
     * Rehace el directorio de una tienda con lo que ya hay: primero las cuentas
     * de cliente y después los pedidos que no apuntan a nadie, del más antiguo
     * al más nuevo. Lo usa la migración, y vale para reparar: lo que ya está
     * enlazado no se toca, así que repetirlo no duplica a nadie.
     */
    public static function reconstruir(string $tenantId): void
    {
        DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('role', 'customer')
            ->orderBy('created_at')
            ->get(['id', 'name', 'phone', 'email', 'created_at'])
            ->each(fn ($cuenta) => self::dePersona(
                $tenantId,
                $cuenta->id,
                $cuenta->name,
                $cuenta->phone,
                $cuenta->email,
                $cuenta->created_at ? Carbon::parse($cuenta->created_at) : null,
            ));

        DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereNull('customer_id')
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'customer_name', 'customer_phone', 'customer_email', 'created_at'])
            ->each(function ($pedido) use ($tenantId) {
                $persona = self::dePersona(
                    $tenantId,
                    $pedido->user_id,
                    $pedido->customer_name,
                    $pedido->customer_phone,
                    $pedido->customer_email,
                    $pedido->created_at ? Carbon::parse($pedido->created_at) : null,
                );

                if ($persona) {
                    DB::table('orders')->where('id', $pedido->id)->update(['customer_id' => $persona->id]);
                }
            });
    }

    /**
     * Las etiquetas como se guardan: sin espacios de más, sin repetir (sin mirar
     * mayúsculas; se queda la primera forma) y como mucho las que admite.
     *
     * @param  array<int, mixed>|null  $etiquetas
     * @return array<int, string>
     */
    public static function etiquetas(?array $etiquetas): array
    {
        $vistas = [];

        foreach ($etiquetas ?? [] as $etiqueta) {
            $texto = mb_substr(preg_replace('/\s+/u', ' ', trim((string) $etiqueta)), 0, self::LARGO_DE_ETIQUETA);
            $clave = mb_strtolower($texto);

            if ($texto !== '' && ! isset($vistas[$clave])) {
                $vistas[$clave] = $texto;
            }
        }

        return array_slice(array_values($vistas), 0, Customer::MAXIMO_DE_ETIQUETAS);
    }

    private static function buscar(string $tenantId, ?string $userId, ?string $claveTelefono, ?string $claveCorreo): ?Customer
    {
        $deLaTienda = fn () => Customer::withoutTenant()->where('tenant_id', $tenantId);

        if ($userId) {
            $suya = $deLaTienda()->where('user_id', $userId)->first();

            if ($suya) {
                return $suya;
            }
        }

        // Una fila de otra cuenta no es de esta: dos cuentas no se juntan.
        $libre = fn ($consulta) => $userId
            ? $consulta->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
            : $consulta;

        if ($claveTelefono) {
            $porTelefono = $libre($deLaTienda()->where('phone_key', $claveTelefono))->orderBy('created_at')->first();

            if ($porTelefono) {
                return $porTelefono;
            }
        }

        if ($claveCorreo) {
            return $libre($deLaTienda()->where('email_key', $claveCorreo))->orderBy('created_at')->first();
        }

        return null;
    }
}
