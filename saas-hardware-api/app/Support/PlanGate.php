<?php

namespace App\Support;

use App\Exceptions\PlanLimitException;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;

/**
 * Aplica los limites del plan de la tienda actual (SAAS-3 — paso 7.7a).
 *
 * La matriz vive en config/plans.php; aqui solo esta la logica de leerla y de
 * negarse. Se llama desde los controladores en vez de desde un middleware
 * porque no todos los limites caben en una ruta: el import CSV necesita pedir
 * hueco para N filas de golpe, no para una.
 *
 * Todos los `ensure*` estan pensados para llamarse AL PRINCIPIO del metodo,
 * antes de escribir nada. Si lanzan, la peticion no ha tocado la base.
 */
class PlanGate
{
    /**
     * Recursos que se cuentan por tienda.
     *
     * El conteo no filtra por tenant a mano: el global scope de BelongsToTenant
     * ya lo hace, y desde AUD-4 falla en cerrado, asi que sin tienda resuelta
     * estos count() devuelven 0 en vez de contar los de todo el mundo.
     */
    private const CONTADORES = [
        'products'   => Product::class,
        'categories' => Category::class,
        'pages'      => Page::class,
    ];

    /** Como se llama cada limite en el mensaje que lee el dueño de la tienda. */
    private const NOMBRES = [
        'products'           => 'productos',
        'categories'         => 'categorías',
        'pages'              => 'páginas informativas',
        'users'              => 'usuarios del panel',
        'images_per_product' => 'imágenes por producto',
        'custom_domain'      => 'El dominio propio',
        'csv_import'         => 'La importación por CSV',
    ];

    /**
     * Clave del plan de la tienda actual, ya resuelta contra la matriz.
     *
     * Un plan que no existe en config/plans.php cae al plan por defecto, no a
     * "sin limites": ver el porqué en la cabecera de esa config.
     */
    public static function plan(): string
    {
        return self::planDe(app()->bound('currentTenant')
            ? (string) app('currentTenant')->plan
            : null);
    }

    /**
     * Lo mismo pero para una tienda que NO es la actual.
     *
     * Lo necesita el panel de plataforma (INF-2), que pinta la ficha de una
     * tienda sin hacerla current: alli no hay `currentTenant` y las tres de
     * arriba devolverian el plan por defecto de todo el mundo. Se resuelve aqui
     * y no con un `config()` suelto en el controlador para que la regla del
     * plan desconocido —cae al plan por defecto, nunca a "sin limites"— siga
     * escrita en un unico sitio.
     */
    public static function planDe(?string $plan): string
    {
        return ($plan !== null && $plan !== '' && config("plans.plans.{$plan}"))
            ? $plan
            : (string) config('plans.default');
    }

    /** Nombre comercial del plan, para los mensajes y para el panel. */
    public static function label(): string
    {
        return self::labelDe(self::plan());
    }

    public static function labelDe(?string $plan): string
    {
        $clave = self::planDe($plan);

        return (string) config("plans.plans.{$clave}.label", $clave);
    }

    /** @return array<string, int|bool|null> */
    public static function limits(): array
    {
        return self::limitsDe(self::plan());
    }

    /** @return array<string, int|bool|null> */
    public static function limitsDe(?string $plan): array
    {
        return (array) config('plans.plans.'.self::planDe($plan).'.limits', []);
    }

    public static function limit(string $clave): int|bool|null
    {
        return self::limits()[$clave] ?? null;
    }

    /** Funciones que se activan o desactivan enteras (no se cuentan). */
    public static function allows(string $funcion): bool
    {
        return self::limit($funcion) === true;
    }

    /**
     * Cuantos se pueden crear todavia. `null` = sin tope.
     *
     * Nunca devuelve negativo: una tienda que bajo de plan puede estar por
     * encima del tope, y ahi el hueco es cero, no "-30".
     */
    public static function hueco(string $recurso): ?int
    {
        $tope = self::limit($recurso);

        if (! is_int($tope)) {
            return null;
        }

        return max(0, $tope - self::actual($recurso));
    }

