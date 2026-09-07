<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

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
 * **`NotTenantAware` no es decorativo: sin el, este correo NO SE ENVIA NUNCA.**
 * `config/multitenancy.php` trae `queues_are_tenant_aware_by_default => true`, asi
 * que spatie exige que todo trabajo encolado lleve un `tenantId` en su payload y lo
 * hace fallar si no lo tiene. Y esta notificacion sale de
 * `POST /api/auth/forgot-password`, que **a proposito no lleva middleware de
 * tenant**: quien la usa es justamente quien no puede entrar. Resultado: payload sin
 * `tenantId` y el trabajo revienta en el worker.
 *
 * Lo grave era como fallaba. El trabajo se borraba **sin llegar a `failed_jobs`**: el
 * dueno veia "te enviamos un correo", no le llegaba nada, y en la bandeja de fallidos
 * no habia rastro; solo quedaba una linea de ERROR en el log. Se descubrio el
 * 2026-09-07 al levantar por fin el worker —el pendiente de verificacion local de
 * `mejoras_propuestas.md`— y no antes, porque con `QUEUE_CONNECTION=sync` el correo
 * sale bien: sin cola no hay payload que rellenar. Fallaba solo en la configuracion
 * de produccion.
 *
 * Que no sea tenant aware es ademas lo correcto de fondo: manda un enlace con un
 * token a un usuario concreto y no consulta nada scopeado. `User` es justo el modelo
 * que puede leerse sin tienda resuelta, y el porque esta escrito en el modelo.
 *
 * Requiere un worker corriendo (`php artisan queue:work`); ver `.env.example`.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue, NotTenantAware
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
