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
 * Aviso al comprador de que su pedido cambio de estado (FUN-2).
 *
 * El dueno ya tenia el boton de WhatsApp con el mensaje redactado, pero eso
 * obliga a que se acuerde y a que el cliente tenga telefono. Este correo sale
 * solo, y solo cuando el comprador dejo su correo.
 *
 * **No se avisa de todos los estados.** Volver a "pendiente" es una correccion
 * interna del dueno —se equivoco de fila, deshace un "atendido"—, y avisar de eso
 * confunde al cliente: le llegaria un correo diciendo que su pedido, que ya
 * estaba listo, vuelve a estar pendiente. Quien decide que estados avisan es
 * `mensajePorEstado()`, y devolver null es lo que apaga el aviso.
 *
 * Copia plana y a la cola por los mismos motivos que las otras dos
 * notificaciones (AUD-11 y AUD-4).
 */
class OrderStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @var array<string, mixed>
     */
    public array $pedido;

    public function __construct(Order $order)
    {
        $tenant = $order->tenant;

        $this->pedido = [
            'referencia' => '#'.$order->number,
            'cliente'    => $order->customer_name,
            'tienda'     => $tenant?->name ?? 'la tienda',
            'estado'     => $order->status,
            'whatsapp'   => $tenant?->whatsapp_number,
            'url'        => StoreUrl::forTenant($tenant),
            'total'      => Money::format($order->total, $tenant?->currency),
        ];
    }

    /**
     * Si un estado merece correo, y con que texto.
     *
     * Devuelve null para los estados que no se avisan.
     *
     * @return array{asunto: string, cuerpo: string}|null
     */
    public static function mensajePorEstado(string $estado, string $referencia, string $tienda, string $total): ?array
    {
        return match ($estado) {
            'processing' => [
                'asunto' => "Tu pedido {$referencia} esta en preparacion",
                'cuerpo' => "Tu pedido **{$referencia}** ya esta en preparacion en {$tienda}. Te avisamos apenas este listo.",
            ],
            'attended' => [
                'asunto' => "Tu pedido {$referencia} ya esta listo",
                'cuerpo' => "Tu pedido **{$referencia}** por un total de {$total} ya esta listo en {$tienda} para que lo recojas o te lo entreguemos. Gracias por tu compra.",
            ],
            'cancelled' => [
                'asunto' => "Tu pedido {$referencia} fue cancelado",
                'cuerpo' => "Tu pedido **{$referencia}** fue cancelado en {$tienda}. Si crees que es un error o tienes dudas, respondenos por aqui.",
            ],
            default => null,
        };
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
        $texto = self::mensajePorEstado(
            $this->pedido['estado'],
            $this->pedido['referencia'],
            $this->pedido['tienda'],
            $this->pedido['total'],
        );

        $mail = (new MailMessage)
            ->subject($texto['asunto'])
            ->greeting("Hola {$this->pedido['cliente']},")
            ->line($texto['cuerpo']);

        if ($this->pedido['url']) {
            $mail->action('Ver la tienda', $this->pedido['url']);
        }

        if ($this->pedido['whatsapp']) {
            $mail->line("Cualquier duda, escribenos por WhatsApp al {$this->pedido['whatsapp']}.");
        }

        return $mail;
    }
}
