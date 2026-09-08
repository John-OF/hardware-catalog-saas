<?php

namespace App\Notifications;

use App\Models\Product;
use App\Support\Money;
use App\Support\StoreUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Avisame cuando llegue": el producto volvio a estar disponible (FUN-1b).
 *
 * Cierra la funcion que estaba a medias desde 5.4: el formulario existia, el
 * interes se registraba y el disparador funcionaba, pero al reponer stock solo se
 * escribia un log. Peor aun, hasta FUN-1a se marcaba `notified_at` igual, asi que
 * cada reposicion consumia la lista de espera sin avisar a nadie.
 *
 * **Es un correo con prisa.** A quien esperaba una pieza agotada le sirve de poco
 * enterarse cuando ya se agoto otra vez, asi que el texto dice el stock que hay y
 * lleva el enlace directo a la ficha, no a la portada de la tienda.
 *
 * Copia plana y a la cola, como las otras tres notificaciones (AUD-11 y AUD-4): el
 * dueno esta guardando un producto en el panel y no puede esperar a que salgan N
 * correos, y el worker corre sin tienda resuelta.
 */
class BackInStockNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @var array<string, mixed>
     */
    public array $aviso;

    public function __construct(Product $product, string $nombreCliente)
    {
        $tenant = $product->tenant;

        // El precio que se anuncia es el que veria en el catalogo: el de oferta
        // cuando existe. Es el mismo criterio que aplica OrderPricing al cobrar.
        $precio = $product->sale_price !== null ? $product->sale_price : $product->price;

        $this->aviso = [
            'cliente'  => $nombreCliente,
            'producto' => $product->name,
            'tienda'   => $tenant?->name ?? 'la tienda',
            'precio'   => Money::format($precio, $tenant?->currency),
            'stock'    => (int) $product->stock,
            'whatsapp' => $tenant?->whatsapp_number,
            'url'      => StoreUrl::forProduct($tenant, $product->id),
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
            ->subject("Ya llego: {$this->aviso['producto']}")
            ->greeting("Hola {$this->aviso['cliente']},")
            ->line("**{$this->aviso['producto']}** volvio a estar disponible en {$this->aviso['tienda']}, a {$this->aviso['precio']}.");

        // Decir cuantas quedan no es un adorno: quien espera una pieza agotada
        // necesita saber si tiene que correr o puede pensarselo.
        if ($this->aviso['stock'] <= 3) {
            $mail->line("Quedan pocas unidades ({$this->aviso['stock']}).");
        }

        if ($this->aviso['url']) {
            $mail->action('Ver el producto', $this->aviso['url']);
        }

        if ($this->aviso['whatsapp']) {
            $mail->line("Si prefieres, escribenos por WhatsApp al {$this->aviso['whatsapp']}.");
        }

        return $mail->line('Recibes este correo porque pediste que te avisaramos de este producto. No te escribiremos por nada mas.');
    }
}
