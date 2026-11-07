<?php

namespace App\Support;

use App\Models\Tenant;
use App\Services\ImageService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Las pruebas abandonadas: cuándo se avisa y cuándo se borran sus datos (INF-24).
 *
 * Una prueba que vence sin pagar se cierra (`FUN-16`), pero su catálogo, sus
 * fotos, sus pedidos y sus clientes se quedaban para siempre: datos personales
 * de compradores guardados sin motivo y fotos ocupando almacenamiento por el que
 * se paga. A los 90 días de cerrarse, y tras dos avisos por correo, se borran.
 *
 * **Solo a quien nunca pagó** —sin ninguna fila en `tenant_payments`—. A quien
 * pagó alguna vez no se le borra nada nunca, aunque su plan venza: choca con
 * «bajar de plan nunca borra nada», y esa tienda tiene un trato con nosotros que
 * una prueba no tiene. **Se queda la tienda** (su nombre, su slug, su
 * configuración, sus categorías) **y se quedan las cuentas** (el equipo y los
 * clientes con cuenta): al pagar, la tienda vuelve a abrir, vacía.
 *
 * Los pasos, uno por día como mucho (el comando `pruebas:purgar`, a diario):
 *
 * 1. **Primer aviso**, a los 60 días del cierre. Ahí se fija la fecha del
 *    borrado (`purge_scheduled_at`): los 90 días, o 30 desde el aviso si el cron
 *    estuvo parado y el aviso sale tarde. Se guarda para que los dos correos y la
 *    pantalla de tienda cerrada digan la misma fecha.
 * 2. **Segundo aviso**, una semana antes de esa fecha. Si sale tarde, la fecha
 *    se corre para que entre el último aviso y el borrado haya siempre una
 *    semana: **nunca se borra antes de lo que se prometió**.
 * 3. **El borrado**, ya pasada la fecha y con los dos avisos dados.
 *
 * El borrado sigue las reglas de `TEC-16` (`docs/operacion.md`): cada `DELETE`
 * lleva su `where` por `tenant_id` escrito, nada de Eloquent ni de scopes, y se
 * comprueba con una cuenta que **no comparte la suposición con la acción**: la
 * tabla entera tiene que haber bajado exactamente lo que tenía esa tienda. Si
 * no cuadra, se deshace todo. Y no se borra nada sin una copia de la base de las
 * últimas horas (`Salud::copia()`), que es la única vuelta atrás.
 */
final class PruebasAbandonadas
{
    /** Días desde que se cerró la prueba hasta el borrado. `PrivacidadPage.tsx` y `TerminosPage.tsx` prometen este número. */
    public const DIAS_HASTA_BORRAR = 90;

    /** Días antes del borrado en que sale el primer aviso. */
    public const DIAS_PRIMER_AVISO = 30;

    /** Días antes del borrado en que sale el último aviso, y lo mínimo entre ese aviso y el borrado. */
    public const DIAS_SEGUNDO_AVISO = 7;

    public const PASO_PRIMER_AVISO = 'primer_aviso';

    public const PASO_SEGUNDO_AVISO = 'segundo_aviso';

    public const PASO_BORRAR = 'borrar';

    /**
     * Lo que se borra, en este orden: primero lo que cuelga de un pedido o de un
     * producto, después ellos. Cada entrada dice cómo se acota a la tienda: por
     * su propio `tenant_id`, o por el del pedido o el producto del que cuelga
     * (`product_images`, `order_items` y `user_favorites` no tienen columna de
     * tienda). Que la base borre en cascada no se da por hecho: se borra a mano,
     * y la comprobación dice si quedó algo.
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    private const TABLAS = [
        'order_items'         => ['order_id', 'orders'],
        'orders'              => ['tenant_id', null],
        'customers'           => ['tenant_id', null],
        'reviews'             => ['tenant_id', null],
        'stock_notifications' => ['tenant_id', null],
        'user_favorites'      => ['product_id', 'products'],
        'product_images'      => ['product_id', 'products'],
        'product_variants'    => ['tenant_id', null],
        'products'            => ['tenant_id', null],
    ];

    /** Cuántas URL de fotos se comprueban de una vez (SQLite pone tope a los parámetros). */
    private const FOTOS_POR_TANDA = 400;

    /**
     * Las tiendas a las que les puede tocar: cerradas, con la prueba vencida y
     * sin ningún pago. Con el plan vencido (`plan_ends_at`) no entra ninguna:
     * para tener plan hubo que pagarlo.
     */
    public static function candidatas(): Builder
    {
        return Tenant::query()
            ->where('is_active', false)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->whereDoesntHave('payments');
    }

