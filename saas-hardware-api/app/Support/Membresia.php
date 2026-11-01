<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\TenantPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El plan pagado de una tienda: hasta cuándo dura y cómo se renueva (INF-23).
 *
 * Mientras Paddle (7.7b) no llegue, el operador cobra a mano y apunta aquí cada
 * pago. Todo lo que mueve `tenants.plan_ends_at` pasa por esta clase: registrar
 * un pago, corregir la fecha y decir si una tienda puede reabrirse. El cierre al
 * vencer lo hace `trials:cerrar-vencidas`, con las mismas fechas.
 *
 * Tres reglas que no se ven en las columnas:
 *
 * - **Los meses se suman a lo que ya tenga.** Un pago cuenta desde hoy o, si a
 *   la tienda le queda prueba o plan pagado, desde que se acabe: pagar antes de
 *   tiempo no hace perder días. Y un mes es un mes de calendario sin
 *   desbordarse: el 31 de enero más un mes es el 28 de febrero, no el 3 de marzo.
 * - **El plan del pago se aplica en el acto**, aunque el periodo empiece más
 *   tarde. Cambiar de plan a mitad de un periodo no prorratea nada: lo justo lo
 *   decide el monto que cobra el operador.
 * - **Pagar reabre la tienda.** Una tienda se cierra al vencer su prueba o su
 *   plan; apuntar el pago es lo que la devuelve, sin un segundo paso.
 */
class Membresia
{
    /** Días antes del fin en que el panel avisa y el operador la ve «por vencer». */
    public const DIAS_DE_AVISO = 7;

    /**
     * Desde cuándo cuentan los meses de un pago nuevo: ahora, o el final de la
     * prueba o del plan pagado si alguno todavía no ha terminado.
     */
    public static function inicioDelSiguientePeriodo(Tenant $tenant): Carbon
    {
        $inicio = now();

        foreach ([$tenant->plan_ends_at, $tenant->trial_ends_at] as $fin) {
            if ($fin !== null && $fin->greaterThan($inicio)) {
                $inicio = Carbon::instance($fin);
            }
        }

        return $inicio->copy();
    }

    public static function finDelPeriodo(Carbon $inicio, int $meses): Carbon
    {
        return $inicio->copy()->addMonthsNoOverflow($meses);
    }

    /**
     * Apunta un pago y mueve la tienda a ese plan hasta la nueva fecha.
     *
     * Todo va en una transacción con la fila de la tienda bloqueada: dos pagos
     * apuntados a la vez (un doble clic) leerían el mismo «pagado hasta» y el
     * segundo no sumaría sus meses. La línea de la bitácora va dentro: en
     * plataforma es parte de la acción (`ActivityLog::registrar()` deja subir el
     * error), y un pago sin rastro de quién lo apuntó no debe existir.
     *
     * @param  array{plan: string, months: int, amount_usd: float|string, method: string, reference?: ?string, notes?: ?string, paid_on: string}  $datos
     */
    public static function registrarPago(Tenant $tenant, array $datos, ?User $operador, ?Request $request = null): TenantPayment
    {
        $pago = DB::transaction(function () use ($tenant, $datos, $operador, $request) {
            $fila = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            $planAnterior = $fila->plan;
            $estabaCerrada = ! $fila->is_active;
            $inicio = self::inicioDelSiguientePeriodo($fila);
            $fin = self::finDelPeriodo($inicio, (int) $datos['months']);

            $pago = TenantPayment::create([
                'tenant_id'           => $fila->id,
                'plan'                => $datos['plan'],
                'months'              => (int) $datos['months'],
                'amount_usd'          => $datos['amount_usd'],
                'method'              => $datos['method'],
                'reference'           => $datos['reference'] ?? null,
                'notes'               => $datos['notes'] ?? null,
                'paid_on'             => $datos['paid_on'],
                'starts_at'           => $inicio,
                'ends_at'             => $fin,
                'registered_by'       => $operador?->id,
                'registered_by_email' => $operador?->email,
            ]);

            // `forceFill`: ninguna de las cuatro es algo que una petición pueda
            // fijar por asignación masiva. Vaciar `trial_ends_at` es salir de la
            // prueba (FUN-16); sin eso, el cierre automático la volvería a cerrar.
            $fila->forceFill([
                'plan'          => $datos['plan'],
                'plan_ends_at'  => $fin,
                'trial_ends_at' => null,
                'is_active'     => true,
            ])->save();

            ActivityLog::registrar(
                ActivityLog::TIENDA_PAGO,
                self::describirPago($fila, $pago, $estabaCerrada),
                $fila,
                $operador,
                [
                    'pago'         => $pago->id,
                    'plan_antes'   => $planAnterior,
                    'plan'         => $pago->plan,
                    'meses'        => $pago->months,
                    'monto_usd'    => (string) $pago->amount_usd,
                    'medio'        => $pago->method,
                    'desde'        => $inicio->toIso8601String(),
                    'hasta'        => $fin->toIso8601String(),
                    'reabierta'    => $estabaCerrada,
                ],
                $request,
            );

            return $pago;
        });

        $tenant->refresh();

        // La caché del catálogo guarda la tienda 5 minutos: sin esto, una tienda
        // que se reabre al pagar seguiría dando 404 un rato.
        Cache::forget("tenant:{$tenant->slug}");

        return $pago;
    }

