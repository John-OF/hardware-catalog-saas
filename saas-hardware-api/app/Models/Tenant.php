<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Concerns\UsesMultitenancyConfig;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;

class Tenant extends Model implements IsTenant
{
    use HasUuids, ImplementsTenant, UsesMultitenancyConfig;

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * Toda tienda nace con el rol «Colaborador» (MOD-21), para que la pantalla
     * de equipo tenga desde el primer día un rol que dar al invitar. Las que ya
     * existían lo recibieron en la migración `create_team_roles_table`.
     */
    protected static function booted(): void
    {
        static::created(function (Tenant $tenant) {
            TeamRole::crearDeFabrica($tenant->id);
        });
    }

    protected $fillable = [
        'slug', 'name', 'logo_url', 'primary_color', 'theme',
        'whatsapp_number', 'plan', 'is_active', 'is_published', 'custom_domain', 'currency',
        'timezone', 'payment_methods', 'delivery_enabled', 'delivery_cost',
        // MOD-2. `tax_included` decide si el porcentaje se saca del precio o se
        // suma al final, y cambia la aritmética entera: ver `App\Support\Impuesto`.
        'tax_enabled', 'tax_name', 'tax_rate', 'tax_included',
        // MOD-25: solo lo que la tienda cambió; lo demás sale de la config.
        'whatsapp_templates',
        // MOD-19: solo lo escribe el operador (`PlatformController::updateTenant`);
        // la pantalla de Configuración valida su propia lista y no la incluye.
        'vertical',
    ];

    /**
     * MOD-19: los módulos de la vertical, ya resueltos, viajan con la tienda a
     * todas partes —el catálogo público y las seis respuestas del panel que la
     * devuelven—, así ninguna pantalla tiene que preguntar por el nombre de la
     * vertical ni acordarse de pedirlos aparte.
     */
    protected $appends = ['modulos'];

    /**
     * MOD-25: lo guardado son solo los textos que la tienda cambió, y nadie
     * fuera de `App\Support\PlantillasWhatsapp` debería leerlo así: el panel
     * recibe las diez resueltas por `GET /api/whatsapp-templates` y el
     * catálogo, solo las del comprador. Oculto, ni el `GET /api/tenant` ni el
     * caché del catálogo se llevan el crudo, que incluye los mensajes que la
     * tienda manda a sus clientes.
     */
    protected $hidden = ['whatsapp_templates'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        // TEC-15: el logo, y la portada y el favicon dentro del tema, se guardan
        // como ruta del disco y se leen como URL completa.
        'logo_url' => \App\Casts\ImagenDelDisco::class,
        'theme' => \App\Casts\TemaDeTienda::class,
        'payment_methods' => 'array',
        'delivery_enabled' => 'boolean',
        'delivery_cost' => 'decimal:2',
        'tax_enabled' => 'boolean',
        'tax_rate' => 'decimal:2',
        'tax_included' => 'boolean',
        'whatsapp_templates' => 'array',
        'custom_domain_requested_at' => 'datetime',
        'custom_domain_verified_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        // INF-23: el «pagado hasta». De sistema, como `trial_ends_at`: fuera de
        // $fillable, lo mueve solo `App\Support\Membresia`.
        'plan_ends_at' => 'datetime',
    ];

    /**
     * Los métodos de pago que la tienda puede enseñar al comprador (MOD-3).
     *
     * Cuatro, fijos: no es una pasarela (eso es `SAAS-3`, y cobra a la tienda,
     * no al comprador de la tienda), es solo dónde pone el dueño su Yape, su
     * cuenta o si acepta efectivo contra entrega. Los campos de cada uno están
     * en `TenantController::update()` (la validación) y en `PaymentMethodsForm`
     * del frontend.
     */
    /**
     * La zona horaria de la tienda, siempre utilizable (MOD-13).
     *
     * Nunca devuelve null ni vacio: una tienda de antes de esta columna, o una
     * fila a la que alguien le metio `''` a mano, cae en UTC, que es como se
     * comportaba el sistema entero antes de MOD-13. Lo que NO se hace aqui es
     * adivinar la zona por la moneda: una tienda peruana puede cobrar en
     * dolares, y dos tiendas con la misma moneda pueden estar en husos
     * distintos.
     *
     * Todo se sigue guardando en UTC. Esto solo decide como se LEE: en que dia
     * cae una venta al agrupar los reportes y con que hora se pinta una fecha.
     */
    public function zonaHoraria(): string
    {
        return $this->timezone ?: 'UTC';
    }

    /**
     * Qué módulos tiene esta tienda por su vertical (MOD-19). Ver
     * `App\Support\Verticales`.
     *
     * @return array<string, bool>
     */
    public function getModulosAttribute(): array
    {
        return \App\Support\Verticales::modulos($this);
    }

    public const METODOS_DE_PAGO = ['yape', 'plin', 'transferencia', 'efectivo'];

    /**
     * Los métodos que la tienda activó, con sus datos. Nunca los que están
     * apagados: mostrar un Yape a medio llenar es peor que no mostrar Yape.
     *
     * @return array<string, array<string, mixed>>
     */
    public function metodosDePagoActivos(): array
    {
        return collect($this->payment_methods ?? [])
            ->only(self::METODOS_DE_PAGO)
            ->filter(fn ($datos) => is_array($datos) && ($datos['enabled'] ?? false))
            ->all();
    }

