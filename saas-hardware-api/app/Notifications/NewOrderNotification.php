<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al dueño de que entro un pedido nuevo (OWN-2 / 7.3).
 *
 * Hasta ahora el unico aviso era que el comprador pulsara "enviar" en WhatsApp;
 * si cerraba el chat sin enviarlo, el pedido se quedaba en el panel sin que
 * nadie lo supiera.
 *
 * AUD-11: ahora va a la cola. Antes se enviaba dentro de la peticion, asi que el
 * comprador esperaba al SMTP —unos segundos, o el timeout entero si el servidor
 * de correo no respondia— cuando su pedido ya estaba guardado y no dependia de
 * ese correo para nada.
 *
 * **Lo que viaja a la cola es una copia plana del pedido, no el modelo.** El
 * trabajo se ejecuta en otro proceso, sin peticion y por tanto sin tienda
 * resuelta, y desde AUD-4 el scope de tenant falla en cerrado: si aqui viajara
 * el `Order`, al restaurarlo el worker no encontraria ni el pedido ni sus
 * lineas y el aviso moriria en `failed_jobs`. El constructor corre dentro de la
 * peticion —con su tienda resuelta—, asi que resuelve ahi el texto y el worker
 * ya no necesita saber de tiendas.
 */
class NewOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Lo que el correo necesita del pedido, ya resuelto a texto.
     *
     * @var array<string, mixed>
     */
    public array $pedido;

    public function __construct(Order $order)
    {
        // La moneda se lee del tenant del pedido, no del usuario que recibe el
        // correo: son el mismo tenant, pero el dato correcto es el de la venta (OWN-1).
        $moneda = $order->tenant?->currency;

        $this->pedido = [
            // Mismo identificador corto que muestra el panel (OrdersPage), para que
            // el dueño pueda casar el correo con la fila de la tabla.
            'referencia' => '#'.substr($order->id, -8),
            'cliente'    => $order->customer_name,
            'telefono'   => $order->customer_phone,
            'nota'       => $order->customer_note,
            'total'      => Money::format($order->total, $moneda),
            'lineas'     => $order->items->map(fn ($item) => [
                'cantidad' => $item->quantity,
                'producto' => $item->product_name,
                'subtotal' => Money::format($item->subtotal, $moneda),
            ])->all(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Pedido nuevo {$this->pedido['referencia']} por {$this->pedido['total']}")
            ->greeting("Hola {$notifiable->name},")
            ->line("**{$this->pedido['cliente']}** acaba de hacer un pedido en tu tienda.")
            ->line("Telefono: {$this->pedido['telefono']}");

        if ($this->pedido['nota']) {
            $mail->line("Nota del cliente: {$this->pedido['nota']}");
        }

        $mail->line('---');

        foreach ($this->pedido['lineas'] as $linea) {
            $mail->line("{$linea['cantidad']} x {$linea['producto']} — {$linea['subtotal']}");
        }

        return $mail
            ->line("**Total: {$this->pedido['total']}**")
            ->action('Ver el pedido en el panel', rtrim(config('app.frontend_url'), '/').'/dashboard/orders')
            ->line('El pedido queda en estado pendiente hasta que lo atiendas desde el panel.');
    }
}
