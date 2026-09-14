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