    /** Lo mismo que `candidatas()`, para una tienda ya cargada. */
    public static function leToca(Tenant $tenant): bool
    {
        return ! $tenant->is_active
            && $tenant->trial_ends_at?->isPast() === true
            && ! $tenant->payments()->exists();
    }

    /** Qué le toca hoy a una candidata, o `null` si todavía nada. */
    public static function pasoDeHoy(Tenant $tenant): ?string
    {
        if ($tenant->data_purged_at !== null || $tenant->trial_ends_at === null) {
            return null;
        }

        $ahora = now();

        if ($tenant->purge_scheduled_at === null) {
            $cuando = $tenant->trial_ends_at->copy()->addDays(self::DIAS_HASTA_BORRAR - self::DIAS_PRIMER_AVISO);

            return $ahora->gte($cuando) ? self::PASO_PRIMER_AVISO : null;
        }

        if ($tenant->purge_reminded_at === null) {
            $cuando = $tenant->purge_scheduled_at->copy()->subDays(self::DIAS_SEGUNDO_AVISO);

            return $ahora->gte($cuando) ? self::PASO_SEGUNDO_AVISO : null;
        }

        return $ahora->gte($tenant->purge_scheduled_at) ? self::PASO_BORRAR : null;
    }

    /**
     * La fecha del borrado que se fija con el primer aviso: los 90 días desde el
     * cierre, o 30 desde hoy si el aviso sale tarde.
     */
    public static function fechaAlAvisar(Tenant $tenant): Carbon
    {
        return self::laMasTarde(
            $tenant->trial_ends_at->copy()->addDays(self::DIAS_HASTA_BORRAR),
            now()->addDays(self::DIAS_PRIMER_AVISO),
        );
    }

    /** La fecha con el último aviso: la prometida, o una semana desde hoy si sale tarde. */
    public static function fechaAlRecordar(Tenant $tenant): Carbon
    {
        return self::laMasTarde(
            $tenant->purge_scheduled_at->copy(),
            now()->addDays(self::DIAS_SEGUNDO_AVISO),
        );
    }

    /**
     * Para la pantalla de tienda cerrada y el panel del operador
     * (`Tenant::cierre()`): desde cuándo se borran sus datos, o cuándo se
     * borraron. `null` si a esta tienda no le toca (pagó alguna vez, o la cerró
     * el operador sin prueba de por medio).
     *
     * Antes del primer aviso la fecha es una estimación —la misma cuenta que hará
     * el aviso—, y puede correrse hacia delante si el aviso sale tarde, nunca
     * hacia atrás.
     *
     * @return array{borrado_desde: string|null, datos_borrados: string|null}|null
     */
    public static function estado(Tenant $tenant): ?array
    {
        if ($tenant->data_purged_at !== null) {
            return ['borrado_desde' => null, 'datos_borrados' => $tenant->data_purged_at->toIso8601String()];
        }

        if (! self::leToca($tenant)) {
            return null;
        }

        $fecha = $tenant->purge_scheduled_at
            ?? self::laMasTarde(
                $tenant->trial_ends_at->copy()->addDays(self::DIAS_HASTA_BORRAR),
                self::laMasTarde(
                    $tenant->trial_ends_at->copy()->addDays(self::DIAS_HASTA_BORRAR - self::DIAS_PRIMER_AVISO),
                    now(),
                )->addDays(self::DIAS_PRIMER_AVISO),
            );

        return ['borrado_desde' => $fecha->toIso8601String(), 'datos_borrados' => null];
    }

    /**
     * Cuántas filas de cada tabla son de la tienda, sin ningún scope.
     *
     * @return array<string, int>
     */
    public static function contar(string $tenantId): array
    {
        $cuentas = [];

        foreach (array_keys(self::TABLAS) as $tabla) {
            $cuentas[$tabla] = self::deLaTienda($tabla, $tenantId)->count();
        }

        return $cuentas;
    }

