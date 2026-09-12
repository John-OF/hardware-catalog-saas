<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\DomainVerifier;
use App\Services\ImageService;
use App\Support\PlanGate;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    /**
     * Cuánto se le da a alguien para verificar un dominio que acaba de pedir
     * antes de que otra tienda pueda quitárselo (FUN-6).
     *
     * Sin esto, escribir el dominio de otro lo dejaba ocupado para siempre —
     * `unique` no distingue entre "lo tengo y lo demostré" y "lo escribí una
     * vez y no volví"—, y el dueño legítimo se comía un 422 sin saber por qué
     * ni cuándo se liberaría. Un día es tiempo de sobra para poner un TXT (la
     * propagación de DNS rara vez pasa de un par de horas) y poco tiempo para
     * que alguien lo use de verdad como bloqueo.
     */
    private const LIBERAR_DOMINIO_TRAS_HORAS = 24;

    public function __construct(private ImageService $imageService) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(app('currentTenant'));
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'name'            => 'sometimes|string|max:200',
            'whatsapp_number' => 'sometimes|string|max:20',
            'primary_color'   => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'logo_url'        => 'sometimes|nullable|url|max:500',
            'logo'            => 'nullable|image|mimes:jpeg,png,webp|max:2048',

            // Cerrojo de FUN-6: un closure y no `unique:tenants,custom_domain`
            // a secas, porque "ocupado" ya no es una pregunta binaria. Un
            // dominio de otra tienda que nunca se verificó y lleva más de
            // `LIBERAR_DOMINIO_TRAS_HORAS` sin moverse se considera abandonado
            // y se deja pedir: si no, escribir el dominio de otro por error (o
            // a propósito) lo dejaba ocupado para siempre, aunque quien lo
            // escribió no fuera a demostrar nunca que es suyo.
            'custom_domain' => [
                'sometimes', 'nullable', 'string', 'max:100',
                function (string $atributo, $valor, \Closure $fallar) use ($tenant) {
                    if (blank($valor) || $valor === $tenant->custom_domain) {
                        return;
                    }

                    $otra = Tenant::where('custom_domain', $valor)
                        ->where('id', '!=', $tenant->id)
                        ->first();

                    if (! $otra) {
                        return;
                    }

                    $abandonado = ! $otra->custom_domain_verified_at
                        && $otra->custom_domain_requested_at
                        && $otra->custom_domain_requested_at->lt(now()->subHours(self::LIBERAR_DOMINIO_TRAS_HORAS));

                    if (! $abandonado) {
                        $fallar('Ese dominio ya lo está usando otra tienda.');
                    }
                },
            ],

            // Moneda de la tienda (OWN-1). La whitelist sale de config/currencies.php,
            // que es la misma lista que ofrece el selector de Configuración.
            'currency'        => ['sometimes', 'string', Rule::in(array_keys(config('currencies')))],

            // Archivos subidos opcionales (alternativa a pegar la URL)
            'banner'          => 'nullable|image|mimes:jpeg,png,webp|max:5120',
            // Sin SVG (TEC-7): un .svg puede llevar <script> dentro y se sirve
            // desde el mismo origen que la tienda, así que aceptarlo es aceptar
            // que un dueño suba JS ejecutable. Los formatos de favicon de verdad
            // son png e ico.
            'favicon'         => 'nullable|file|mimes:png,ico|max:512',

            // Personalización visual de la tienda (theme JSON)
            'theme'               => 'sometimes|nullable|array',
            'theme.hero_title'    => 'nullable|string|max:120',
            'theme.hero_subtitle' => 'nullable|string|max:240',
            'theme.banner_url'    => 'nullable|url|max:500',

            // Estilo de portada (PERS-5). Cada valor es una disposicion distinta
            // del mismo contenido; las medidas estan en el frontend
            // (CatalogPage + src/utils/hero.ts). Ojo: no todos los estilos usan
            // `banner_url` igual, y `minimal` no lo pinta — pero se guarda
            // siempre, para que cambiar de estilo no borre la imagen.
            'theme.hero_style'    => 'nullable|in:classic,centered,split,minimal',

            'theme.accent_color'  => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'theme.color_mode'    => 'nullable|in:dark,light',

            // Tono neutral de la tienda (PERS-2). La paleta vive en el frontend
            // (index.css + src/utils/neutrals.ts); aquí solo se valida la clave
            // para no guardar en el JSON un valor que ninguna hoja de estilos
            // sepa pintar. Al añadir un tono hay que ampliar esta lista también.
            'theme.neutral'       => 'nullable|in:slate,zinc,stone,navy,plum',

            // Forma de la tienda (PERS-4). Igual que el tono: las medidas viven
            // en el frontend (index.css + src/utils/shape.ts) y aquí solo se
            // valida la clave.
            'theme.radius'        => 'nullable|in:sharp,soft,round',
            'theme.card_style'    => 'nullable|in:glass,solid,flat',
            'theme.density'       => 'nullable|in:compact,normal,comfortable',

            'theme.layout'        => 'nullable|in:grid,compact,list',

            // Tipografía (PERS-6). `font` era una pareja cerrada: un solo valor
            // decidía la letra de los títulos y la del texto. Desde 10.3 son dos
            // familias sueltas y el panel ya no manda `font`, pero la clave se
            // sigue aceptando y guardando: las tiendas dadas de alta antes solo
            // tienen esa, y es de donde el frontend deduce sus dos familias
            // mientras su dueño no entre a Configuración (ver resolveFonts en
            // src/utils/fonts.ts). Si se quitara de aquí, la primera vez que
            // esas tiendas guardasen cualquier otra cosa perderían su letra.
            'theme.font'          => 'nullable|in:sans,serif,mono,heading',

            // El catálogo de familias vive en src/utils/fonts.ts, que es también
            // de donde salen los dos selectores. Al añadir una familia hay que
            // ampliar estas dos listas o el dueño se come un 422 al guardar.
            'theme.font_heading'  => 'nullable|in:inter,outfit,space-grotesk,montserrat,playfair,lora,merriweather,fira-code',
            'theme.font_body'     => 'nullable|in:inter,outfit,space-grotesk,montserrat,playfair,lora,merriweather,fira-code',

            'theme.sections'      => 'nullable|string',

            // Branding de la pestaña del navegador (título y favicon)
            'theme.page_title'    => 'nullable|string|max:60',

            // Elementos de marca (PERS-7). La franja se muestra si hay texto:
            // no hay un booleano de encendido, así que vaciar `announcement` es
            // lo que la apaga (ver src/utils/branding.ts).
            'theme.announcement'       => 'nullable|string|max:120',
            'theme.announcement_style' => 'nullable|in:primary,accent,neutral',

            'theme.footer_address' => 'nullable|string|max:160',
            'theme.footer_hours'   => 'nullable|string|max:120',
            'theme.footer_tax_id'  => 'nullable|string|max:40',

            // `url:http,https` y no `url` a secas: estos tres son los únicos
            // campos del theme que acaban en un href, y de un enlace a una red
            // social no hay ningún esquema más que tenga sentido. La regla
            // genérica ya rechaza javascript: y data: por su cuenta (está
            // comprobado en TenantBrandingTest), pero deja pasar cosas como
            // ftp://, y no conviene que la seguridad de un href dependa de los
            // detalles internos de una regla de propósito general.
            'theme.footer_facebook'  => 'nullable|url:http,https|max:200',
            'theme.footer_instagram' => 'nullable|url:http,https|max:200',
            'theme.footer_tiktok'    => 'nullable|url:http,https|max:200',
        ]);

        // Dominio propio: funcion de plan (SAAS-3). Se comprueba despues de
        // validar, para que un dominio mal escrito siga dando el 422 de formato
        // de siempre y no uno de plan.
        //
        // Solo se mira cuando el valor CAMBIA a algo no vacio. Vaciarlo tiene que
        // poder hacerse siempre: si no, una tienda que baja de plan se quedaria
        // con el dominio puesto y sin forma de quitarlo desde el panel.
        if (array_key_exists('custom_domain', $data)
            && filled($data['custom_domain'])
            && $data['custom_domain'] !== $tenant->custom_domain) {
            PlanGate::ensureAllows('custom_domain');
        }

        if (isset($data['theme'])) {
            $data['theme'] = array_merge($tenant->theme ?? [], $data['theme']);
        }

        if ($request->hasFile('logo')) {
            $urls = $this->imageService->uploadProductImage($request->file('logo'), $tenant->slug . '/logo');
            $data['logo_url'] = $urls['image_url'];
        }

        // El banner se procesa como imagen normal (WebP); el favicon se guarda
        // tal cual. Ambos viven dentro del JSON theme, así que partimos del
        // theme enviado (o el actual) y le inyectamos la URL resultante.
        if ($request->hasFile('banner')) {
            $urls = $this->imageService->uploadProductImage($request->file('banner'), $tenant->slug . '/banner');
            $data['theme'] = array_merge($data['theme'] ?? $tenant->theme ?? [], ['banner_url' => $urls['image_url']]);
        }

        if ($request->hasFile('favicon')) {
            $url = $this->imageService->uploadFavicon($request->file('favicon'), $tenant->slug);
            $data['theme'] = array_merge($data['theme'] ?? $tenant->theme ?? [], ['favicon_url' => $url]);
        }

        // Las claves de archivo no son columnas del modelo: quitarlas antes de guardar.
        unset($data['logo'], $data['banner'], $data['favicon']);

        try {
            DB::transaction(function () use ($tenant, $data) {
                if (array_key_exists('custom_domain', $data)) {
                    $this->prepararDominioPropio($tenant, $data['custom_domain']);
                }

                $tenant->update($data);
            });
        } catch (QueryException $e) {
            // El UNIQUE de la columna es el ultimo cerrojo, detras de la
            // validacion de arriba: dos peticiones reclamando el MISMO dominio
            // abandonado a la vez pasan las dos esa comprobacion (ninguna ve
            // todavia el cambio de la otra) y solo una gana la escritura.
            // Rarisimo -exige el mismo dominio libre y las dos peticiones en la
            // misma fraccion de segundo- pero un 422 claro es mejor que un 500.
            return response()->json([
                'message' => 'Alguien más acaba de quedarse con ese dominio justo ahora. Prueba con otro.',
            ], 422);
        }

        // Invalidar la caché pública del tenant para que el branding se refleje al instante
        Cache::forget("tenant:{$tenant->slug}");

        return response()->json($tenant->fresh());
    }

    /**
     * Todo lo que rodea al VALOR del dominio, aparte de guardarlo (eso lo hace
     * el `update()` normal de arriba, porque `custom_domain` sigue siendo una
     * columna corriente de `$data`).
     *
     * Las tres columnas de verificación son de sistema —fuera de `$fillable`,
     * ver el modelo— y sólo se escriben aquí, con `forceFill()`, nunca desde
     * una petición.
     *
     * Se llama SIEMPRE dentro de la transacción de `update()`: si hay que
     * liberar el dominio de otra tienda, esa liberación y el resto del guardado
     * tienen que ir juntos o nada.
     */
    private function prepararDominioPropio(Tenant $tenant, ?string $nuevoDominio): void
    {
        // Resometer el mismo valor (guardar otra cosa sin tocar el dominio) no
        // reinicia nada: perdería el progreso de una verificación en curso.
        if ($nuevoDominio === $tenant->custom_domain) {
            return;
        }

        if (blank($nuevoDominio)) {
            $tenant->forceFill([
                'custom_domain_token'        => null,
                'custom_domain_requested_at' => null,
                'custom_domain_verified_at'  => null,
            ])->save();

            return;
        }

        // Liberar el dominio si lo tenía una tienda que lo pidió y nunca lo
        // verificó: la validación de arriba ya comprobó que se puede pedir
        // (`LIBERAR_DOMINIO_TRAS_HORAS`); esto sólo lo suelta para no chocar
        // con el UNIQUE de la columna al guardar el nuevo dueño.
        Tenant::where('custom_domain', $nuevoDominio)
            ->where('id', '!=', $tenant->id)
            ->update([
                'custom_domain'               => null,
                'custom_domain_token'         => null,
                'custom_domain_requested_at'  => null,
                'custom_domain_verified_at'   => null,
            ]);

        $tenant->forceFill([
            'custom_domain_token'        => Str::random(32),
            'custom_domain_requested_at' => now(),
            'custom_domain_verified_at'  => null,
        ])->save();
    }

    /**
     * Comprobar el registro TXT y, si está, marcar el dominio como verificado
     * (FUN-6).
     *
     * Mientras no esté verificado, ni el catálogo público ni el panel resuelven
     * la tienda por este dominio (`Tenant::scopeConDominioVerificado`, usado en
     * `PublicCatalogController::resolveDomain` e `InitializeTenantByHeader`):
     * "lo escribí" y "lo demostré" son preguntas distintas, y sólo la segunda
     * abre algo.
     */
    public function verifyCustomDomain(DomainVerifier $verificador): JsonResponse
    {
        $tenant = app('currentTenant');

        if (blank($tenant->custom_domain)) {
            return response()->json([
                'message' => 'Esta tienda no tiene ningún dominio propio configurado.',
            ], 422);
        }

        if ($tenant->custom_domain_verified_at) {
            return response()->json([
                'verified' => true,
                'message'  => 'Este dominio ya está verificado.',
            ]);
        }

        // Subdominio con guion bajo (`_saas-verify`) y no el dominio pelado, por
        // lo mismo que `_dmarc` o `_domainconnect`: así no compite con un
        // subdominio de verdad que el dueño ya tuviera.
        $host = '_saas-verify.'.$tenant->custom_domain;
        $valorEsperado = 'saas-verify='.$tenant->custom_domain_token;

        if (! $verificador->tieneRegistroTxt($host, $valorEsperado)) {
            return response()->json([
                'verified' => false,
                'message'  => "No encontramos el registro TXT en {$host}. Puede tardar unas horas en propagarse desde que lo agregas.",
            ], 422);
        }

        $tenant->forceFill(['custom_domain_verified_at' => now()])->save();
        Cache::forget("tenant:{$tenant->slug}");

        return response()->json([
            'verified' => true,
            'message'  => 'Dominio verificado: ya sirve el catálogo de esta tienda.',
            'tenant'   => $tenant->fresh(),
        ]);
    }
}
