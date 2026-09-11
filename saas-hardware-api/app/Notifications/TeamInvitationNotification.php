<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Invitacion a administrar una tienda (FUN-4).
 *
 * **Reutiliza el token del broker de recuperacion de contrasenia**, no inventa
 * uno propio. El invitado no tiene contrasenia todavia, asi que lo que necesita
 * es exactamente lo que ya hace `SAAS-2`: un enlace firmado y caducable que abre
 * la pantalla de elegir contrasenia del SPA. Montar un segundo sistema de tokens
 * -su tabla, su caducidad, su limpieza- para la misma operacion habria sido dos
 * cosas que mantener y una de ellas sin probar.
 *
 * Lo unico que cambia es el texto: a quien recupera su acceso se le habla de
 * "restablecer", y a quien acaba de ser invitado hay que decirle **quien** le
 * invito y **a que tienda**, porque no estaba esperando este correo.
 *
 * **`NotTenantAware` por la leccion de `FUN-10`.** No es que aqui falte la
 * tienda -esto sale del panel, que si la resuelve-, es que la notificacion no
 * necesita ninguna: lleva un token, dos cadenas de texto y un usuario, y no
 * consulta nada scopeado. Marcarla tenant aware solo anadiria una dependencia
 * -que la tienda siga existiendo cuando el worker recoja el trabajo- a cambio de
 * nada. Y el modo en que eso falla ya lo conocemos: el trabajo se borra sin
 * pasar por `failed_jobs` y el correo no llega nunca, en silencio.
 *
 * Requiere un worker corriendo (`php artisan queue:work`); ver `.env.example`.
 */
class TeamInvitationNotification extends Notification implements ShouldQueue, NotTenantAware
{
    use Queueable;

    public function __construct(
        public string $token,
        public string $tienda,
        public string $invitadoPor,
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
        // Mismo destino que la recuperacion: la pantalla del SPA que fija la
        // contrasenia. El correo viaja en la URL porque el broker lo exige de
        // vuelta para resolver al usuario.
        $url = rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutos = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject("{$this->invitadoPor} te invito a administrar {$this->tienda}")
            ->greeting("Hola {$notifiable->name},")
            ->line("{$this->invitadoPor} te dio acceso al panel de administracion de **{$this->tienda}**.")
            ->line('Para entrar solo falta que elijas tu contrasenia.')
            ->action('Elegir mi contrasenia', $url)
            ->line("Este enlace caduca en {$minutos} minutos. Si se te pasa, pide una nueva invitacion o usa la opcion de recuperar contrasenia con este mismo correo.")
            ->line('Si no esperabas esta invitacion, puedes ignorar este correo: sin elegir contrasenia no se puede entrar.')
            ->salutation('Un saludo, el equipo de '.config('app.name'));
    }
}