    /** Cuenta de cada recurso limitado, para pintar "12 / 20" en el panel. */
    public static function usage(): array
    {
        $uso = [];

        foreach (array_keys(self::CONTADORES) as $recurso) {
            $uso[$recurso] = self::actual($recurso);
        }

        // `users` no esta en CONTADORES -se cuenta aparte, ver actual()- pero el
        // panel tiene que poder pintar "1 / 3" igual que con los demas.
        $uso['users'] = self::actual('users');

        return $uso;
    }

    /**
     * Corta si crear `$cuantos` de `$recurso` pasaria del tope del plan.
     *
     * @throws PlanLimitException
     */
    public static function ensureCanCreate(string $recurso, int $cuantos = 1): void
    {
        $tope = self::limit($recurso);

        if (! is_int($tope)) {
            return;
        }

        self::comprobar($recurso, $tope, self::actual($recurso), $cuantos);
    }

    /**
     * Igual que ensureCanCreate pero con el conteo a cargo de quien llama.
     *
     * Lo usa `images_per_product`, que se cuenta dentro de un producto y no por
     * tienda, asi que PlanGate no puede saber cual es el actual.
     *
     * @throws PlanLimitException
     */
    public static function ensureCanAdd(string $limite, int $actual, int $cuantos = 1): void
    {
        $tope = self::limit($limite);

        if (! is_int($tope)) {
            return;
        }

        self::comprobar($limite, $tope, $actual, $cuantos);
    }

    /**
     * Corta si la funcion no esta incluida en el plan.
     *
     * @throws PlanLimitException
     */
    public static function ensureAllows(string $funcion): void
    {
        if (self::allows($funcion)) {
            return;
        }

        $nombre = self::NOMBRES[$funcion] ?? $funcion;

        throw new PlanLimitException(
            self::plan(),
            $funcion,
            false,
            null,
            "{$nombre} no está incluido en tu plan ".self::label().'. Mejora de plan para usarlo.',
        );
    }

    private static function comprobar(string $limite, int $tope, int $actual, int $cuantos): void
    {
        if ($actual + $cuantos <= $tope) {
            return;
        }

        $nombre = self::NOMBRES[$limite] ?? $limite;
        $plan   = self::label();

        // Dos mensajes porque el caso de uno y el de varios se leen muy
        // distinto: "ya tienes 20 de 20" explica el bloqueo al crear a mano,
        // pero no explica nada si el import trae 300 filas de golpe.
        $mensaje = $cuantos === 1
            ? "Tu plan {$plan} permite {$tope} {$nombre} y ya tienes {$actual}. Mejora de plan para añadir más."
            : "Tu plan {$plan} permite {$tope} {$nombre}, ya tienes {$actual} y estás intentando añadir {$cuantos}.";

        throw new PlanLimitException(self::plan(), $limite, $tope, $actual, $mensaje);
    }

    private static function actual(string $recurso): int
    {
        // `users` no se puede contar como los demas, por dos motivos (FUN-4):
        //
        // 1. `User` es la EXCEPCION al fallo en cerrado de AUD-4 -su global
        //    scope esta desactivado a proposito, el porque esta en el modelo-,
        //    asi que un `User::count()` contaria a los usuarios de TODAS las
        //    tiendas. Justo el tipo de suposicion tacita que AUD-4 saco a la luz.
        // 2. Solo cuentan los del PANEL. Los clientes del catalogo viven en esta
        //    misma tabla con rol `customer`, y una tienda con 500 compradores
        //    registrados se quedaria sin poder invitar a su vendedor.
        if ($recurso === 'users') {
            if (! app()->bound('currentTenant')) {
                return 0;
            }

            return User::where('tenant_id', app('currentTenant')->id)
                ->whereIn('role', User::ROLES_DE_PANEL)
                ->count();
        }

        $modelo = self::CONTADORES[$recurso] ?? null;

        return $modelo ? $modelo::count() : 0;
    }
}
