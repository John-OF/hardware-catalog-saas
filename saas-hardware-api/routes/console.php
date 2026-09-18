<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FUN-16: cierra las tiendas cuya prueba venció sin que se eligiera un plan.
// Necesita el cron del servidor corriendo `artisan schedule:run` cada minuto
// -documentado en `.env.example`, mismo tipo de dependencia de despliegue que
// el worker de colas (AUD-11)-: sin eso, esto no se dispara nunca, aunque el
// código esté bien.
Schedule::command('trials:cerrar-vencidas')->daily();

// MOD-8: borra de verdad lo que lleve más de 30 días en la papelera, con sus
// fotos. Mismo cron que el de arriba: sin él la papelera no se vacía sola y las
// imágenes de lo borrado se quedan ocupando disco para siempre.
Schedule::command('papelera:purgar')->daily();

// INF-7: la copia de seguridad de la base, a diario. Mismo cron que los dos de
// arriba. Se programa de madrugada porque `mysqldump` con `--single-transaction`
// no bloquea la tienda, pero sí compite por disco y red con quien esté comprando.
//
// `withoutOverlapping()` porque una copia que tarde más de un día no puede
// arrancar la siguiente encima: dos `mysqldump` a la vez es la forma de tumbar
// justo la base que se quería proteger.
Schedule::command('copias:crear')->dailyAt('03:30')->withoutOverlapping();
