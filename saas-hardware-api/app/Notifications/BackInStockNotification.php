<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Money;
use App\Support\StoreUrl;
use App\Support\TextoDeCorreo;
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

    public function __construct(Product $product, string $nombreCliente, ?ProductVariant $variante = null)
    {
        // FUN-29: el aviso sale cuando la reposición se guarda de verdad. Si se
        // encola dentro de una transacción que luego se deshace —la vista previa
        // de un import que repone stock—, no sale, sea cual sea la cola.
        $this->afterCommit();

        $tenant = $product->tenant;

        // El precio que se anuncia es el que veria en el catalogo: el de oferta
        // cuando existe. Es el mismo criterio que aplica OrderPricing al cobrar.
        // MOD-5: si esperaba una variante, el precio y el stock son los de ESA.
        $precio = $variante
            ? $variante->precioVisible()
            : ($product->sale_price !== null ? $product->sale_price : $product->price);

        $this->aviso = [
            'cliente'  => $nombreCliente,
            'producto' => $variante ? "{$product->name} ({$variante->nombre})" : $product->name,
            'tienda'   => $tenant?->name ?? 'la tienda',
            'precio'   => Money::format($precio, $tenant?->currency),
            // MOD-22: sin control de stock no hay unidades que contar (el aviso
            // sale al apagarlo), y el numero que quedo guardado no dice nada.
            'stock'    => $product->controlaStock() ? (int) ($variante ? $variante->stock : $product->stock) : null,
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
        // SEC-8: `cliente` es el nombre que dejo quien pidio el aviso, o sea un
        // dato del formulario publico.
        $producto = TextoDeCorreo::enLinea($this->aviso['producto']);
        $tienda = TextoDeCorreo::enLinea($this->aviso['tienda']);

        $mail = (new MailMessage)
            ->subject("Ya llegó: {$this->aviso['producto']}")
            ->greeting('Hola '.TextoDeCorreo::enLinea($this->aviso['cliente']).',')
            ->line("**{$producto}** volvió a estar disponible en {$tienda}, a {$this->aviso['precio']}.");

        // Decir cuantas quedan no es un adorno: quien espera una pieza agotada
        // necesita saber si tiene que correr o puede pensarselo.
        if ($this->aviso['stock'] !== null && $this->aviso['stock'] <= 3) {
            $mail->line("Quedan pocas unidades ({$this->aviso['stock']}).");
        }

        if ($this->aviso['url']) {
            $mail->action('Ver el producto', $this->aviso['url']);
        }

        if ($this->aviso['whatsapp']) {
            $mail->line('Si prefieres, escríbenos por WhatsApp al '.TextoDeCorreo::enLinea($this->aviso['whatsapp']).'.');
        }

        return $mail->line('Recibes este correo porque pediste que te avisáramos de este producto. No te escribiremos por nada más.');
    }
}
