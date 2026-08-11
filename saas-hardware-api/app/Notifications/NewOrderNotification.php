<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al dueño de que entro un pedido nuevo (OWN-2 / 7.3).
 *
 * Hasta ahora el unico aviso era que el comprador pulsara "enviar" en WhatsApp;
 * si cerraba el chat sin enviarlo, el pedido se quedaba en el panel sin que
 * nadie lo supiera.
 *
 * Sin ShouldQueue por el mismo motivo que ResetPasswordNotification:
 * QUEUE_CONNECTION=database y no hay worker, asi que encolarla dejaria el aviso
 * sin enviar en silencio. Quien la dispara la envuelve en try/catch para que un
 * fallo del mailer no tumbe la creacion del pedido.
 */
class NewOrderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Order $order,
    ) {
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
        // Mismo identificador corto que muestra el panel (OrdersPage), para que
        // el dueño pueda casar el correo con la fila de la tabla.
        $referencia = '#'.substr($this->order->id, -8);
        $total = $this->money($this->order->total);

        $mail = (new MailMessage)
            ->subject("Pedido nuevo {$referencia} por {$total}")
            ->greeting("Hola {$notifiable->name},")
            ->line("**{$this->order->customer_name}** acaba de hacer un pedido en tu tienda.")
            ->line("Telefono: {$this->order->customer_phone}");

        if ($this->order->customer_note) {
            $mail->line("Nota del cliente: {$this->order->customer_note}");
        }

        $mail->line('---');

        foreach ($this->order->items as $item) {
            $mail->line("{$item->quantity} x {$item->product_name} — {$this->money($item->subtotal)}");
        }

        return $mail
            ->line("**Total: {$total}**")
            ->action('Ver el pedido en el panel', rtrim(config('app.frontend_url'), '/').'/dashboard/orders')
            ->line('El pedido queda en estado pendiente hasta que lo atiendas desde el panel.');
    }

    /**
     * Formato de importe en la moneda de la tienda (OWN-1).
     *
     * La moneda se lee del tenant del pedido, no del usuario que recibe el
     * correo: son el mismo tenant, pero el dato correcto es el de la venta.
     */
    private function money(float|string $amount): string
    {
        return Money::format($amount, $this->order->tenant?->currency);
    }
}
