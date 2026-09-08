<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use App\Support\StoreUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmacion al comprador de que su pedido entro (FUN-2).
 *
 * Hasta ahora el unico correo que salia del sistema al recibir un pedido iba al
 * dueno (OWN-2). El comprador no recibia nada: si cerraba WhatsApp sin enviar el
 * mensaje, se quedaba sin ninguna constancia de lo que habia pedido, y el unico
 * seguimiento posible era que el dueno le escribiera a mano.
 *
 * **Solo se envia si el comprador dejo correo**, que es opcional en el checkout
 * (ver la migracion de `customer_email`). El que decide es quien llama, no esta
 * clase.
 *
 * **A la cola, y con una copia plana del pedido.** Los dos motivos son los mismos
 * que dejo escritos `NewOrderNotification`: el comprador no puede esperar al SMTP
 * cuando su pedido ya esta guardado (AUD-11), y el worker corre sin peticion y por
 * tanto sin tienda resuelta, asi que un `Order` restaurado alli no encontraria ni
 * sus lineas (AUD-4). El constructor corre dentro de la peticion y resuelve el
 * texto ahi.
 */
class OrderPlacedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @var array<string, mixed>
     */
    public array $pedido;

    public function __construct(Order $order)
    {
        $tenant = $order->tenant;
        $moneda = $tenant?->currency;

        $this->pedido = [
            'referencia' => '#'.$order->number,
            'cliente'    => $order->customer_name,
            'tienda'     => $tenant?->name ?? 'la tienda',
            'whatsapp'   => $tenant?->whatsapp_number,
            'url'        => StoreUrl::forTenant($tenant),
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
            ->subject("Recibimos tu pedido {$this->pedido['referencia']} en {$this->pedido['tienda']}")
            ->greeting("Hola {$this->pedido['cliente']},")
            ->line("Recibimos tu pedido **{$this->pedido['referencia']}** en {$this->pedido['tienda']}. Te avisaremos cuando este listo.")
            ->line('---');

        foreach ($this->pedido['lineas'] as $linea) {
            $mail->line("{$linea['cantidad']} x {$linea['producto']} — {$linea['subtotal']}");
        }

        $mail->line("**Total: {$this->pedido['total']}**");

        if ($this->pedido['url']) {
            $mail->action('Ver la tienda', $this->pedido['url']);
        }

        // El numero va tambien en el cuerpo y no solo en el asunto: es lo que el
        // comprador tiene que decir si escribe por WhatsApp, y el asunto se pierde
        // en cuanto reenvia el correo o lo lee en el movil.
        $mail->line("Guarda el numero **{$this->pedido['referencia']}**: es el que necesitas para preguntar por tu pedido.");

        if ($this->pedido['whatsapp']) {
            $mail->line("Cualquier duda, escribenos por WhatsApp al {$this->pedido['whatsapp']}.");
        }

        return $mail;
    }
}
