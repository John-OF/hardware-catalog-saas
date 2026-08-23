<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // AUD-2: el catalogo publico no tenia ningun limite. La auditoria lo
        // comprobo a mano: 70 peticiones seguidas, 70 respuestas 200, ningun 429.
        //
        // 120/min y no los 60 que proponia la auditoria, porque el limite es por
        // IP y hay dos cosas que empujan hacia arriba: una sola carga del
        // catalogo son ~6 peticiones (tienda, categorias, productos, facetas,
        // paginas) y detras de una misma IP puede haber varias personas a la vez
        // (el NAT de una oficina, el CGNAT de una operadora movil). Con 60
        // bastarian dos o tres compradores de la misma red navegando en paralelo
        // para empezar a comer 429, que es peor que no tener limite: rompe a
        // quien compra y no molesta a quien ataca.
        //
        // A 120 el atacante queda en 2 req/s, que contra respuestas cacheadas no
        // hace dano, y el dano de verdad —el UPDATE por peticion— se cierra
        // aparte en ViewCounter. El limite esta para poner un techo, no para
        // medir con precision.
        RateLimiter::for('catalogo_publico', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        RateLimiter::for('anonymous_reviews', function (Request $request) {
            if ($request->user('sanctum')) {
                return Limit::none();
            }
            // Límite de 60 por hora en desarrollo para facilitar pruebas cómodas
            return Limit::perHour(60)->by($request->ip());
        });
    }
}
