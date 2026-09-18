<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Coupon;
use App\Support\Bitacora;
use App\Support\Paginacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Los cupones de la tienda (`MOD-4`). **Solo admin.**
 *
 * Un cupón es dinero que se deja de cobrar, así que decide quién decide los
 * precios — el mismo criterio que las acciones en lote sobre precios (`FUN-4`).
 */
class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cupones = Coupon::orderByDesc('created_at')
            ->paginate(Paginacion::porPagina($request, 20));

        return response()->json($cupones);
    }

    public function store(Request $request): JsonResponse
    {
        // El techo va lo primero, antes de escribir nada, como los topes de plan
        // (SAAS-3) aunque este no sea uno.
        abort_if(
            Coupon::count() >= Coupon::MAXIMO_POR_TIENDA,
            422,
            'Tu tienda ya tiene '.Coupon::MAXIMO_POR_TIENDA.' cupones. Borra o desactiva alguno antes de crear otro.',
        );

        $datos = $this->validar($request);

        $cupon = Coupon::create($datos);

        Bitacora::anotar(
            ActivityLog::CUPON_CREADO,
            "Creó el cupón {$cupon->code} ({$cupon->etiqueta}).",
            ['cupon_id' => $cupon->id, 'codigo' => $cupon->code],
        );

        return response()->json($cupon, 201);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $antes = $coupon->only(['code', 'type', 'value', 'min_purchase', 'max_uses', 'is_active']);

        $coupon->update($this->validar($request, $coupon));

        $cambios = Bitacora::cambios($antes, $coupon->fresh()->only(array_keys($antes)), [
            'code' => 'código',
            'type' => 'tipo',
            'value' => 'valor',
            'min_purchase' => 'compra mínima',
            'max_uses' => 'usos máximos',
            'is_active' => 'activo',
        ]);

        Bitacora::anotar(
            ActivityLog::CUPON_EDITADO,
            "Editó el cupón {$coupon->code}".($cambios ? ': '.Bitacora::resumirCambios($cambios) : '').'.',
            ['cupon_id' => $coupon->id, 'cambios' => $cambios],
        );

        return response()->json($coupon->fresh());
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $codigo = $coupon->code;

        // Sin papelera (`MOD-8`): un cupón no tiene fotos ni historial propio, y
        // los pedidos que lo usaron guardan su código como snapshot, así que
        // borrarlo no borra nada de lo vendido. Para "quitarlo de circulación sin
        // perder el contador" está `is_active`.
        $coupon->delete();

        Bitacora::anotar(
            ActivityLog::CUPON_BORRADO,
            "Borró el cupón {$codigo}.",
            ['codigo' => $codigo],
        );

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Coupon $actual = null): array
    {
        $tenant = app('currentTenant');

        // El codigo se normaliza ANTES de validar. `Coupon::setCodeAttribute` lo
        // pasa a mayusculas al guardar, asi que sin esto la regla `unique`
        // compara "repe" contra el "REPE" guardado, no lo encuentra, y el choque
        // salta abajo como un 500 de la base en vez de un error de formulario.
        if ($request->has('code')) {
            $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);
        }

        return $request->validate([
            // Único por tienda, no global: dos tiendas pueden tener su
            // "VERANO25" sin enterarse. Se compara en mayúsculas porque así se
            // guarda (`Coupon::setCodeAttribute`).
            'code' => [
                $actual ? 'sometimes' : 'required',
                'string',
                'max:40',
                // Sin espacios ni signos: el comprador lo teclea en el móvil.
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('coupons', 'code')
                    ->where('tenant_id', $tenant->id)
                    ->ignore($actual?->id),
            ],
            'type' => [$actual ? 'sometimes' : 'required', Rule::in(['percent', 'fixed'])],
            'value' => [$actual ? 'sometimes' : 'required', 'numeric', 'min:0.01'],
            'min_purchase' => 'nullable|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'is_active' => 'sometimes|boolean',
        ], [
            'code.regex' => 'El código solo puede llevar letras, números, guiones y guiones bajos.',
            'code.unique' => 'Ya tienes un cupón con ese código.',
            'ends_at.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
        ]);
    }
}
