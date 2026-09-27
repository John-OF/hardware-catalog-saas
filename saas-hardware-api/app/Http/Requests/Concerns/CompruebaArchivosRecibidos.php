<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;

/**
 * Que llegaron todas las fotos que el formulario mandó (`INF-12`).
 *
 * PHP descarta **en silencio** los archivos de una petición que pasan de
 * `max_file_uploads` (20 de fábrica): no hay error, ni campo vacío, ni nada en
 * `$_FILES` que diga que faltan. Y un solo guardado de producto puede llevar
 * la foto principal, la galería (8 en Pro, sin tope en Enterprise) y una por
 * variante (hasta 30): el producto se guardaba con menos fotos de las que el
 * dueño eligió, sin que nadie se enterase.
 *
 * El formulario manda cuántos archivos adjuntó (`archivos_enviados`) y aquí se
 * cuentan los que llegaron. Si faltan, el guardado entero se rechaza —nada a
 * medias— diciendo cuántos llegaron, y el log lo deja escrito para quien
 * despliega. Sin el campo no se comprueba nada: el que lo manda es el panel.
 */
trait CompruebaArchivosRecibidos
{
    protected function reglasDeArchivosRecibidos(): array
    {
        return ['archivos_enviados' => 'nullable|integer|min:0'];
    }

    protected function comprobarArchivosRecibidos(Validator $validator): void
    {
        if (! $this->filled('archivos_enviados')) {
            return;
        }

        $enviados = (int) $this->input('archivos_enviados');
        $recibidos = count(Arr::flatten($this->allFiles()));

        if ($recibidos >= $enviados) {
            return;
        }

        $tope = (int) ini_get('max_file_uploads');

        Log::warning('Llegaron menos archivos de los que se enviaron', [
            'enviados' => $enviados,
            'recibidos' => $recibidos,
            'max_file_uploads' => $tope,
            'ruta' => $this->path(),
        ]);

        $validator->errors()->add('archivos_enviados', $recibidos === $tope
            ? "Solo llegaron {$recibidos} de las {$enviados} fotos: el servidor admite {$tope} por envío. "
                .'Guarda con menos fotos nuevas y añade el resto editando el producto.'
            : "Solo llegaron {$recibidos} de las {$enviados} fotos. Vuelve a elegirlas y guarda de nuevo.");
    }
}
