<?php

namespace App\Console\Commands;

use App\Support\Comprobantes;
use Illuminate\Console\Command;

/**
 * Borra los comprobantes de pago que ya no hacen falta (MOD-26).
 *
 * Los que se revisaron hace más de `Comprobantes::DIAS_TRAS_REVISAR` días, los
 * que llevan más de `DIAS_MAXIMOS` sin que nadie los mirara y los que se
 * quedaron sin pedido. El pedido se queda con su estado de pago: solo se va el
 * archivo. A diario desde el scheduler, con su aviso de vigilancia (`INF-18`).
 */
class PurgePaymentProofs extends Command
{
    protected $signature = 'comprobantes:purgar';

    protected $description = 'Borra los comprobantes de pago caducados y los que se quedaron sin pedido';

    public function handle(): int
    {
        $borrados = Comprobantes::purgar();

        $this->info($borrados === 1 ? 'Se borró 1 comprobante.' : "Se borraron {$borrados} comprobantes.");

        return self::SUCCESS;
    }
}