    /**
     * Corrige el «pagado hasta» a mano: la salida cuando un pago se apuntó mal
     * (12 meses en vez de 1), porque los pagos no se editan.
     *
     * Solo para una tienda que ya tiene fecha: a una en prueba o sin pagos le
     * pondría un plan sin pago detrás, que es justo lo que `INF-23` quita. Para
     * dar tiempo sin cobrar está el pago de cortesía, que queda en el historial.
     */
    public static function corregirFin(Tenant $tenant, Carbon $fin, ?User $operador, ?Request $request = null): void
    {
        $antes = $tenant->plan_ends_at;

        $tenant->forceFill(['plan_ends_at' => $fin])->save();

        ActivityLog::registrar(
            ActivityLog::TIENDA_FECHA_PLAN,
            'Corrigió hasta cuándo está pagada '.self::nombre($tenant).': del '
                .self::fecha($tenant, $antes).' al '.self::fecha($tenant, $fin).'.',
            $tenant,
            $operador,
            ['antes' => $antes?->toIso8601String(), 'despues' => $fin->toIso8601String()],
            $request,
        );
    }

    /**
     * Por qué no se puede reabrir a mano esta tienda, o `null` si se puede.
     *
     * Una tienda con la prueba o el plan vencidos que se reabriera sin más
     * volvería a cerrarse sola en la siguiente pasada de `trials:cerrar-vencidas`:
     * el operador creería haberla reabierto y el dueño la vería cerrarse otra vez
     * al día siguiente. Se dice ahora, con lo que hay que hacer.
     */
    public static function motivoParaNoReabrir(Tenant $tenant): ?string
    {
        if ($tenant->trial_ends_at?->isPast()) {
            return 'La prueba de '.self::nombre($tenant).' terminó el '.self::fecha($tenant, $tenant->trial_ends_at)
                .'. Registra un pago para reabrirla (con «Cortesía» y monto 0 si no se cobra).';
        }

        if ($tenant->plan_ends_at?->isPast()) {
            return 'El plan de '.self::nombre($tenant).' venció el '.self::fecha($tenant, $tenant->plan_ends_at)
                .'. Registra un pago para reabrirla, o corrige la fecha si se apuntó mal.';
        }

        return null;
    }

    /** La frase de la bitácora: «Registró un pago de X: Pro, 1 mes, 29,00 US$ por transferencia…». */
    private static function describirPago(Tenant $tenant, TenantPayment $pago, bool $reabierta): string
    {
        $meses = $pago->months === 1 ? '1 mes' : "{$pago->months} meses";

        $importe = $pago->method === 'cortesia'
            ? 'sin cobro (cortesía)'
            : Money::format((string) $pago->amount_usd, 'USD').' por '.mb_strtolower(TenantPayment::MEDIOS[$pago->method] ?? $pago->method);

        return 'Registró un pago de '.self::nombre($tenant).': '.PlanGate::labelDe($pago->plan).", {$meses}, {$importe}. "
            .'Pagado hasta el '.self::fecha($tenant, $pago->ends_at).'.'
            .($reabierta ? ' La tienda vuelve a abrir.' : '');
    }

    /**
     * El nombre acortado: la descripción de la bitácora tiene 255 caracteres y el
     * de una tienda puede llegar a 200. Pasarse no recorta, falla, y aquí un fallo
     * deshace el pago.
     */
    private static function nombre(Tenant $tenant): string
    {
        return Str::limit($tenant->name, 60);
    }

    /** Una fecha como la lee el dueño: en el día de su zona horaria (MOD-13). */
    private static function fecha(Tenant $tenant, ?\DateTimeInterface $fecha): string
    {
        return $fecha === null
            ? 'sin fecha'
            : Carbon::instance($fecha)->setTimezone($tenant->zonaHoraria())->format('d/m/Y');
    }
}
