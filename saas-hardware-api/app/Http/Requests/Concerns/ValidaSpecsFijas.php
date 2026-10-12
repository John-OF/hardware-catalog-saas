<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ComponentType;
use App\Models\Category;
use App\Support\SpecsFijas;
use Illuminate\Validation\Validator;

/**
 * Las specs con nombre fijo del tipo de la categoría (FUN-8, paso 13.3).
 *
 * Qué campos son fijos lo decide la CATEGORÍA con la que se va a guardar el
 * producto, no la que tenía: quien pasa un producto de "Sin categoría" a
 * "Procesadores" en el mismo guardado tiene que ver validado su socket.
 *
 * Se normaliza ANTES de validar ("am5" → "AM5", "105 W" → "105") para que lo
 * que se compruebe y lo que se guarde sea lo mismo. Un campo fijo ausente no es
 * un error: ver `SpecsFijas`.
 */
trait ValidaSpecsFijas
{
    private ComponentType|string|null $tipoDeLasSpecs = null;

    private bool $tipoDeLasSpecsResuelto = false;

    /** El id de la categoría con la que se va a guardar el producto. */
    abstract protected function categoriaConLaQueSeGuarda(): ?string;

    protected function normalizarSpecsFijas(): void
    {
        if (is_array($this->input('specs'))) {
            $this->merge(['specs' => SpecsFijas::normalizar($this->input('specs'), $this->tipoDeLasSpecs())]);
        }
    }

    protected function comprobarSpecsFijas(Validator $validator): void
    {
        if (! is_array($this->input('specs'))) {
            return;
        }

        foreach (SpecsFijas::errores($this->input('specs'), $this->tipoDeLasSpecs()) as $clave => $mensaje) {
            $validator->errors()->add("specs.{$clave}", $mensaje);
        }
    }

    private function tipoDeLasSpecs(): ComponentType|string|null
    {
        if (! $this->tipoDeLasSpecsResuelto) {
            $id = $this->categoriaConLaQueSeGuarda();

            // Si la categoría no es de esta tienda, el scope la deja fuera y no
            // hay tipo: la regla `category_id` ya rechaza la petición por su lado.
            $this->tipoDeLasSpecs = $id ? Category::query()->whereKey($id)->first()?->component_type : null;
            $this->tipoDeLasSpecsResuelto = true;
        }

        return $this->tipoDeLasSpecs;
    }
}
