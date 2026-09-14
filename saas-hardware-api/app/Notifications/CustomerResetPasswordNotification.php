<?php

namespace App\Notifications;

use App\Support\StoreUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Correo de recuperación de contraseña de un CLIENTE del catálogo (FUN-11).
 *
 * Hermana de `ResetPasswordNotification`, que es la del panel. Hacían falta dos
 * clases y no una con un `if` porque el enlace y el texto son distintos de
 * verdad: éste apunta al catálogo de la tienda, no al SPA del panel, y "tu
 * panel de administración" no significa nada para quien sólo compra ahí.
 * `User::sendPasswordResetNotification()` es el único sitio que decide cuál de
 * las dos mandar, mirando el rol.
 *
 * **`NotTenantAware`, igual que la del panel y por el mismo motivo:** sólo
 * lleva un token, nada que consultar con tienda resuelta. La tienda del
 * enlace sale de `$notifiable->tenant` —la relación de `BelongsToTenant`,
 * una consulta normal por `tenant_id`, no del scope de AUD-4—, así que
 * funciona igual la lleve o no.
 *
 * AUD-11: va a la cola, por lo mismo que la del panel.
 */
class CustomerResetPasswordNotification extends Notification implements ShouldQueue, NotTenantAware
{
    use Queueable;

    public function __construct(
        public string $token,
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
        // FUN-6: `StoreUrl::forTenant` ya resuelve el dominio propio SÓLO si
        // está verificado y si no, cae al catálogo con slug — nunca un enlace
        // que la propia plataforma sabe que no sirve.
        //
        // Nombres de query distintos a los del panel (`reset_token`/
        // `reset_email` en vez de `token`/`email`) a propósito: el catálogo es
        // una sola SPA con varias pantallas —el modal de cuenta puede abrirse
        // desde cualquiera de ellas—, así que el nombre tiene que decir de qué
        // va sin ambigüedad frente a cualquier otro parámetro de la URL.
        $url = StoreUrl::forTenant($notifiable->tenant).'?'.http_build_query([
            'reset_token' => $this->token,
            'reset_email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutos = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Recupera el acceso a tu cuenta')
            ->greeting("Hola {$notifiable->name},")
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->action('Elegir nueva contraseña', $url)
            ->line("Este enlace caduca en {$minutos} minutos y sólo puede usarse una vez.")
            ->line('Si no fuiste tú, puedes ignorar este correo: tu contraseña actual sigue funcionando.')
            ->salutation('Un saludo, el equipo de '.config('app.name'));
    }
}
