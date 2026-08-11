<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo de recuperacion de contrasenia del panel (SAAS-2).
 *
 * Reemplaza a la notificacion nativa de Laravel por dos motivos: el texto va en
 * espaniol (el locale de la app es 'en' y no hay carpeta lang/) y el enlace
 * apunta al SPA, no a una ruta Blade de la API — aqui el frontend es una app
 * aparte, asi que Laravel no tiene ninguna pantalla de reset que ofrecer.
 *
 * Deliberadamente NO implementa ShouldQueue: QUEUE_CONNECTION=database y no hay
 * worker corriendo, asi que encolarla dejaria el correo sin enviar en silencio.
 */
class ResetPasswordNotification extends Notification
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
        // El email viaja en la URL porque el broker de Laravel lo exige de vuelta
        // para resolver al usuario: el token por si solo no identifica a nadie.
        $url = rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutos = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Recupera el acceso a tu tienda')
            ->greeting("Hola {$notifiable->name},")
            ->line('Recibimos una solicitud para restablecer la contrasenia de tu panel de administracion.')
            ->action('Elegir nueva contrasenia', $url)
            ->line("Este enlace caduca en {$minutos} minutos y solo puede usarse una vez.")
            ->line('Si no fuiste tu, puedes ignorar este correo: tu contrasenia actual sigue funcionando.')
            ->salutation('Un saludo, el equipo de '.config('app.name'));
    }
}
