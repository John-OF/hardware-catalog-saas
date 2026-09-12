<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Sesión de soporte: el operador entrando en una tienda ajena (INF-2).
 *
 * El operador no tiene cuenta en la tienda, así que "entrar como soporte" es
 * emitir un token del admin de esa tienda. Eso es una llave prestada, y por eso
 * lleva tres cerrojos:
 *
 * 1. **Caduca en 15 minutos** (`MINUTOS`). Es para mirar algo concreto, no para
 *    trabajar dentro.
 * 2. **Solo lee.** El token no puede escribir: lo impide `RestrictImpersonation`,
 *    que rechaza todo lo que no sea GET/HEAD en el panel. Un soporte que puede
 *    borrar productos ajenos es un incidente esperando a pasar, y además nadie
 *    podría distinguir después lo que rompió el operador de lo que rompió el
 *    dueño.
 * 3. **Queda anotado** en la bitácora antes de emitirse (`ActivityLog`).
 *
 * La comprobación mira las abilities **una a una** y no con `tokenCan()` a
 * propósito: `tokenCan('...')` devuelve `true` para cualquier cosa si el token
 * tiene la ability comodín `*`, y eso convertiría a un token todopoderoso en
 * uno de solo lectura sin que nadie lo hubiera pedido. Aquí la pregunta es la
 * contraria —"¿es EXACTAMENTE una sesión de soporte?"— y solo la responde bien
 * mirar la lista.
 */
class Suplantacion
{
    /** Ability que marca el token como sesión de soporte. */
    public const ABILITY = 'soporte';

    /** Lo que dura la llave prestada. */
    public const MINUTOS = 15;

    public static function activa(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        // Con la sesión por cookie de Sanctum aquí llega un `TransientToken`,
        // que NO es un modelo y no tiene ni abilities ni `getAttribute()`.
        // Comprobarlo por modelo y no por clase concreta deja esto funcionando
        // aunque el proyecto cambie el modelo de token de Sanctum.
        if (! $token instanceof Model) {
            return false;
        }

        return in_array(self::ABILITY, (array) $token->getAttribute('abilities'), true);
    }
}
