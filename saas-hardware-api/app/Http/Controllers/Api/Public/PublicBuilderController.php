<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\ComponentType;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Compatibilidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * La compatibilidad del armador de PC, calculada en el servidor (FUN-8, 13.5).
 *
 * El navegador manda que pieza eligio en cada paso y, con el cajon de un paso
 * abierto, los candidatos que esta viendo; aqui se responde con el veredicto
 * del armado, el consumo estimado y la etiqueta de cada candidato. Las reglas
 * viven enteras en `App\Support\Compatibilidad`: el navegador no tiene copia.
 *
 * GET porque no cambia nada. La respuesta trae dos veredictos del mismo
 * armado: `armado`, mientras se elige, y `al_pedir`, el del armado que se va a
 * pedir, donde lo que falta deja de ser "todavia" (ver la regla 14). Van juntos
 * y no en dos peticiones porque el de pedir se mira al pulsar "Pedir armado", y
 * abrir WhatsApp despues de esperar una respuesta es lo que el navegador
 * bloquea como ventana emergente.
 */
class PublicBuilderController extends Controller
{
    /** Los productos de una pagina del cajon, con margen. */
    private const MAX_CANDIDATOS = 50;

    public function compatibilidad(Request $request): JsonResponse
    {
        $pasos = array_map(fn (ComponentType $t) => $t->value, ComponentType::deArmador());

        $datos = $request->validate([
            'seleccion'    => ['nullable', 'array'],
            'seleccion.*'  => ['uuid'],
            'paso'         => ['nullable', 'required_with:candidatos', Rule::in($pasos)],
            'candidatos'   => ['nullable', 'array', 'max:'.self::MAX_CANDIDATOS],
            'candidatos.*' => ['uuid'],
        ]);

        $seleccion = $datos['seleccion'] ?? [];
        $otros = array_diff(array_keys($seleccion), $pasos);

        if ($otros !== []) {
            throw ValidationException::withMessages(['seleccion' => 'El armado no tiene ese paso: '.implode(', ', $otros).'.']);
        }

        $productos = $this->visibles([...array_values($seleccion), ...($datos['candidatos'] ?? [])]);

        // Lo elegido tiene que ser de su paso. Uno que ya no esta en el catalogo
        // —se agoto la publicacion mientras el comprador armaba— se trata como
        // no elegido y se devuelve en `no_disponibles`, para que la pantalla lo
        // diga en vez de tumbar el armador entero.
        $piezas = [];
        $noDisponibles = [];

        foreach ($seleccion as $paso => $id) {
            $producto = $productos->get($id);

            if ($producto === null) {
                $noDisponibles[] = $id;

                continue;
            }

            if ($producto->category?->component_type?->value !== $paso) {
                throw ValidationException::withMessages(["seleccion.{$paso}" => "«{$producto->name}» no es de este paso del armado."]);
            }

            $piezas[$paso] = $producto;
        }

        $armado = Compatibilidad::evaluar($piezas);
        $alPedir = Compatibilidad::evaluar($piezas, cerrar: true);

        $candidatos = [];

        if (! empty($datos['paso']) && ! empty($datos['candidatos'])) {
            // Los que no son de ese paso o ya no estan publicados no llevan
            // etiqueta: no se puede decir nada de ellos.
            $delPaso = collect($datos['candidatos'])
                ->map(fn (string $id) => $productos->get($id))
                ->filter(fn (?Product $p) => $p?->category?->component_type?->value === $datos['paso']);

            $candidatos = Compatibilidad::candidatos($piezas, $datos['paso'], $delPaso);
        }

        return response()->json([
            'armado'         => ['incidencias' => $armado['incidencias'], 'comprobadas' => $armado['comprobadas']],
            'al_pedir'       => ['incidencias' => $alPedir['incidencias'], 'comprobadas' => $alPedir['comprobadas']],
            'consumo'        => $armado['consumo'],
            'candidatos'     => (object) $candidatos,
            'no_disponibles' => $noDisponibles,
        ]);
    }

    /**
     * Lo que el catalogo publico ensena: de esta tienda (el scope de la ruta),
     * activo y publicado. Ni borradores ni desactivados entran en un armado.
     *
     * @param  list<string>  $ids
     * @return Collection<string, Product>
     */
    private function visibles(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', array_unique($ids))
            ->where('is_active', true)
            ->where('status', 'published')
            ->with('category:id,component_type')
            ->get(['id', 'name', 'specs', 'category_id'])
            ->keyBy('id');
    }
}