    /**
     * Cuánto dura la prueba de una tienda nueva (FUN-16).
     *
     * Sólo la usa `AuthController::register()`, al crear la tienda -de ahí que
     * viva aquí y no en `config/plans.php`: no es un límite de ningún plan, es
     * cuánto tiempo se le da a alguien para decidir antes de tener que elegir
     * uno.
     */
    public const DIAS_DE_PRUEBA = 7;

    /**
     * Si esta tienda sigue dentro de su período de prueba (FUN-16).
     *
     * `trial_ends_at` nula significa que nunca tuvo prueba (una tienda vieja,
     * de antes de este cambio) o que ya pagó un plan -ver
     * `Membresia::registrarPago()`, que la vacía (INF-23)-. Una
     * prueba VENCIDA (la fecha ya pasó) tampoco cuenta: para entonces el
     * trabajo programado ya debería haber suspendido la tienda, y aunque no
     * hubiera corrido todavía, tratarla como "sigue en prueba" le regalaría
     * tiempo de más sólo por un retraso del cron.
     */
    public function enPrueba(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /** Por qué está cerrada una tienda (FUN-28): ver `cierre()`. */
    public const CIERRE_PRUEBA = 'prueba';
    public const CIERRE_PLAN = 'plan';
    public const CIERRE_SUSPENDIDA = 'suspendida';

    /**
     * Si la tienda está cerrada, por qué y desde cuándo (FUN-28). `null` si está
     * abierta.
     *
     * No se guarda en ninguna columna: sale de las mismas dos fechas que usa el
     * cierre automático (`trials:cerrar-vencidas`). Una prueba o un plan vencidos
     * son eso; cualquier otra tienda inactiva la cerró el operador a mano. Es lo
     * que necesita la pantalla del dueño para decirle algo útil: no es lo mismo
     * «elige un plan» que «escríbenos».
     *
     * **No va en `$appends`, a propósito.** Lee tres columnas, y el catálogo
     * público consulta `tenants` eligiendo columnas (`COLUMNAS_PUBLICAS_TENANT`):
     * sin `is_active` entre ellas, toda tienda abierta saldría «suspendida». La
     * piden a mano el login, `me` y el 403 del panel.
     *
     * @return array{motivo: string, desde: ?string, plan: string}|null
     */
    public function cierre(): ?array
    {
        if ($this->is_active) {
            return null;
        }

        [$motivo, $desde] = match (true) {
            $this->trial_ends_at?->isPast() === true => [self::CIERRE_PRUEBA, $this->trial_ends_at],
            $this->plan_ends_at?->isPast() === true  => [self::CIERRE_PLAN, $this->plan_ends_at],
            default                                  => [self::CIERRE_SUSPENDIDA, null],
        };

        return [
            'motivo' => $motivo,
            'desde'  => $desde?->toIso8601String(),
            // El nombre comercial del plan que tenía, para «tu plan Pro venció».
            'plan'   => \App\Support\PlanGate::labelDe((string) $this->plan),
        ];
    }

    /**
     * Las tiendas que se ven desde fuera (FUN-5).
     *
     * Este scope es la respuesta unica a "¿esta tienda es publica?", que antes
     * estaba escrita a mano como `where('is_active', true)` en seis sitios: el
     * middleware de slug, `resolveDomain` y las cuatro rutas de crawler de
     * `web.php`. Con dos condiciones en vez de una, repartirlas otra vez era
     * garantizar que alguna se quedara con la mitad de la regla; de hecho el
     * sintoma seria el peor posible, una tienda sin verificar cuya vista previa
     * de WhatsApp si funciona.
     *
     * Los `where('is_active', true)` que quedan dentro de `PublicCatalogController`
     * NO se han tocado: corren despues del middleware, asi que son la segunda
     * barrera de AUD-4 y su redundancia es el objetivo.
     */
    public function scopePublica(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_published', true);
    }

    /**
     * Dominio propio VERIFICADO (FUN-6).
     *
     * No basta con que `custom_domain` tenga un valor: hasta que se demuestre
     * con el registro TXT, ese valor es solo lo que alguien escribió, y podría
     * ser el dominio de otra persona. Centralizado por el mismo motivo que
     * `scopePublica()`: es la unica pregunta que importa antes de resolver una
     * tienda por su dominio, y repetirla a mano en cada sitio es la forma
     * segura de que un dia se olvide en uno.
     */
    /**
     * Las tiendas abiertas cuyo plan pagado vence en los próximos días (INF-23):
     * a quién hay que cobrarle antes de que se cierre. La usan el resumen y el
     * filtro del listado del operador, para que los dos cuenten lo mismo.
     */
    public function scopePorVencer(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('plan_ends_at')
            ->where('plan_ends_at', '>=', now())
            ->where('plan_ends_at', '<', now()->addDays(\App\Support\Membresia::DIAS_DE_AVISO));
    }

    public function scopeConDominioVerificado(Builder $query): Builder
    {
        return $query->whereNotNull('custom_domain_verified_at');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** La usa el panel de plataforma para contar pedidos por tienda (SAAS-4). */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Los cobros de su plan (INF-23). Sin global scope: ver `TenantPayment`. */
    public function payments(): HasMany
    {
        return $this->hasMany(TenantPayment::class);
    }
}
