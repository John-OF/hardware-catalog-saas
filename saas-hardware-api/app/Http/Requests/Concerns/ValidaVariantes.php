<?php

namespace App\Http\Requests\Concerns;

use App\Models\ProductVariant;
use Illuminate\Validation\Validator;

/**
 * Las variantes que llegan en el formulario de producto (MOD-5).
 *
 * Compartido por alta y edicion. El formulario es multipart (lleva fotos), asi
 * que `variants` llega como un string JSON y cada foto como
 * `variant_images[<posicion en la lista>]`.
 *
 * `variants` ausente significa "no toques las variantes" —una edicion que no
 * viene del formulario completo—; `variants` = `[]` significa "quitalas todas".
 */
trait ValidaVariantes
{
    protected function decodificarVariantes(): void
    {
        if (is_string($this->variants)) {
            $this->merge([
                'variants' => json_decode($this->variants, true) ?? [],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function reglasDeVariantes(): array
    {
        return [
            'variants' => 'nullable|array|max:'.ProductVariant::MAXIMO_POR_PRODUCTO,
            'variants.*.id' => 'nullable|uuid',
            // Hasta tres ejes ("Capacidad", "Color", "Velocidad"): mas que eso ya
            // no es una variante sino otro producto.
            'variants.*.options' => 'required|array|min:1|max:3',
            'variants.*.options.*.name' => 'required|string|max:50',
            'variants.*.options.*.value' => 'required|string|max:100',
            'variants.*.sku' => 'nullable|string|max:100',
            'variants.*.price' => 'required|numeric|min:0',
            'variants.*.sale_price' => 'nullable|numeric|min:0',
            'variants.*.stock' => 'required|integer|min:0',
            'variants.*.low_stock_threshold' => 'nullable|integer|min:0',
            'variants.*.remove_image' => 'nullable|boolean',
            'variant_images' => 'nullable|array',
            'variant_images.*' => 'image|mimes:jpeg,jpg,png,webp|max:10240',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensajesDeVariantes(): array
    {
        return [
            'variants.max' => 'Un producto puede tener como mucho '.ProductVariant::MAXIMO_POR_PRODUCTO.' variantes.',
            'variants.*.options.required' => 'Cada variante necesita al menos una opción (por ejemplo, Capacidad: 16 GB).',
            'variants.*.options.max' => 'Cada variante admite como mucho tres opciones.',
            'variants.*.options.*.name.required' => 'Falta el nombre de una opción (por ejemplo, "Capacidad").',
            'variants.*.options.*.value.required' => 'Falta el valor de una opción (por ejemplo, "16 GB").',
            'variants.*.price.required' => 'Cada variante necesita su precio.',
            'variants.*.stock.required' => 'Cada variante necesita su stock.',
        ];
    }

    /**
     * Dos variantes con las mismas opciones serian indistinguibles en la tienda:
     * el comprador veria dos botones "16 GB" con precios distintos.
     */
    protected function comprobarVariantesRepetidas(Validator $validator): void
    {
        $variantes = $this->input('variants');

        if (! is_array($variantes)) {
            return;
        }

        $vistas = [];

        foreach ($variantes as $posicion => $variante) {
            $clave = collect($variante['options'] ?? [])
                ->map(fn ($o) => mb_strtolower(trim((string) ($o['name'] ?? ''))).'='.mb_strtolower(trim((string) ($o['value'] ?? ''))))
                ->sort()
                ->implode('|');

            if ($clave === '') {
                continue;
            }

            if (isset($vistas[$clave])) {
                $validator->errors()->add(
                    "variants.{$posicion}.options",
                    'Hay dos variantes con las mismas opciones.',
                );
            }

            $vistas[$clave] = true;
        }
    }
}
