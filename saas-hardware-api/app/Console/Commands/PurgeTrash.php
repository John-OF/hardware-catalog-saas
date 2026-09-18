<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\TrashController;
use App\Models\Order;
use App\Models\Product;
use App\Services\ImageService;
use Illuminate\Console\Command;

/**
 * Vacía de la papelera lo que ya caducó (`MOD-8`).
 *
 * Pensado para el scheduler (`routes/console.php`), a diario — necesita el cron
 * del servidor, `* * * * * php artisan schedule:run`, el mismo que ya hacía
 * falta desde `FUN-16` y que está documentado en `.env.example`. Sin él la
 * papelera no se vacía nunca: las filas se quedan, y sobre todo se quedan las
 * fotos ocupando disco, que es por lo que el dueño paga.
 *
 * **Corre sin tienda resuelta**, así que todo va con `withoutTenant()` a mano
 * (el global scope de `BelongsToTenant` falla en cerrado, `AUD-4`: sin esto no
 * borraría nada y no diría nada). Se recorre tienda por tienda y no de golpe
 * porque las fotos se borran con las reglas de `TEC-14` —sólo si nadie más las
 * usa— y eso se comprueba sobre la tabla entera de todas formas; lo que sí
 * importa es que un fallo en una tienda no se lleve por delante el resto.
 */
class PurgeTrash extends Command
{
    protected $signature = 'papelera:purgar {--dias= : Días de retención, por defecto los de TrashController}';

    protected $description = 'Borra definitivamente lo que lleve más de 30 días en la papelera, con sus fotos';

    public function __construct(private ImageService $imageService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dias = (int) ($this->option('dias') ?? TrashController::DIAS_DE_RETENCION);
        $corte = now()->subDays($dias);

        $productos = Product::withoutTenant()
            ->onlyTrashed()
            ->where('deleted_at', '<', $corte)
            ->with(['images', 'variants' => fn ($q) => $q->withoutTenant()])
            ->get();

        if ($productos->isNotEmpty()) {
            // Las URL se apuntan ANTES del DELETE y los archivos se borran
            // DESPUÉS, cuando la galería y las variantes ya cayeron por cascada:
            // si una copia del producto (duplicar) sigue usándolos, se quedan
            // (TEC-14).
            $fotos = $this->imageService->fotosDe($productos);

            Product::withoutTenant()->onlyTrashed()->whereIn('id', $productos->pluck('id'))->forceDelete();

            $this->imageService->borrarSiNadieLasUsa($fotos);
        }

        $pedidos = Order::withoutTenant()
            ->onlyTrashed()
            ->where('deleted_at', '<', $corte)
            ->count();

        if ($pedidos > 0) {
            Order::withoutTenant()->onlyTrashed()->where('deleted_at', '<', $corte)->forceDelete();
        }

        // Sin bitácora: la de la tienda (`INF-3`) es "quién cambió qué", y aquí
        // no hay quién. Lo que el dueño ve es que a los 30 días deja de estar,
        // que es lo que dice la propia pantalla de la papelera.
        $this->info("Purgados de la papelera: {$productos->count()} productos y {$pedidos} pedidos (más de {$dias} días).");

        return self::SUCCESS;
    }
}
