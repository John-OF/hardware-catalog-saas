<?php

use App\Support\Salud;
use App\Support\Vigilancia;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Todo lo de este archivo necesita el cron del servidor corriendo
// `artisan schedule:run` cada minuto -documentado en `.env.example`, mismo tipo
// de dependencia de despliegue que el worker de colas (AUD-11)-: sin eso, nada
// de esto se dispara nunca, aunque el código esté bien.
//
// INF-18: cada tarea avisa al terminar a la URL que tenga en
// `config/salud.php` (`Vigilancia::tarea`), y es la falta de aviso lo que dispara
// la alarma fuera. Una tarea nueva va envuelta igual, o `VigilanciaDeTareasTest`
// falla.

// INF-19: el latido del propio scheduler, el primero para que no espere detrás
// de una tarea larga. Es lo que deja a `salud:comprobar` saber que el cron está
// vivo: un cron parado no da errores, se calla.
Schedule::call(fn () => Salud::anotarPasoDelScheduler())
    ->everyMinute()
    ->name('salud:latido-del-scheduler');

// FUN-16: cierra las tiendas cuya prueba venció sin que se eligiera un plan.
Vigilancia::tarea(Schedule::command('trials:cerrar-vencidas')->daily(), 'pruebas');

// MOD-8: borra de verdad lo que lleve más de 30 días en la papelera, con sus
// fotos. Sin esto la papelera no se vacía sola y las imágenes de lo borrado se
// quedan ocupando disco para siempre.
Vigilancia::tarea(Schedule::command('papelera:purgar')->daily(), 'papelera');

// INF-20: la actividad del panel de tienda caduca al año. Sin esto la tabla
// crece con cada import, que guarda el detalle de hasta 100 productos.
Vigilancia::tarea(Schedule::command('bitacora:purgar')->daily(), 'bitacora');

// INF-7: la copia de seguridad de la base, a diario. Se programa de madrugada
// porque `mysqldump` con `--single-transaction` no bloquea la tienda, pero sí
// compite por disco y red con quien esté comprando.
//
// `withoutOverlapping()` porque una copia que tarde más de un día no puede
// arrancar la siguiente encima: dos `mysqldump` a la vez es la forma de tumbar
// justo la base que se quería proteger.
Vigilancia::tarea(Schedule::command('copias:crear')->dailyAt('03:30')->withoutOverlapping(), 'copias');

// INF-19: el repaso de todo lo que falla en silencio, cada hora. En segundo
// plano porque puede esperar hasta 90 s a que un worker atienda su trabajo de
// prueba, y en primer plano retrasaría al resto -incluido el worker, si se lanza
// desde aquí mismo con `queue:work --stop-when-empty` (INF-16)-.
Vigilancia::tarea(
    Schedule::command('salud:comprobar')->hourly()->runInBackground()->withoutOverlapping(),
    'salud',
);
