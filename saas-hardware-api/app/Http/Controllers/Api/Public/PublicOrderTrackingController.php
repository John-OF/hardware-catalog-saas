<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Comprobantes;
use App\Support\Subidas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El pedido visto por quien lo hizo, sin cuenta (MOD-26).
 *
 * El comprador recibe un enlace (`/{slug}/pedido/{token}`) en su mensaje de
 * WhatsApp, en el correo de confirmación y en los mensajes que le manda la
 * tienda. Con él ve cómo va su pedido y manda el comprobante de su pago, y la
 * tienda lo revisa en el panel. **Nunca se cobra aquí en nombre de la tienda**
 * (`revision_mvpsaas.md`, 3.2): el dinero va directo a la cuenta de la tienda,
 * y esto solo lleva la prueba.
 *
 * El enlace es la única llave, así que lo que devuelve es lo mínimo para
 * seguir un pedido: ni el teléfono, ni el correo, ni la nota del comprador, por
 * si el enlace acaba reenviado a otra persona. El pedido se busca **dentro de
 * la tienda del slug** (`tenant.slug`): el token de otra tienda da 404.
 */
class PublicOrderTrackingController extends Controller
{
    public function show(string $slug, string $token): JsonResponse
    {
        return response()->json($this->paraElComprador($this->pedido($token)));
    }

    /**
     * El comprobante del pago. Se puede mandar mientras el pedido siga vivo y
     * el pago no esté confirmado; mandar otro reemplaza al anterior (una foto
     * borrosa se corrige mandando otra) y vuelve a dejar el pago en revisión.
     */
    public function comprobante(Request $request, string $slug, string $token): JsonResponse
    {
        $pedido = $this->pedido($token);

        $request->validate(
            ['file' => ['required', 'file', ...Subidas::reglas('comprobante')]],
            [],
            ['file' => 'comprobante'],
        );

        if ($pedido->status === 'cancelled') {
            return response()->json(['message' => 'Este pedido está cancelado: ya no hace falta mandar un comprobante.'], 422);
        }

        if ($pedido->payment_status === Order::PAGO_CONFIRMADO) {
            return response()->json(['message' => 'La tienda ya confirmó tu pago. No hace falta mandar otro comprobante.'], 422);
        }

        $anterior = $pedido->payment_proof_path;

        $pedido->forceFill([
            'payment_proof_path' => Comprobantes::guardar($pedido, $request->file('file')),
            'payment_proof_uploaded_at' => now(),
            'payment_status' => Order::PAGO_EN_REVISION,
            'payment_reviewed_at' => null,
            'payment_rejection_reason' => null,
        ])->save();

        Comprobantes::borrar($anterior);

        return response()->json($this->paraElComprador($pedido));
    }

    private function pedido(string $token): Order
    {
        // El token tiene forma fija: lo que no la tiene no llega a la base.
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return Order::where('tracking_token', $token)->with('items')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function paraElComprador(Order $pedido): array
    {
        return [
            'number'          => $pedido->number,
            'status'          => $pedido->status,
            'created_at'      => $pedido->created_at,
            'customer_name'   => $pedido->customer_name,
            'items'           => $pedido->items->map(fn ($linea) => [
                'product_name' => $linea->product_name,
                'variant_name' => $linea->variant_name,
                'quantity'     => $linea->quantity,
                'unit_price'   => $linea->unit_price,
                'subtotal'     => $linea->subtotal,
            ])->values(),
            'items_subtotal'  => $pedido->items_subtotal,
            'discount_amount' => $pedido->discount_amount,
            'coupon_code'     => $pedido->coupon_code,
            'delivery_method' => $pedido->delivery_method,
            'delivery_cost'   => $pedido->delivery_cost,
            'tax_name'        => $pedido->tax_name,
            'tax_rate'        => $pedido->tax_rate,
            'tax_amount'      => $pedido->tax_amount,
            'total'           => $pedido->total,
            'payment' => [
                'status'          => $pedido->payment_status,
                'proof_sent_at'   => $pedido->payment_proof_uploaded_at,
                'rejection_reason' => $pedido->payment_status === Order::PAGO_RECHAZADO ? $pedido->payment_rejection_reason : null,
                'can_upload'      => $pedido->status !== 'cancelled' && $pedido->payment_status !== Order::PAGO_CONFIRMADO,
            ],
        ];
    }
}
