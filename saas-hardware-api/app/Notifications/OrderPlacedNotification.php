<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use App\Support\StoreUrl;
use App\Support\TextoDeCorreo;
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
            'cliente' => $order->customer_name,
            'tienda' => $tenant?->name ?? 'la tienda',
            'whatsapp' => $tenant?->whatsapp_number,
            'url' => StoreUrl::forTenant($tenant),
            'entrega' => match ($order->delivery_method) {
                'delivery' => 'Delivery ('.Money::format($order->delivery_cost, $moneda).')',
                'pickup' => 'Recojo en tienda',
                default => null,
            },
            'total' => Money::format($order->total, $moneda),
            'lineas' => $order->items->map(fn ($item) => [
                'cantidad' => $item->quantity,
                'producto' => $item->descripcion(),
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
        // SEC-8: el nombre de la tienda y los de los productos los escribe el
        // dueno, no un desconocido, pero se escapan igual: decidir caso por caso
        // cual de los ocho correos merece escapado es como se olvida el noveno.
        $tienda = TextoDeCorreo::enLinea($this->pedido['tienda']);

        $mail = (new MailMessage)
            ->subject("Recibimos tu pedido {$this->pedido['referencia']} en {$this->pedido['tienda']}")
            ->greeting('Hola '.TextoDeCorreo::enLinea($this->pedido['cliente']).',')
            ->line("Recibimos tu pedido **{$this->pedido['referencia']}** en {$tienda}. Te avisaremos cuando este listo.")
            ->line('---');

        foreach ($this->pedido['lineas'] as $linea) {
            $producto = TextoDeCorreo::enLinea($linea['producto']);
            $mail->line("{$linea['cantidad']} x {$producto} — {$linea['subtotal']}");
        }

        if ($this->pedido['entrega']) {
            $mail->line('Entrega: '.TextoDeCorreo::enLinea($this->pedido['entrega']));
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
            $mail->line('Cualquier duda, escribenos por WhatsApp al '.TextoDeCorreo::enLinea($this->pedido['whatsapp']).'.');
        }

        return $mail;
    }
}
