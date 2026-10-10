<?php

namespace App\Notifications;

use App\Support\TextoDeCorreo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Invitacion al equipo de una tienda (FUN-4), con el texto de su rol (FUN-24).
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

        // SEC-8: este correo va a una direccion que escribe un admin, y el nombre
        // de quien invita y el de la tienda los escribe tambien el. Es el segundo
        // mas expuesto tras el aviso de pedido nuevo: lo recibe alguien que
        // todavia no tiene cuenta y que no puede comprobar nada.
        $invitadoPor = TextoDeCorreo::enLinea($this->invitadoPor);
        $tienda = TextoDeCorreo::enLinea($this->tienda);

        // FUN-24: el texto dice el rol de verdad. Antes decia "administrar" a
        // todos, tambien al colaborador, que al entrar veia menos de lo prometido.
        // Se lee del usuario y no se pasa al construir: encolada, la notificacion
        // vuelve a cargarlo de la base al enviarse, asi que un reenvio despues
        // de cambiarle el rol ya sale con el rol nuevo. Cualquier cosa que no sea
        // `admin` se trata como colaborador: si hay que equivocarse, que sea
        // prometiendo menos.
        $esAdmin = $notifiable->role === 'admin';

        $asunto = $esAdmin
            ? "{$this->invitadoPor} te invitó a administrar {$this->tienda}"
            : "{$this->invitadoPor} te invitó a colaborar en {$this->tienda}";

        $acceso = $esAdmin
            ? "{$invitadoPor} te dio acceso al panel de **{$tienda}** como **administrador**: puedes gestionarlo todo, también la configuración de la tienda y el equipo."
            : "{$invitadoPor} te dio acceso al panel de **{$tienda}** como **colaborador**, para el día a día: pedidos, productos, reseñas y la lista de espera.";

        return (new MailMessage)
            ->subject($asunto)
            ->greeting('Hola '.TextoDeCorreo::enLinea($notifiable->name).',')
            ->line($acceso)
            ->line('Para entrar solo falta que elijas tu contraseña.')
            ->action('Elegir mi contraseña', $url)
            ->line("Este enlace caduca en {$minutos} minutos. Si se te pasa, pide una nueva invitación o usa la opción de recuperar la contraseña con este mismo correo.")
            ->line('Si no esperabas esta invitación, puedes ignorar este correo: sin elegir contraseña no se puede entrar.')
            ->salutation('Un saludo, el equipo de '.config('app.name'));
    }
}
