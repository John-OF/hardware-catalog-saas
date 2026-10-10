<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Verificación en dos pasos del operador de la plataforma (SEC-9).
 *
 * El operador ve todas las tiendas, les cambia el plan, las suspende y entra en
 * ellas como soporte. Hasta aquí lo protegían la contraseña y la lista de IPs
 * (`AUD-13`), y la lista solo protege mientras la IP del operador sea fija.
 * Ahora, con el segundo paso activo, además de la contraseña hace falta el
 * código de seis cifras de una app de autenticación (Google Authenticator,
 * Authy, 1Password…): TOTP, RFC 6238, un código nuevo cada 30 segundos.
 *
 * Todo lo que decide cómo funciona está aquí y no repartido por controladores:
 *
 * - **La semilla va cifrada** con la APP_KEY (cast `encrypted` de `User`). Si la
 *   clave cambia, la semilla deja de poder leerse y el operador se queda fuera:
 *   la salida es `php artisan platform:quitar-dos-pasos`.
 * - **Un código solo sirve una vez.** Se guarda el paso de tiempo del último
 *   aceptado, con un `UPDATE` condicionado que gana solo una de dos peticiones
 *   simultáneas con el mismo código.
 * - **Los códigos de recuperación** son ocho, de un solo uso, y se guardan como
 *   hash (además de cifrados): se enseñan una vez, al activar, y no se pueden
 *   volver a leer.
 * - **Entre la contraseña y el código** hay un desafío en caché: una clave al
 *   azar de cinco minutos que muere al quinto código equivocado. La contraseña
 *   sola no da ningún token.
 * - **Obligatoria solo en producción**, como la lista de IPs: en local se puede
 *   probar sin que estorbe.
 */
class DosPasos
{
    /** Cuántos códigos de recuperación se dan al activarla. */
    public const CODIGOS_DE_RECUPERACION = 8;

    /** Lo que dura el paso entre la contraseña y el código. */
    public const MINUTOS_DEL_DESAFIO = 5;

    /** Códigos equivocados que aguanta un desafío antes de pedir otra vez la contraseña. */
    public const INTENTOS_POR_DESAFIO = 5;

    /**
     * Pasos de 30 s de margen a cada lado: el reloj del móvil y el del servidor
     * nunca van exactos. Uno es lo que recomienda el RFC 6238.
     */
    private const VENTANA = 1;

    /** Sin 0/O ni 1/I/L: se confunden al copiarlos de un papel. */
    private const ALFABETO_DE_RECUPERACION = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function obligatoria(): bool
    {
        return app()->isProduction();
    }

    /**
     * Lo que el panel necesita saber de la cuenta, para `me` y para el login.
     *
     * @return array{dos_pasos: bool, obligatoria: bool, activada_el: ?string, codigos_restantes: ?int}
     */
    public static function estado(User $usuario): array
    {
        $activa = $usuario->tieneDosPasos();

        return [
            'dos_pasos'         => $activa,
            'obligatoria'       => self::obligatoria(),
            'activada_el'       => $activa ? $usuario->two_factor_confirmed_at?->toIso8601String() : null,
            'codigos_restantes' => $activa ? count($usuario->two_factor_recovery_codes ?? []) : null,
        ];
    }

    /**
     * Empieza (o reempieza) una activación: semilla nueva y todavía sin confirmar.
     * Hasta que se confirme con un código, el login no la pide.
     */
    public static function iniciar(User $usuario): string
    {
        $secreto = (new Google2FA)->generateSecretKey(32);

        $usuario->forceFill([
            'two_factor_secret'         => $secreto,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
            'two_factor_last_step'      => null,
        ])->save();

        return $secreto;
    }

