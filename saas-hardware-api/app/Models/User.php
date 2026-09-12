<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Concerns\BelongsToTenant;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids, BelongsToTenant;

    /**
     * `User` es la excepcion al fallo en cerrado de AUD-4, y conviene entender
     * por que antes de copiar esto en otro modelo.
     *
     * Es el problema del huevo y la gallina: para saber QUE tienda es esta hace
     * falta a veces saber QUIEN eres, y `auth:sanctum` resuelve al usuario a
     * partir del token ANTES de que ningun middleware haya resuelto la tienda
     * —`InitializeTenantByHeader` incluso consulta `Auth::check()` para su propia
     * comprobacion de pertenencia—. Con el fallo en cerrado, esa consulta no
     * devolveria a nadie y el panel entero respondaria 401.
     *
     * Ademas hay caminos legitimamente sin tienda que van contra `users`: el
     * login del panel, el alta de tienda, el super-admin de plataforma y el
     * comando que lo crea.
     *
     * Lo que protege a `users` no es este scope, es que cada consulta filtra por
     * tienda a mano y hay tests que lo fijan: el email es unico por tienda
     * (`SEC-4`), el login discrimina por rol (`SEC-1`) y un token de cliente solo
     * vale en su tienda (`AUD-3`).
     */
    protected static function veTodoSinTenant(): bool
    {
        return true;
    }

    /**
     * Los roles que entran al panel de una tienda (FUN-4).
     *
     * `admin` lo puede todo; `staff` lleva el dia a dia -pedidos, productos,
     * resenas, lista de espera- pero no la configuracion, las categorias, las
     * paginas, el plan ni el equipo. El reparto de rutas esta en `routes/api.php`.
     *
     * Vive aqui porque se consulta en muchos sitios (login, reset, middleware,
     * tope del plan, equipo, aviso de pedido nuevo) y una lista copiada a mano
     * en cada uno es un rol que alguien olvidara anadir en alguno.
     */
    public const ROLES_DE_PANEL = ['admin', 'staff'];

    public function esDelPanel(): bool
    {
        return in_array($this->role, self::ROLES_DE_PANEL, true);
    }

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = [
        'tenant_id', 'name', 'email', 'phone', 'password', 'role', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'is_active'         => 'boolean',
        'password'          => 'hashed',    // bcrypt automático en Laravel 10+
    ];

    /**
     * Enviar el correo de recuperacion de contrasenia (SAAS-2).
     *
     * Se sobreescribe la nativa de Laravel porque su enlace apunta a una ruta
     * Blade que aqui no existe: el panel es un SPA aparte.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }

    /**
     * Enviar el correo de verificacion del alta (FUN-5).
     *
     * Mismo motivo que arriba para no usar la nativa de Laravel, y ademas la suya
     * no es `NotTenantAware`, que aqui es literalmente la diferencia entre enviar
     * y no enviar; el porque esta escrito en la notificacion.
     *
     * **`MustVerifyEmail` esta en la clase, pero solo se le envia a los admins.**
     * `users` guarda tambien a los clientes de cada tienda, que se registran en el
     * catalogo publico (`PublicAuthController`) para guardar favoritos y ver sus
     * pedidos; a esos no se les pide verificar nada, porque el correo no les abre
     * ninguna puerta y la friccion se pagaria en ventas. El contrato no los afecta
     * por si solo: nadie dispara el evento `Registered` en este proyecto, asi que
     * el listener automatico de Laravel no llega a correr nunca y el unico envio
     * es la llamada explicita de `AuthController::register`.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\VerifyEmailNotification());
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function favorites(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'user_favorites')->withTimestamps();
    }
}
