<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Correo de verificacion del alta de tienda (FUN-5).
 *
 * Reemplaza a la nativa de Laravel por los mismos dos motivos que
 * `ResetPasswordNotification`: el texto va en espaniol (el locale de la app es
 * 'en' y no hay carpeta lang/) y aqui no hay pantallas Blade que ofrecer.
 *
 * **`NotTenantAware` no es decorativo, y esta vez se sabe antes de romperlo.**
 * `config/multitenancy.php` trae `queues_are_tenant_aware_by_default => true`, asi
 * que spatie exige un `tenantId` en el payload de todo trabajo encolado. Este
 * correo sale de `POST /api/auth/register`, que **no lleva middleware de tenant**
 * —la tienda se acaba de crear en ese mismo metodo, pero nadie la ha hecho
 * `current()`—, o sea el mismo escenario exacto que dejo la recuperacion de
 * contrasenia sin enviar durante meses (FUN-10). Alli el trabajo se borraba **sin
 * pasar por `failed_jobs`**: ni correo ni rastro. Si esta linea desaparece, el alta
 * de tienda deja de enviar nada y falla igual de callada.
 *
 * De fondo tambien es lo correcto: manda un enlace firmado a un usuario concreto y
 * no consulta nada scopeado. `User` es justo el modelo que puede leerse sin tienda
 * resuelta, y el porque esta escrito en el modelo.
 *
 * **El enlace apunta a la API y no al SPA, al reves que el de recuperacion.** Es la
 * diferencia entre los dos flujos: recuperar exige un formulario —hay que teclear
 * la contrasenia nueva—, asi que el enlace tiene que abrir una pantalla y el token
 * viaja como parametro. Verificar no pide nada al usuario: pinchar ES la accion. Si
 * el enlace abriera el SPA para que este llamara a la API, habria que arrastrar la
 * firma intacta por el camino y una sola diferencia de codificacion la invalidaria.
 * Asi la firma la comprueba quien la genero, y la API redirige al SPA despues.
 *
 * Requiere un worker corriendo (`php artisan queue:work`); ver `.env.example`.
 */
class VerifyEmailNotification extends Notification implements ShouldQueue, NotTenantAware
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);

        $minutos = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Confirma tu correo y publica tu tienda')
            ->greeting("Hola {$notifiable->name},")
            ->line('Gracias por crear tu tienda. Solo falta confirmar que este correo es tuyo.')
            ->line('**Hasta que lo confirmes tu catalogo no es visible para el publico.** Puedes entrar al panel y dejarlo todo listo mientras tanto: productos, categorias y personalizacion se guardan igual.')
            ->action('Confirmar mi correo', $url)
            ->line("Este enlace caduca en {$minutos} minutos. Si caduca, puedes pedir otro desde el panel.")
            ->line('Si no creaste ninguna tienda, ignora este correo.')
            ->salutation('Un saludo, el equipo de '.config('app.name'));
    }

    /**
     * El hash del correo es lo que invalida el enlace si el usuario cambia de
     * direccion: la firma sola seguiria siendo valida y el enlace verificaria una
     * cuenta cuyo correo ya no es el que se comprobo. Es el mismo mecanismo que usa
     * el `VerifyEmail` nativo de Laravel.
     */
    private function verificationUrl(object $notifiable): string
    {
        return URL::temporarySignedRoute(
            'verificacion.correo',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id'   => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }
}
