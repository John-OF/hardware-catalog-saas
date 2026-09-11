<?php

namespace App\Http\Requests;

use App\Enums\ComponentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'       => 'required|string|max:100',
            'icon'       => 'nullable|string|max:50',
            // FUN-8: opcional a propósito. Quien no lo mande —un cliente de la
            // API anterior a esta columna— no se queda sin tipo: el modelo lo
            // deduce del nombre al crear. Lo que no se acepta es un valor
            // inventado, que dejaría la categoría fuera del armador para
            // siempre sin que nadie lo notara.
            'component_type' => ['nullable', Rule::enum(ComponentType::class)],
            'sort_order' => 'nullable|integer|min:0',
            'is_active'  => 'nullable|boolean',
        ];
    }
}
