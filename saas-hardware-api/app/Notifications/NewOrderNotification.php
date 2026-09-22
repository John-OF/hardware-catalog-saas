<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use App\Support\TextoDeCorreo;
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
            // FUN-3: el correlativo de la tienda, que es el mismo que enseña el
            // panel y el que el cliente lleva en su mensaje de WhatsApp. Antes era
            // un trozo del UUID: casaba con la tabla del panel, pero no había forma
            // de decirlo en voz alta ni de buscarlo.
            'referencia' => '#'.$order->number,
            'cliente' => $order->customer_name,
            'telefono' => $order->customer_phone,
            'nota' => $order->customer_note,
            // MOD-1: null en la venta de mostrador y en lo de antes de este
            // cambio, así que el correo no dice nada si no hay nada que decir.
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

    /**
     * SEC-8: todo lo que viene de fuera pasa por `TextoDeCorreo` antes de entrar
     * en una linea, porque estas lineas se renderizan como Markdown. Este es el
     * correo mas expuesto de los ocho: el nombre, el telefono, la nota y la
     * entrega los escribe **cualquiera** en el checkout publico, y el correo lo
     * recibe el dueno desde el servidor de su propia tienda. El Markdown nuestro
     * -las negritas, el `---`- se queda fuera del escapado a proposito: se
     * escapa el dato interpolado, nunca la linea.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $cliente = TextoDeCorreo::enLinea($this->pedido['cliente']);

        $mail = (new MailMessage)
            ->subject("Pedido nuevo {$this->pedido['referencia']} por {$this->pedido['total']}")
            ->greeting('Hola '.TextoDeCorreo::enLinea($notifiable->name).',')
            ->line("**{$cliente}** acaba de hacer un pedido en tu tienda.")
            ->line('Telefono: '.TextoDeCorreo::enLinea($this->pedido['telefono']));

        if ($this->pedido['nota']) {
            // `enParrafo` y no `enLinea`: una nota si puede traer varias lineas de
            // verdad, y aplastarlas seria perder lo que el cliente escribio.
            $mail->line('Nota del cliente: '.TextoDeCorreo::enParrafo($this->pedido['nota']));
        }

        if ($this->pedido['entrega']) {
            $mail->line('Entrega: '.TextoDeCorreo::enLinea($this->pedido['entrega']));
        }

        $mail->line('---');

        foreach ($this->pedido['lineas'] as $linea) {
            $producto = TextoDeCorreo::enLinea($linea['producto']);
            $mail->line("{$linea['cantidad']} x {$producto} — {$linea['subtotal']}");
        }

        return $mail
            ->line("**Total: {$this->pedido['total']}**")
            ->action('Ver el pedido en el panel', rtrim(config('app.frontend_url'), '/').'/dashboard/orders')
            ->line('El pedido queda en estado pendiente hasta que lo atiendas desde el panel.');
    }
}
