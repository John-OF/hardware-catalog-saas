<?php

namespace Tests\Feature;

use App\Support\Salud;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Las tareas programadas avisan fuera al terminar (`INF-18`).
 *
 * Un cron caído no da errores, se calla: lo único que lo detecta es un servicio
 * externo que echa en falta el aviso. Estos casos van sobre las tareas **tal
 * como están programadas** en `routes/console.php`, no sobre un doble: se
 * disparan sus callbacks de fin igual que lo hace el scheduler —con el código
 * de salida del comando— y se mira qué URL se pidió.
 *
 * El primero es una guarda, como la de `BitacoraDeTiendaTest`: una tarea nueva
 * sin `Vigilancia::tarea()` lo hace fallar.
 */
class VigilanciaDeTareasTest extends TestCase
{
    public function test_cada_tarea_programada_avisa_a_su_url_y_ninguna_url_sobra(): void
    {
        $urls = [];

        foreach (array_keys(config('salud.vigilancia')) as $nombre) {
            $urls[$nombre] = "https://hc-ping.test/{$nombre}";
        }

        config(['salud.vigilancia' => $urls]);

        $avisadas = [];

        foreach ($this->tareasConComando() as $tarea) {
            Http::fake();

            $tarea->finish($this->app, 0);

            $pedidas = Http::recorded()->map(fn (array $par) => $par[0]->url())->all();

            $this->assertCount(1, $pedidas, "La tarea «{$tarea->command}» no avisa al terminar: envuélvela con Vigilancia::tarea() y dale su URL en config/salud.php.");
            $this->assertContains($pedidas[0], $urls);

            $avisadas[] = $pedidas[0];
        }

        $this->assertEqualsCanonicalizing(array_values($urls), $avisadas, 'Hay una URL en config/salud.php que ninguna tarea usa, o dos tareas que avisan a la misma.');
    }

    public function test_si_la_tarea_falla_avisa_a_fail(): void
    {
        config(['salud.vigilancia.copias' => 'https://hc-ping.test/copias']);
        Http::fake();

        $this->tarea('copias:crear')->finish($this->app, 1);

        Http::assertSent(fn (Request $peticion) => $peticion->url() === 'https://hc-ping.test/copias/fail');
        Http::assertSentCount(1);
    }

    public function test_sin_url_no_avisa_a_nadie(): void
    {
        config(['salud.vigilancia.copias' => null]);
        Http::fake();

        $this->tarea('copias:crear')->finish($this->app, 0);
        $this->tarea('copias:crear')->finish($this->app, 1);

        Http::assertNothingSent();
    }

    public function test_un_aviso_que_no_llega_no_tumba_la_tarea(): void
    {
        config(['salud.vigilancia.copias' => 'https://hc-ping.test/copias']);
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->tarea('copias:crear')->finish($this->app, 0);

        // Llegar aquí es la prueba: la excepción se anota y no sube.
        $this->assertTrue(true);
    }

    public function test_el_latido_del_scheduler_es_lo_que_ve_salud_comprobar(): void
    {
        $this->assertSame(Salud::FALLO, Salud::scheduler()[0]);

        $latido = collect(app(Schedule::class)->events())
            ->first(fn (Event $evento) => $evento instanceof CallbackEvent && $evento->description === 'salud:latido-del-scheduler');

        $this->assertNotNull($latido, 'Falta el latido del scheduler en routes/console.php.');
        $this->assertSame('* * * * *', $latido->expression);

        $latido->run($this->app);

        $this->assertSame(Salud::OK, Salud::scheduler()[0]);
    }

    /** @return list<Event> */
    private function tareasConComando(): array
    {
        $tareas = collect(app(Schedule::class)->events())
            ->reject(fn (Event $evento) => $evento instanceof CallbackEvent)
            ->values()
            ->all();

        $this->assertNotEmpty($tareas, 'No se cargó ninguna tarea de routes/console.php.');

        return $tareas;
    }

    private function tarea(string $comando): Event
    {
        $tarea = collect($this->tareasConComando())
            ->first(fn (Event $evento) => str_contains((string) $evento->command, $comando));

        $this->assertNotNull($tarea, "No está programada la tarea {$comando}.");

        return $tarea;
    }
}
