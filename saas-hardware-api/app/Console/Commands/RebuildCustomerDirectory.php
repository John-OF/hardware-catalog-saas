<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Directorio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Enlaza al directorio de clientes lo que se quedó suelto (MOD-24).
 *
 * Cada pedido y cada cuenta de cliente se apuntan solos al crearse; si eso
 * falla, el pedido entra igual y queda en el log («No se pudo apuntar el pedido
 * en el directorio de clientes»), con `orders.customer_id` vacío. Esto lo
 * repara: busca o crea a la persona de cada cuenta y de cada pedido sin persona,
 * con las mismas reglas (`Directorio::reconstruir()`), que es también lo que
 * llenó el directorio al migrar.
 *
 * **Solo añade y enlaza: no borra ni junta a nadie que ya esté.** Repetirlo no
 * duplica, así que se puede lanzar sin miedo. No va en el scheduler: hace falta
 * solo si el log dice que algo falló.
 */
class RebuildCustomerDirectory extends Command
{
    protected $signature = 'clientes:reconstruir {slug? : La tienda; sin ella, todas}';

    protected $description = 'Enlaza al directorio de clientes los pedidos y las cuentas que se quedaron sin persona';

    public function handle(): int
    {
        $tiendas = Tenant::query()
            ->when($this->argument('slug'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get(['id', 'slug']);

        if ($tiendas->isEmpty()) {
            $this->error('No hay ninguna tienda con ese slug.');

            return self::FAILURE;
        }

        foreach ($tiendas as $tienda) {
            $sueltos = DB::table('orders')->where('tenant_id', $tienda->id)->whereNull('customer_id')->count();

            Directorio::reconstruir($tienda->id);

            $quedan = DB::table('orders')->where('tenant_id', $tienda->id)->whereNull('customer_id')->count();

            // Los que quedan son pedidos sin teléfono, correo ni cuenta: no hay
            // a quién apuntarlos, y es lo esperado.
            $this->line("{$tienda->slug}: {$sueltos} pedidos sin persona antes, {$quedan} después (sin teléfono, correo ni cuenta).");
        }

        return self::SUCCESS;
    }
}
