<?php

namespace Tests\Feature;

use App\Support\Observabilidad;
use Sentry\Event;
use Tests\TestCase;

/**
 * TEC-5: lo que sale hacia Sentry. Lo que importa de verdad es lo que NO sale:
 * una regresión aquí no rompe nada visible, solo empieza a mandar datos
 * personales a un tercero.
 */
class ObservabilidadTest extends TestCase
{
    public function test_sin_dsn_no_se_envia_nada(): void
    {
        $this->assertEmpty(config('sentry.dsn'));
    }

    public function test_la_configuracion_no_manda_datos_personales(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertSame('none', config('sentry.max_request_body_size'));
        $this->assertNull(config('sentry.traces_sample_rate'));

        foreach (['logs', 'sql_queries', 'http_client_requests', 'notifications', 'cache'] as $miga) {
            $this->assertFalse(config("sentry.breadcrumbs.$miga"), "La miga de pan '$miga' puede llevar datos personales.");
        }
    }

    public function test_depurar_quita_usuario_cookies_cabeceras_cuerpo_y_query(): void
    {
        $evento = Event::createEvent();
        $evento->setUser(\Sentry\UserDataBag::createFromUserIdentifier('42'));
        $evento->setRequest([
            'method' => 'POST',
            'url' => 'https://tienda.test/api/auth/reset-password?token=SECRETO&email=ana@ejemplo.com#x',
            'query_string' => 'token=SECRETO&email=ana@ejemplo.com',
            'cookies' => ['sesion' => 'abc'],
            'headers' => ['Authorization' => 'Bearer 1|zzz'],
            'data' => ['password' => 'clave'],
        ]);

        $resultado = Observabilidad::depurar($evento);

        $this->assertNull($resultado->getUser());
        $this->assertSame(
            ['method' => 'POST', 'url' => 'https://tienda.test/api/auth/reset-password'],
            $resultado->getRequest(),
        );
    }

    public function test_la_configuracion_se_puede_cachear(): void
    {
        // `config:cache` falla con un closure; el callback va como [clase, método].
        $this->assertSame([Observabilidad::class, 'depurar'], config('sentry.before_send'));
        $this->assertTrue(is_callable(config('sentry.before_send')));
    }
}