    /**
     * Borra los datos de la tienda: pedidos, clientes, productos (con su
     * galería, variantes, reseñas y listas de espera) y sus fotos. Devuelve lo
     * que había en cada tabla.
     *
     * Todo lo de la base va en una transacción y se comprueba antes de
     * confirmarla; los archivos se borran después, cuando ya no hay vuelta atrás
     * en la base: al revés, un fallo dejaría filas apuntando a fotos que ya no
     * existen.
     *
     * @return array<string, int>
     *
     * @throws \RuntimeException si lo borrado no cuadra (y entonces no se borró nada)
     */
    public static function borrar(Tenant $tenant, ImageService $imagenes): array
    {
        $id = (string) $tenant->id;

        if ($id === '') {
            throw new \RuntimeException('La tienda no tiene id: no se borra nada.');
        }

        // Las rutas de los archivos se apuntan antes: después de borrar las
        // filas ya no hay de dónde sacarlas.
        $fotos = self::fotosDe($id);
        $comprobantes = DB::table('orders')->where('tenant_id', $id)->whereNotNull('payment_proof_path')->pluck('payment_proof_path')->all();

        $cuentas = DB::transaction(function () use ($id) {
            $antes = self::contar($id);
            $totales = self::totales();

            foreach (array_keys(self::TABLAS) as $tabla) {
                $borradas = self::deLaTienda($tabla, $id)->delete();

                if ($borradas !== $antes[$tabla]) {
                    throw new \RuntimeException("{$tabla}: la tienda tenía {$antes[$tabla]} filas y el DELETE borró {$borradas}. No se borró nada.");
                }
            }

            // La comprobación que no comparte la suposición con el borrado
            // (TEC-16): cada tabla ENTERA, contada sin ningún `where`, tiene que
            // haber bajado exactamente lo que la tienda tenía. Si bajó más, algo
            // borró filas de otra tienda (un `where` que no acotaba, una cascada
            // que nadie contó); si bajó menos, se quedó algo.
            $despues = self::totales();

            foreach ($antes as $tabla => $suyas) {
                if ($totales[$tabla] - $despues[$tabla] !== $suyas) {
                    throw new \RuntimeException(
                        "{$tabla}: la tienda tenía {$suyas} filas y la tabla bajó ".($totales[$tabla] - $despues[$tabla]).'. No se borró nada.'
                    );
                }
            }

            return $antes;
        });

        // Las fotos, solo si ninguna otra fila las usa (TEC-14), por tandas.
        foreach (array_chunk($fotos, self::FOTOS_POR_TANDA) as $tanda) {
            $imagenes->borrarSiNadieLasUsa($tanda);
        }

        foreach ($comprobantes as $ruta) {
            Comprobantes::borrar($ruta);
        }

        // Lo que queda ya no es borrar, y no puede dejar el borrado a medias:
        // si falla, se registra y sigue.
        try {
            // Las cuentas de cliente siguen: vuelven al directorio, sin pedidos.
            Directorio::reconstruir($id);

            // El catálogo público cachea listas de productos: que una tienda
            // reabierta no enseñe durante cinco minutos lo que ya no existe.
            Cache::increment("tenant:{$tenant->slug}:cache_version");
        } catch (\Throwable $e) {
            report($e);
        }

        return $cuentas;
    }

    /**
     * Cuántas filas tiene cada tabla, entera y sin ningún `where`.
     *
     * @return array<string, int>
     */
    private static function totales(): array
    {
        $totales = [];

        foreach (array_keys(self::TABLAS) as $tabla) {
            $totales[$tabla] = DB::table($tabla)->count();
        }

        return $totales;
    }

    /**
     * Las filas de una tabla que son de la tienda, con el `where` escrito.
     */
    private static function deLaTienda(string $tabla, string $tenantId): \Illuminate\Database\Query\Builder
    {
        [$columna, $padre] = self::TABLAS[$tabla];

        $consulta = DB::table($tabla);

        return $padre === null
            ? $consulta->where('tenant_id', $tenantId)
            : $consulta->whereIn($columna, DB::table($padre)->select('id')->where('tenant_id', $tenantId));
    }

    /**
     * Todas las fotos de los productos de la tienda —principal, galería y
     * variantes, también de lo que está en la papelera—, como se guardaron.
     *
     * @return array<int, string>
     */
    private static function fotosDe(string $tenantId): array
    {
        $productos = DB::table('products')->select('id')->where('tenant_id', $tenantId);

        return collect([
            DB::table('products')->where('tenant_id', $tenantId)->get(['image_url', 'thumbnail_url']),
            DB::table('product_images')->whereIn('product_id', $productos)->get(['image_url', 'thumbnail_url']),
            DB::table('product_variants')->where('tenant_id', $tenantId)->get(['image_url', 'thumbnail_url']),
        ])
            ->flatten(1)
            ->flatMap(fn ($fila) => [$fila->image_url, $fila->thumbnail_url])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function laMasTarde(CarbonInterface $a, CarbonInterface $b): Carbon
    {
        return Carbon::instance($a->gte($b) ? $a : $b);
    }
}
