<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Anotar en la bitácora lo que hace el equipo desde el panel de tienda (INF-3).
 *
 * Con una sola persona por tienda daba igual; con varias (`FUN-4`) la pregunta
 * "¿quién cambió este precio?" no tenía respuesta.
 *
 * Se llama desde los controladores, **después** de guardar, y no desde eventos
 * de los modelos: un evento no sabe si el cambio lo hizo una persona en el panel
 * o el sistema (el stock que baja con cada pedido del catálogo), y la bitácora
 * responde a lo primero. La contrapartida es que una ruta nueva puede nacer sin
 * anotar nada; lo vigila `BitacoraDeTiendaTest`, que obliga a decidir para cada
 * ruta que escribe si se anota o por qué no.
 *
 * **Anotar nunca tumba la acción.** Cuando se llega aquí el cambio ya está
 * guardado; si la escritura en la bitácora fallara y subiera el error, el dueño
 * vería un 500, creería que no se guardó y lo repetiría. Se registra en el log y
 * se sigue.
 */
class Bitacora
{
    /**
     * @param  array<string, mixed>  $contexto  El detalle que da sentido a la línea
     *                                          (qué cambió, de qué a qué).
     */
    public static function anotar(string $accion, string $descripcion, array $contexto = []): void
    {
        $request = request();

        try {
            ActivityLog::registrar(
                $accion,
                // La columna es de 255: una descripción con el nombre largo de un
                // producto y cinco cambios no puede costar la línea entera.
                mb_strimwidth($descripcion, 0, 255, '…'),
                app('currentTenant'),
                $request->user(),
                $contexto,
                $request,
                ActivityLog::ORIGEN_TIENDA,
            );
        } catch (Throwable $e) {
            Log::error('No se pudo anotar en la bitácora de la tienda.', [
                'accion'    => $accion,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Qué campos cambiaron entre dos fotos del mismo registro.
     *
     * Compara como texto y con los decimales normalizados, porque la foto de
     * antes sale de la base ("1500.00") y la de después puede venir del
     * formulario ("1500"): sin eso, guardar sin tocar nada anotaría un cambio.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     * @param  array<string, string>  $campos  campo => cómo se llama para el dueño
     * @return array<string, array{0: mixed, 1: mixed}>  etiqueta => [antes, después]
     */
    public static function cambios(array $antes, array $despues, array $campos): array
    {
        $cambios = [];

        foreach ($campos as $campo => $etiqueta) {
            if (! array_key_exists($campo, $despues)) {
                continue;
            }

            $viejo = $antes[$campo] ?? null;
            $nuevo = $despues[$campo];

            if (self::normalizar($viejo) !== self::normalizar($nuevo)) {
                $cambios[$etiqueta] = [$viejo, $nuevo];
            }
        }

        return $cambios;
    }

    /**
     * "precio 1500 → 1450, stock 10 → 8", para la descripción de la línea.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $cambios
     */
    public static function resumirCambios(array $cambios): string
    {
        return collect($cambios)
            ->map(fn (array $par, string $etiqueta) => $etiqueta.' '.self::legible($par[0]).' → '.self::legible($par[1]))
            ->implode(', ');
    }

    private static function normalizar(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        if (is_numeric($valor)) {
            return (string) ($valor + 0);
        }

        return (string) $valor;
    }

    private static function legible(mixed $valor): string
    {
        return match (true) {
            $valor === null || $valor === '' => 'vacío',
            is_bool($valor)                  => $valor ? 'sí' : 'no',
            is_numeric($valor)               => (string) ($valor + 0),
            default                          => mb_strimwidth((string) $valor, 0, 40, '…'),
        };
    }
}
