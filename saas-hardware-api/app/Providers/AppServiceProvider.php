<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->guardarDeAppDebug();
        $this->guardarDeAlmacenamiento();
        $this->politicaDeContrasenias();

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

        $this->limitesPorFormulario();
    }

    /**
     * TEC-13: cada formulario limitado con su propio contador.
     *
     * Antes estas rutas llevaban `throttle:5,1` / `throttle:10,1` a secas, y un
     * throttle sin nombre usa como clave `sha1(dominio|IP)` -sin la ruta y sin el
     * tope- y suma la peticion ANTES de ejecutarla, salga bien o mal. Resultado:
     * login del panel, de plataforma y de clientes, registro, recuperacion de
     * contrasenia, pedidos, resenias y avisos de stock llenaban un unico contador
     * por IP. Cinco peticiones cualesquiera entre todos -un comprador en el wifi
     * de la tienda fallando su contrasenia, o haciendo pedidos- dejaban al dueño
     * sin poder entrar al panel durante un minuto.
     *
     * Ahora la clave lleva siempre la ruta (su plantilla, mas el slug de la tienda
     * si lo hay: el id de un producto no, o bastaria cambiar de producto para
     * tener otro cupo) y cada limitador tiene su nombre, que Laravel tambien mete
     * en la clave. Los topes son los de antes; lo que cambia es a quien se le
     * cuentan.
     */
    private function limitesPorFormulario(): void
    {
        $ruta = static fn (Request $request): string => ($request->route()?->uri() ?? $request->path())
            .'|'.(string) $request->route('slug');

        // Login (panel, plataforma y clientes): por CORREO + IP, como hace
        // Fortify. Asi quien falla con su correo solo se bloquea a si mismo, y el
        // dueño entra con el suyo desde la misma red. Ese limite solo, sin mas,
        // dejaria probar 5 contrasenias por minuto contra CADA correo de una
        // lista desde una sola IP; el segundo limite pone techo a eso, alto
        // para que varias personas reales detras de un NAT no lleguen a el.
        RateLimiter::for('login', function (Request $request) use ($ruta) {
            $correo = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('correo|'.$ruta($request).'|'.$correo.'|'.$request->ip()),
                Limit::perMinute(20)->by('ip|'.$ruta($request).'|'.$request->ip()),
            ];
        });

        // Registro y recuperacion de contrasenia: por IP y ruta. No por correo:
        // lo que se limita aqui es crear cuentas o mandar correos, y eso se
        // repetiria cambiando de direccion.
        RateLimiter::for('auth_publica', function (Request $request) use ($ruta) {
            return Limit::perMinute(5)->by($ruta($request).'|'.$request->ip());
        });

        // Pedidos, resenias y "avisame" del catalogo: el 10/min de siempre, cada
        // ruta con el suyo.
        RateLimiter::for('escritura_publica', function (Request $request) use ($ruta) {
            return Limit::perMinute(10)->by($ruta($request).'|'.$request->ip());
        });

        // El enlace de verificacion del correo (FUN-5).
        RateLimiter::for('verificacion_correo', function (Request $request) use ($ruta) {
            return Limit::perMinute(6)->by($ruta($request).'|'.$request->ip());
        });

        // Reenviar un correo desde el panel (verificacion, invitacion). Con sesion:
        // por usuario, como ya hacia el throttle sin nombre, pero cada ruta aparte.
        RateLimiter::for('reenvio_correo', function (Request $request) use ($ruta) {
            return Limit::perMinute(3)->by($ruta($request).'|'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }

    /**
     * AUD-12: en produccion, APP_DEBUG=true no arranca.
     *
     * Habia un comentario en `.env.example` avisando de ponerlo en false, pero
     * el valor que se copia es el peligroso y nada comprobaba el resultado. Con
     * debug activo, cualquier 500 devuelve el stack trace, la consulta SQL y las
     * variables de entorno a quien haya provocado el error.
     *
     * Falla en el arranque y no en la primera peticion que reviente, para que el
     * despiste se note al desplegar -tambien al correr `artisan`- y no meses
     * despues, cuando ya lo ha visto alguien de fuera.
     */
    private function guardarDeAppDebug(): void
    {
        if ($this->app->isProduction() && config('app.debug')) {
            throw new \RuntimeException(
                'APP_DEBUG=true con APP_ENV=production: cualquier error 500 devolveria el stack trace, '
                .'la consulta SQL y variables de entorno al cliente. Pon APP_DEBUG=false en el .env del '
                .'servidor y vuelve a cachear la configuracion (php artisan config:cache).'
            );
        }
    }

    /**
     * TEC-10: en produccion, guardar las imagenes en el disco local no arranca.
     *
     * `ImageService` elige disco con `default === 'r2' ? 'r2' : 'public'`, asi
     * que cualquier otro valor cae al disco local **en silencio**. En un hosting
     * con filesystem efimero -contenedores, la mayoria de PaaS- las fotos que
     * suban las tiendas desaparecen en el siguiente despliegue, y con mas de una
     * instancia detras de un balanceador ni siquiera se ven entre ellas. Un
     * catalogo sin fotos no es una degradacion: es la tienda rota, y rota de una
     * forma que el dueño descubre semanas despues sin poder recuperar nada.
     *
     * Mismo criterio que AUD-12 y AUD-13: lo que depende de que el operador se
     * acuerde lo comprueba el sistema. Y como en AUD-13 con `PLATFORM_ALLOWED_IPS='*'`,
     * hay salida explicita para quien de verdad tenga disco persistente
     * (`ALLOW_LOCAL_STORAGE=true`): asi no estorba a ese caso y queda escrito
     * que fue una decision y no un descuido.
     */
    private function guardarDeAlmacenamiento(): void
    {
        if (! $this->app->isProduction() || config('filesystems.allow_local')) {
            return;
        }

        if (config('filesystems.default') !== 'r2') {
            throw new \RuntimeException(
                'FILESYSTEM_DISK='.config('filesystems.default').' con APP_ENV=production: las imagenes '
                .'que suban las tiendas se guardarian en el disco local del servidor y se perderian en el '
                .'siguiente despliegue si el filesystem es efimero. Configura R2/S3 (FILESYSTEM_DISK=r2 '
                .'mas las R2_*) o, si este servidor tiene disco persistente, ponlo por escrito con '
                .'ALLOW_LOCAL_STORAGE=true.'
            );
        }
    }

    /**
     * AUD-17: politica de contrasenias unica para todo el proyecto.
     *
     * Antes cada endpoint pedia `min:8` por su cuenta, asi que subir el liston
     * era acordarse de tres sitios. Ahora los tres piden `Password::defaults()`
     * y la politica se decide aqui.
     *
     * `uncompromised()` contrasta la contrasenia contra la lista de Have I Been
     * Pwned -por k-anonimato: viaja un prefijo de 5 caracteres del hash, nunca
     * la contrasenia-, que es lo que de verdad frena el relleno de credenciales.
     * Se puede apagar con PASSWORD_UNCOMPROMISED=false, y la suite lo apaga: son
     * llamadas HTTP a internet, lentas en local e imposibles en un CI sin salida.
     * El test que cubre el camino la vuelve a encender con un verificador falso.
     */
    private function politicaDeContrasenias(): void
    {
        Password::defaults(function () {
            $regla = Password::min(8);

            return config('auth.password_uncompromised')
                ? $regla->uncompromised()
                : $regla;
        });
    }
}
