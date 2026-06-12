<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => 'sometimes|string|max:300',
            'brand'       => 'nullable|string|max:100',
            'price'       => 'sometimes|numeric|min:0',
            'stock'       => 'sometimes|integer|min:0',
            'category_id' => 'nullable|uuid|exists:categories,id',
            'description' => 'nullable|string|max:5000',
            'specs'       => 'nullable|array',
            'image'       => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            'is_active'   => 'nullable|boolean',
        ];
    }
}
