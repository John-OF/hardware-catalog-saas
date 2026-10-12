<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\CompruebaArchivosRecibidos;
use App\Http\Requests\Concerns\ValidaSpecsFijas;
use App\Http\Requests\Concerns\ValidaVariantes;
use App\Support\DeLaTienda;
use App\Support\Subidas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    use CompruebaArchivosRecibidos;
    use ValidaSpecsFijas;
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

        // FUN-8: despues de decodificar y antes de validar. Ver `ValidaSpecsFijas`.
        $this->normalizarSpecsFijas();

        $this->decodificarVariantes();
        $this->decodificarTramos();
    }

    /**
     * La que llega si el guardado la trae —también vacía: "Sin categoría"—, y
     * si no, la que ya tenía el producto.
     */
    protected function categoriaConLaQueSeGuarda(): ?string
    {
        if ($this->exists('category_id')) {
            return $this->filled('category_id') ? (string) $this->input('category_id') : null;
        }

        return $this->route('product')?->category_id;
    }

    public function rules(): array
    {
        return [
            'name'        => 'sometimes|string|max:300',
            'brand'       => 'nullable|string|max:100',
            // MOD-5: vacío solo vale si llegan variantes (ver StoreProductRequest).
            'price'       => 'sometimes|nullable|required_without:variants|numeric|min:0',
            'sale_price'  => 'nullable|numeric|min:0',
            'stock'       => 'sometimes|nullable|required_without:variants|integer|min:0',
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
            // INF-12: ver StoreProductRequest.
            ...$this->reglasDeArchivosRecibidos(),
            'deleted_image_ids'   => 'nullable',
            'is_active'           => 'nullable|boolean',
            'status'              => 'nullable|string|in:draft,published',
            ...$this->reglasDeVariantes(),
            // MOD-15: ver StoreProductRequest.
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
            $this->comprobarSpecsFijas($v);
            $this->comprobarArchivosRecibidos($v);
        });
    }
}
