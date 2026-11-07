<?php

namespace App\Http\Requests\Concerns;

/**
 * Si el producto que se guarda lleva la cuenta de sus unidades (MOD-22).
 *
 * Compartido por alta y edicion, y lo usan las reglas del stock de la ficha y
 * de cada variante: sin control de stock, el numero no hace falta.
 *
 * Llega por `FormData`, asi que como `'1'`/`'0'` (README, booleanos en
 * FormData). Ausente es "como estaba": en el alta, que si la lleva —lo que era
 * todo antes de esto—; en la edicion, lo que tenga el producto.
 */
trait ValidaControlDeStock
{
    protected function controlaStock(): bool
    {
        $valor = $this->input('track_stock');

        if ($valor !== null && $valor !== '') {
            return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
        }

        return $this->route('product')?->controlaStock() ?? true;
    }
}
