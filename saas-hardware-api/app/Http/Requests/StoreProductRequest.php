<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaVariantes;
use App\Support\DeLaTienda;
use App\Support\Subidas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    use ValidaVariantes;

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

        $this->decodificarVariantes();
        $this->decodificarTramos();
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:300',
            'brand'       => 'nullable|string|max:100',
            // MOD-5: con variantes, precio y stock son de cada una, y los de la
            // ficha los calcula el backend (`sincronizarResumenDeVariantes`).
            // `nullable` porque un formulario multipart manda el campo vacío,
            // que llega como null; `required_without` sigue exigiéndolo cuando
            // no hay variantes, porque es una regla implícita y no se la salta.
            'price'       => 'nullable|required_without:variants|numeric|min:0',
            'sale_price'  => 'nullable|numeric|min:0',
            'stock'       => 'nullable|required_without:variants|integer|min:0',
            // MOD-6: el costo de compra. Lo valida todo el panel pero solo lo
            // guarda un admin: el controlador lo descarta para staff, para que
            // editar un producto sin ver el campo no lo borre.
            'cost'                => 'nullable|numeric|min:0',
            'sku'                 => 'nullable|string|max:100',
            'low_stock_threshold' => 'nullable|integer|min:0',
            // ACC-5: la categoria tiene que ser de ESTA tienda; `exists:` a secas
            // aceptaba la de cualquiera.
            'category_id'         => ['nullable', 'uuid', DeLaTienda::existe('categories')],
            'description'         => 'nullable|string|max:5000',
            'specs'               => 'nullable|array',
            // UI-15: tipo y tope de `config/subidas.php`, que es lo que avisa el navegador.
            'image'               => ['nullable', 'image', ...Subidas::reglas('imagen')],
            'gallery'             => 'nullable|array',
            'gallery.*'           => ['image', ...Subidas::reglas('imagen')],
            'is_active'           => 'nullable|boolean',
            'status'              => 'nullable|string|in:draft,published',
            ...$this->reglasDeVariantes(),
            // MOD-15: el precio por mayor de la ficha. Con variantes no se cobra
            // —cobra el de la variante—, pero se admite igual: quitarle las
            // variantes a un producto no tiene por que borrarle lo que negoció.
            ...$this->reglasDeTramos(),
        ];
    }

    public function messages(): array
    {
        return [...$this->mensajesDeVariantes(), ...$this->mensajesDeTramos()];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $this->comprobarVariantesRepetidas($v);
            $this->comprobarTramos($v);
        });
    }
}