    /** La deja como antes de activarla. Solo desde el servidor (ver la cabecera). */
    public static function quitar(User $usuario): void
    {
        $usuario->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
            'two_factor_last_step'      => null,
        ])->save();
    }

    /** El enlace `otpauth://` que leen las apps de autenticación: es lo que lleva el QR. */
    public static function enlace(User $usuario, string $secreto): string
    {
        return (new Google2FA)->getQRCodeUrl((string) config('app.name'), $usuario->email, $secreto);
    }

    /** El QR del enlace, en SVG: solo trazos, ningún texto ni script. */
    public static function qr(string $enlace): string
    {
        $escritor = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $escritor->writeString($enlace);
    }

    /**
     * Si el código de la app vale para este usuario; y si vale, lo gasta.
     *
     * Gastarlo es guardar el paso de tiempo en que se aceptó. Un código sigue
     * siendo válido mientras dura la ventana —hasta minuto y medio—, así que sin
     * esto quien lo viera en la pantalla podría usarlo después. El `UPDATE` va
     * condicionado a que el paso guardado sea anterior: de dos peticiones con el
     * mismo código a la vez, solo una lo consigue.
     *
     * Sirve igual para confirmar la activación (con la semilla recién creada) que
     * para entrar.
     */
    public static function gastarCodigo(User $usuario, string $codigo): bool
    {
        $codigo = preg_replace('/\s+/', '', $codigo) ?? '';

        if ($usuario->two_factor_secret === null || ! preg_match('/^\d{6}$/', $codigo)) {
            return false;
        }

        // `verifyKeyNewer()` devuelve el paso aceptado... salvo que el último sea
        // null, que entonces devuelve `true` a secas. Con 0 siempre da el número.
        $paso = (new Google2FA)->verifyKeyNewer(
            $usuario->two_factor_secret,
            $codigo,
            $usuario->two_factor_last_step ?? 0,
            self::VENTANA,
        );

        if ($paso === false) {
            return false;
        }

        $ganado = User::withoutTenant()
            ->whereKey($usuario->getKey())
            ->where(fn ($q) => $q->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $paso))
            ->update(['two_factor_last_step' => $paso]);

        if ($ganado === 0) {
            return false;
        }

        $usuario->two_factor_last_step = $paso;
        $usuario->syncOriginalAttribute('two_factor_last_step');

        return true;
    }

    /**
     * Ocho códigos nuevos, que sustituyen a los que hubiera.
     *
     * @return list<string> en claro, para enseñarlos UNA vez
     */
    public static function nuevosCodigosDeRecuperacion(User $usuario): array
    {
        $codigos = [];

        for ($i = 0; $i < self::CODIGOS_DE_RECUPERACION; $i++) {
            $codigos[] = self::alAzar(5).'-'.self::alAzar(5);
        }

        $usuario->forceFill([
            'two_factor_recovery_codes' => array_map(self::huella(...), $codigos),
        ])->save();

        return $codigos;
    }

    /**
     * Si es uno de sus códigos de recuperación; y si lo es, lo tacha.
     *
     * Con la fila bloqueada, para que el mismo código no entre dos veces a la
     * vez. Da igual cómo se escriba: mayúsculas o minúsculas, con guion o sin él.
     */
    public static function gastarCodigoDeRecuperacion(User $usuario, string $codigo): bool
    {
        $huella = self::huella($codigo);

        return DB::transaction(function () use ($usuario, $huella) {
            $fila = User::withoutTenant()->whereKey($usuario->getKey())->lockForUpdate()->first();
            $guardados = $fila?->two_factor_recovery_codes ?? [];

            foreach ($guardados as $i => $guardado) {
                if (hash_equals($guardado, $huella)) {
                    unset($guardados[$i]);
                    $fila->forceFill(['two_factor_recovery_codes' => array_values($guardados)])->save();
                    $usuario->setRawAttributes($fila->getAttributes(), true);

                    return true;
                }
            }

            return false;
        });
    }

    /**
     * El paso entre la contraseña y el código: una clave al azar que dice «este
     * usuario ya dio su contraseña», válida cinco minutos. En caché y guardada
     * por su hash, igual que un token.
     */
    public static function crearDesafio(User $usuario): string
    {
        $desafio = Str::random(64);
        $caduca = now()->addMinutes(self::MINUTOS_DEL_DESAFIO);

        Cache::put(self::claveDe($desafio), [
            'usuario' => $usuario->getKey(),
            'fallos'  => 0,
            'caduca'  => $caduca->getTimestamp(),
        ], $caduca);

        return $desafio;
    }

    public static function usuarioDelDesafio(string $desafio): ?User
    {
        $datos = Cache::get(self::claveDe($desafio));

        return $datos ? User::withoutTenant()->find($datos['usuario']) : null;
    }

    /**
     * Un código equivocado. Al quinto se borra el desafío y hay que volver a dar
     * la contraseña: el techo de intentos por minuto frena a quien prueba rápido,
     * y esto, a quien prueba despacio con la misma contraseña robada.
     *
     * @return bool si el desafío sigue vivo
     */
    public static function anotarFallo(string $desafio): bool
    {
        $clave = self::claveDe($desafio);
        $datos = Cache::get($clave);

        if (! $datos) {
            return false;
        }

        $datos['fallos']++;

        if ($datos['fallos'] >= self::INTENTOS_POR_DESAFIO) {
            Cache::forget($clave);

            return false;
        }

        // Sin alargarle la vida: caduca cuando iba a caducar.
        Cache::put($clave, $datos, Carbon::createFromTimestamp($datos['caduca']));

        return true;
    }

    public static function cerrarDesafio(string $desafio): void
    {
        Cache::forget(self::claveDe($desafio));
    }

    private static function claveDe(string $desafio): string
    {
        return 'dos-pasos:desafio:'.hash('sha256', $desafio);
    }

    private static function huella(string $codigo): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo) ?? ''));
    }

    private static function alAzar(int $largo): string
    {
        $texto = '';
        $ultimo = strlen(self::ALFABETO_DE_RECUPERACION) - 1;

        for ($i = 0; $i < $largo; $i++) {
            $texto .= self::ALFABETO_DE_RECUPERACION[random_int(0, $ultimo)];
        }

        return $texto;
    }
}
