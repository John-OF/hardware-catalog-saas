<?php

namespace App\Notifications;

use App\Support\TextoDeCorreo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * El aviso de que los datos de una prueba abandonada se van a borrar (INF-24).
 *
 * Sale dos veces, a los administradores de la tienda: a los 60 días de cerrarse
 * la prueba (`$ultimo = false`) y una semana antes del borrado (`true`). Las dos
 * dicen la misma fecha, la que se fijó con el primero (`purge_scheduled_at`),
 * salvo que el último saliera tarde y la corriera hacia delante.
 *
 * Las fechas llegan ya escritas, en la zona de la tienda: el correo no consulta
 * nada al enviarse.
 *
 * **`NotTenantAware`**: lo manda `pruebas:purgar`, que corre sin tienda. Si fuera
 * tenant aware, spatie descartaría el trabajo en silencio —sin `failed_jobs`—, y
 * la purga borraría datos de alguien a quien nunca le llegó el aviso.
 *
 * Requiere un worker corriendo (`php artisan queue:work`); ver `.env.example`.
 */
class AbandonedTrialNotification extends Notification implements ShouldQueue, NotTenantAware
{
    use Queueable;

    public function __construct(
        public string $tienda,
        public string $finDeLaPrueba,
        public string $fechaDeBorrado,
        public bool $ultimo,
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
        // SEC-8: el nombre de la tienda lo escribe su dueño.
        $tienda = TextoDeCorreo::enLinea($this->tienda);
        $url = rtrim((string) config('app.frontend_url'), '/').'/login';

        $asunto = $this->ultimo
            ? "Último aviso: el {$this->fechaDeBorrado} se borran los datos de {$this->tienda}"
            : "Los datos de {$this->tienda} se borrarán el {$this->fechaDeBorrado}";

        $mensaje = (new MailMessage)
            ->subject($asunto)
            ->greeting('Hola '.TextoDeCorreo::enLinea($notifiable->name).',');

        if ($this->ultimo) {
            $mensaje
                ->line("Ya te escribimos hace unas semanas: la prueba gratuita de **{$tienda}** terminó el {$this->finDeLaPrueba} y la tienda sigue cerrada.")
                ->line("Si para el **{$this->fechaDeBorrado}** no has elegido un plan, borraremos sus productos con sus fotos, sus pedidos y su lista de clientes. Es el último aviso: después no se podrán recuperar.");
        } else {
            $mensaje
                ->line("La prueba gratuita de **{$tienda}** terminó el {$this->finDeLaPrueba} y la tienda sigue cerrada desde entonces.")
                ->line("Si para el **{$this->fechaDeBorrado}** no has elegido un plan, borraremos sus productos con sus fotos, sus pedidos y su lista de clientes. Después no se podrán recuperar.");
        }

        return $mensaje
            ->line('Tu cuenta y la tienda se quedan: si eliges un plan más adelante, vuelve a abrirse, pero vacía.')
            ->action('Elegir un plan', $url)
            ->line('Si ya no vas a usarla, no tienes que hacer nada.')
            ->salutation('Un saludo, el equipo de '.config('app.name'));
    }
}
