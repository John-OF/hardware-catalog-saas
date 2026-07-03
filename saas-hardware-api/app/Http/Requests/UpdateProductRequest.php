<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El frontend envía specs como string JSON dentro de un FormData (multipart),
     * así que lo decodificamos a array antes de validar.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->specs)) {
            $this->merge([
                'specs' => json_decode($this->specs, true) ?? [],
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name'        => 'sometimes|string|max:300',
            'brand'       => 'nullable|string|max:100',
            'price'       => 'sometimes|numeric|min:0',
            'sale_price'  => 'nullable|numeric|min:0',
            'stock'       => 'sometimes|integer|min:0',
            'category_id' => 'nullable|uuid|exists:categories,id',
            'description' => 'nullable|string|max:5000',
            'specs'       => 'nullable|array',
            'image'             => 'nullable|image|mimes:jpeg,jpg,png,webp|max:10240',
            'gallery'           => 'nullable|array',
            'gallery.*'         => 'image|mimes:jpeg,jpg,png,webp|max:10240',
            'deleted_image_ids' => 'nullable',
            'is_active'         => 'nullable|boolean',
        ];
    }
}
