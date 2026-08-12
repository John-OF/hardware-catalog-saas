<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de un usuario (TEC-4).
 *
 * Antes los endpoints devolvían el modelo crudo, así que la respuesta crecía
 * sola con cada columna nueva: `tenant_id`, `last_login_at`, `email_verified_at`
 * y los timestamps viajaban al navegador sin que nadie los usara. Con la lista
 * explícita, añadir una columna a `users` ya no la publica sin querer.
 *
 * `password` y `remember_token` nunca salían ($hidden en el modelo); esto es la
 * capa de encima.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'email'     => $this->email,
            'phone'     => $this->phone,
            // El panel los usa para decidir qué mostrar.
            'role'      => $this->role,
            'is_active' => $this->is_active,
        ];
    }
}
