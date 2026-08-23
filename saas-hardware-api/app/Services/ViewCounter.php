<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Contador de visitas que no escribe en la base en cada peticion (AUD-2).
 *
 * Antes, ver el catalogo hacia un `increment('views_count')` por peticion, y
 * ademas ANTES de mirar la cache: la respuesta salia cacheada pero el UPDATE se
 * hacia igual, asi que la cache no protegia de nada. Como todas las visitas de
 * una tienda caen sobre la MISMA fila de `tenants`, con concurrencia eso es
 * contencion de bloqueo sobre una fila caliente y las escrituras legitimas de
 * esa tienda —un pedido, un cambio de producto— empiezan a esperar detras. Sin
 * coste ni autenticacion para quien lo provoca.
 *
 * Aqui las visitas se acumulan en cache y se vuelcan como mucho una vez cada
 * VENTANA_SEGUNDOS por recurso: N peticiones pasan a ser 1 UPDATE.
 *
 * El volcado lo hace la propia peticion que abre la ventana, NO una tarea
 * programada, y es deliberado: este proyecto todavia no tiene worker de colas ni
 * scheduler corriendo (ver 11.10), asi que colgar el contador de ellos
 * significaria que el panel muestra 0 visitas para siempre. El dia que se
 * levante el scheduler, `flush()` sirve igual desde un comando sin tocar nada.
 *
 * No se pierde ninguna visita: lo que entra mientras se escribe queda en el
 * contador y se vuelca en la ventana siguiente.
 *
 * LO QUE ESTO NO ARREGLA, dicho claro: en produccion CACHE_STORE=database, asi
 * que el acumulador tambien es una fila —la de la clave `views:tenants:{id}` en
 * la tabla `cache`— y sigue siendo una sola por tienda. La contencion se mueve,
 * no desaparece. Lo que SI cambia, y es el motivo de hacerlo igual:
 *
 *   - Deja de caer sobre `tenants`, que es donde viven las escrituras que de
 *     verdad importan (alta de pedidos, cambios de configuracion). Antes esas
 *     esperaban detras de las visitas; ahora no se cruzan.
 *   - La tabla `cache` se toca con un UPDATE por clave, sin eventos de modelo,
 *     sin global scopes y sin nada que dependa de ella transaccionalmente.
 *   - El throttle del grupo publico pone un techo a cuantas visitas por segundo
 *     puede provocar una misma IP.
 *
 * La solucion completa es un store de cache en memoria (Redis) para contadores y
 * rate limiting. Queda apuntado para cuando se decida la infraestructura de
 * despliegue, junto con TEC-5.
 */
class ViewCounter
{
    /** Cada cuanto, como maximo, se toca la base por recurso. */
    private const VENTANA_SEGUNDOS = 300;

    /**
     * TTL del contador acumulado. Vive mucho mas que la ventana a proposito: si
     * nadie vuelve a visitar el recurso justo cuando vence, lo pendiente sigue
     * ahi para el siguiente que pase en vez de evaporarse.
     */
    private const TTL_ACUMULADO = 86400;

    /**
     * El nombre de la tabla acaba dentro de una consulta, asi que no se acepta
     * cualquiera aunque hoy solo llamen dos sitios con literales.
     */
    private const TABLAS = ['tenants', 'products'];

    /**
     * Anota una visita. Solo escribe en la base la primera de cada ventana.
     */
    public function record(string $tabla, string $id): void
    {
        $this->assertTabla($tabla);

        $acumulado = $this->claveAcumulado($tabla, $id);

        // add() no pisa el valor si la clave ya existe. Hace falta porque
        // increment() sobre una clave inexistente no crea nada en varios stores:
        // sin esto el contador se quedaria siempre a cero.
        Cache::add($acumulado, 0, self::TTL_ACUMULADO);
        Cache::increment($acumulado);

        // La puerta la abre solo la primera peticion de cada ventana: add()
        // devuelve false mientras la clave siga viva. Esto es lo que convierte
        // "una escritura por visita" en "una escritura cada VENTANA_SEGUNDOS".
        if (Cache::add($this->clavePuerta($tabla, $id), 1, self::VENTANA_SEGUNDOS)) {
            $this->flush($tabla, $id);
        }
    }

    /**
     * Vuelca a la base lo acumulado para un recurso.
     */
    public function flush(string $tabla, string $id): void
    {
        $this->assertTabla($tabla);

        $acumulado = $this->claveAcumulado($tabla, $id);
        $pendientes = (int) Cache::get($acumulado, 0);

        if ($pendientes < 1) {
            return;
        }

        // Se descuenta ANTES de escribir para no contar dos veces lo que llegue
        // mientras dura el UPDATE: esas visitas se quedan en el contador y salen
        // en el volcado siguiente.
        Cache::decrement($acumulado, $pendientes);

        try {
            DB::table($tabla)->where('id', $id)->increment('views_count', $pendientes);
        } catch (\Throwable $e) {
            // Si el UPDATE falla, lo descontado vuelve al contador en vez de
            // perderse. Un contador de visitas no justifica tumbar la peticion:
            // el visitante venia a ver el catalogo, no a que le contaran.
            Cache::increment($acumulado, $pendientes);

            Log::warning('No se pudo volcar el contador de visitas', [
                'tabla'      => $tabla,
                'id'         => $id,
                'pendientes' => $pendientes,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function claveAcumulado(string $tabla, string $id): string
    {
        return "views:{$tabla}:{$id}";
    }

    private function clavePuerta(string $tabla, string $id): string
    {
        return "views:{$tabla}:{$id}:puerta";
    }

    private function assertTabla(string $tabla): void
    {
        if (!in_array($tabla, self::TABLAS, true)) {
            throw new InvalidArgumentException("ViewCounter no cuenta visitas de la tabla '{$tabla}'.");
        }
    }
}
