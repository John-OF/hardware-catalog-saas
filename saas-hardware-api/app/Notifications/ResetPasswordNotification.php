<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
 * AUD-11: va a la cola. Quien pide recuperar la contrasenia esperaba al SMTP
 * dentro de su peticion, y con un servidor de correo lento eso se veia como un
 * formulario colgado. Solo lleva el token, un string: nada que restaurar en el
 * worker, asi que no le afecta que alli no haya tienda resuelta (AUD-4).
 *
 * Requiere un worker corriendo (`php artisan queue:work`); ver `.env.example`.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
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
