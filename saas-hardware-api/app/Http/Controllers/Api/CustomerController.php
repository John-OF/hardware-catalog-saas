<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Bitacora;
use App\Support\CeldaCsv;
use App\Support\Directorio;
use App\Support\Paginacion;
use App\Support\PlanGate;
use App\Support\Subidas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Los clientes de la tienda y lo que le han comprado (MOD-10, MOD-24).
 *
 * Desde MOD-24 la lista es el **directorio** de la tienda (`customers`): cada
 * persona que le compró —con cuenta o sin ella, por el catálogo o en el
 * mostrador— y cada una que abrió cuenta, juntadas por teléfono o correo
 * (`App\Support\Directorio`). Antes solo salían las cuentas registradas, y como
 * el checkout no pide cuenta, una tienda que vende por WhatsApp veía la lista
 * casi vacía aunque tuviera ventas.
 *
 * La tienda no crea ni borra personas desde aquí: aparecen solas con sus
 * pedidos. Lo que sí escribe es **lo suyo**: notas internas y etiquetas, que no
 * ve nunca el cliente. Los datos de contacto son los que dio el cliente al
 * comprar o al abrir su cuenta.
 *
 * El modelo `Customer` falla en cerrado como el resto (`AUD-4`), así que estas
 * consultas solo ven la tienda resuelta. Los totales se cuentan desde
 * `orders.customer_id`.
 */
class CustomerController extends Controller
{
    /**
     * Orden de la lista. `recientes` es el que trae la pantalla por defecto
     * (quién llegó último); `gasto` y `pedidos` son la pregunta que de verdad se
     * hace el dueño, que es quién le compra más.
     */
    private const ORDENES = ['recientes', 'gasto', 'pedidos'];

    /** Filas que admite un CSV de clientes, como el de productos (AUD-10). */
    private const MAX_FILAS_CSV = 2000;

    private const MODOS_DE_IMPORT = ['omitir', 'actualizar'];

    public function index(Request $request): JsonResponse
    {
        $orden = in_array($request->sort, self::ORDENES, true) ? $request->sort : 'recientes';

        $clientes = $this->base()
            ->when($request->filled('search'), function ($q) use ($request) {
                $termino = trim($request->search);

                $q->where(function ($sub) use ($termino) {
                    $sub->where('customers.name', 'like', "%{$termino}%")
                        ->orWhere('customers.email', 'like', "%{$termino}%")
                        ->orWhere('customers.phone', 'like', "%{$termino}%");
                });
            })
            ->when($request->filled('etiqueta'), fn ($q) => $q->whereJsonContains('customers.tags', (string) $request->etiqueta))
            ->when($request->cuenta === 'con', fn ($q) => $q->whereNotNull('customers.user_id'))
            ->when($request->cuenta === 'sin', fn ($q) => $q->whereNull('customers.user_id'))
            ->when($orden === 'recientes', fn ($q) => $q->orderByDesc('customers.created_at'))
            // Los que nunca han comprado quedan al final en los dos ordenes de
            // ventas, no fuera: un cliente registrado que no ha comprado todavia
            // es justo al que el dueño querria escribirle.
            ->when($orden === 'gasto', fn ($q) => $q->orderByDesc('total_gastado')->orderByDesc('customers.created_at'))
            ->when($orden === 'pedidos', fn ($q) => $q->orderByDesc('pedidos_count')->orderByDesc('customers.created_at'))
            ->paginate(Paginacion::porPagina($request, 20));

        return response()->json($clientes->through(fn (Customer $cliente) => $this->conTotales($cliente)));
    }

    /**
     * Las etiquetas que usa la tienda, para el filtro y para sugerirlas al
     * escribir. Sin repetir y en orden alfabético.
     */
    public function etiquetas(): JsonResponse
    {
        $etiquetas = Customer::query()
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->filter(fn ($e) => is_string($e) && $e !== '')
            ->unique(fn ($e) => mb_strtolower($e))
            ->sort(fn ($a, $b) => strcasecmp($a, $b))
            ->values();

        return response()->json($etiquetas);
    }

    /**
     * La ficha: los mismos totales de la lista y su historial de compras.
     */
    public function show(string $customer): JsonResponse
    {
        $cliente = $this->base()->where('customers.id', $customer)->firstOrFail();

        // Sin `with` en la consulta de arriba: los pedidos se piden aparte para
        // poder limitarlos. Un cliente fiel puede tener cientos y la ficha no es
        // un listado de pedidos, es un resumen con lo ultimo.
        $pedidos = Order::where('customer_id', $cliente->id)
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'number', 'status', 'total', 'created_at']);

        return response()->json([
            'customer' => $this->conTotales($cliente),
            'orders'   => $pedidos,
        ]);
    }

    /**
     * Las notas y las etiquetas de una persona: lo único que la tienda escribe
     * del directorio. Los datos de contacto no se tocan desde aquí.
     */
    public function update(Request $request, string $customer): JsonResponse
    {
        $cliente = Customer::query()->where('id', $customer)->firstOrFail();

        $data = $request->validate([
            'notes' => 'sometimes|nullable|string|max:2000',
            'tags' => 'sometimes|nullable|array|max:'.Customer::MAXIMO_DE_ETIQUETAS,
            'tags.*' => 'nullable|string|max:'.Directorio::LARGO_DE_ETIQUETA,
        ]);

        $antes = ['notes' => $cliente->notes, 'tags' => $cliente->tags ?? []];

        if (array_key_exists('notes', $data)) {
            $notas = trim((string) $data['notes']);
            $cliente->notes = $notas === '' ? null : $notas;
        }

        if (array_key_exists('tags', $data)) {
            $etiquetas = Directorio::etiquetas($data['tags']);
            $cliente->tags = $etiquetas === [] ? null : $etiquetas;
        }

        if ($cliente->isDirty()) {
            $cliente->save();

            $que = [];
            if ($antes['notes'] !== $cliente->notes) {
                $que[] = 'las notas';
            }
            if ($antes['tags'] !== ($cliente->tags ?? [])) {
                $que[] = 'las etiquetas ('.(implode(', ', $cliente->tags ?? []) ?: 'ninguna').')';
            }

            // Las notas se nombran y no se copian: son de uso interno y la
            // bitácora la lee más gente que la ficha.
            Bitacora::anotar(
                ActivityLog::CLIENTE_EDITADO,
                'Cambió '.implode(' y ', $que)." de {$cliente->name}.",
                ['cliente_id' => $cliente->id],
            );
        }

        return $this->show($cliente->id);
    }

    /**
     * Trae personas al directorio desde un CSV: la libreta de contactos que el
     * negocio ya tenía (MOD-24).
     *
     * Columnas: `nombre`, `telefono`, `correo`, `etiquetas` (separadas por `|`)
     * y `notas`; las mismas que escribe la exportación, así que exportar y
     * volver a subir no cambia nada. Cada fila necesita teléfono o correo: es lo
     * que la identifica. Quien ya está —por las mismas reglas que un pedido— se
     * `omitir` (por defecto, el único repetible sin pensar) o se `actualizar`:
     * una celda vacía no borra nada, como en el import de productos (FUN-17).
     */
    public function import(Request $request): JsonResponse
    {
        // La importación por CSV es una función del plan, la misma que la de
        // productos (SAAS-3): va antes de mirar el archivo.
        PlanGate::ensureAllows('csv_import');

        $validado = $request->validate([
            'file' => ['required', 'file', ...Subidas::reglas('csv')],
            'modo' => 'nullable|in:'.implode(',', self::MODOS_DE_IMPORT),
        ]);

        $modo = $validado['modo'] ?? 'omitir';
        $ruta = $request->file('file')->getRealPath();
        $tenantId = app('currentTenant')->id;

        // Con `fgetcsv` y no partiendo por líneas: una nota puede llevar saltos
        // de línea dentro de sus comillas, y la exportación los escribe así.
        $archivo = fopen($ruta, 'r');
        $separador = str_contains((string) fgets($archivo), ';') ? ';' : ',';
        rewind($archivo);

        $cabecera = fgetcsv($archivo, 0, $separador) ?: [];
        $lineas = [];

        while (($celdas = fgetcsv($archivo, 0, $separador)) !== false) {
            if (count($lineas) >= self::MAX_FILAS_CSV) {
                fclose($archivo);

                return response()->json([
                    'message' => 'El archivo tiene más de '.self::MAX_FILAS_CSV.' clientes. Divídelo en varios archivos e impórtalos por partes.',
                ], 422);
            }

            $lineas[] = $celdas;
        }

        fclose($archivo);

        // El BOM que escribe Excel (y la exportación) va pegado a la primera cabecera.
        $cabecera = array_map(fn ($c) => mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $c))), $cabecera);
        $columna = fn (string ...$nombres) => collect($nombres)->map(fn ($n) => array_search($n, $cabecera, true))->first(fn ($i) => $i !== false);
        $mapa = [
            'nombre'    => $columna('nombre', 'name'),
            'telefono'  => $columna('telefono', 'teléfono', 'phone', 'whatsapp'),
            'correo'    => $columna('correo', 'email'),
            'etiquetas' => $columna('etiquetas', 'tags'),
            'notas'     => $columna('notas', 'notes'),
        ];

        if ($mapa['telefono'] === null && $mapa['correo'] === null) {
            return response()->json([
                'message' => 'El archivo necesita una columna «telefono» o «correo»: es lo que identifica a cada cliente.',
            ], 422);
        }

        $creados = 0;
        $actualizados = 0;
        $sinCambios = 0;
        $omitidos = 0;
        $errores = [];

        DB::transaction(function () use ($lineas, $mapa, $modo, $tenantId, &$creados, &$actualizados, &$sinCambios, &$omitidos, &$errores) {
            foreach ($lineas as $i => $celdas) {
                $fila = $i + 2;

                if (implode('', array_map(fn ($c) => trim((string) $c), $celdas)) === '') {
                    continue;
                }

                // SEC-7: la comilla que la exportación pone delante de una celda
                // que Excel leería como fórmula se quita aquí.
                $celdas = array_map(fn ($c) => CeldaCsv::sinPrefijo(trim((string) $c)), $celdas);
                $celda = fn (string $campo) => $mapa[$campo] !== null ? ($celdas[$mapa[$campo]] ?? '') : '';

                $telefono = $celda('telefono');
                $correo = $celda('correo');

                if (! Directorio::claveDeTelefono($telefono) && ! Directorio::claveDeCorreo($correo)) {
                    $errores[] = "Fila {$fila}: necesita un teléfono (de al menos ".Directorio::DIGITOS_DEL_TELEFONO.' dígitos) o un correo válido.';

                    continue;
                }

                $nombre = $celda('nombre');
                $etiquetas = Directorio::etiquetas(explode('|', $celda('etiquetas')));
                $notas = mb_substr($celda('notas'), 0, 2000);

                $existia = Customer::withoutTenant()
                    ->where('tenant_id', $tenantId)
                    ->where(function ($q) use ($telefono, $correo) {
                        $q->when(Directorio::claveDeTelefono($telefono), fn ($s, $clave) => $s->orWhere('phone_key', $clave))
                            ->when(Directorio::claveDeCorreo($correo), fn ($s, $clave) => $s->orWhere('email_key', $clave));
                    })
                    ->exists();

                if ($existia && $modo === 'omitir') {
                    $omitidos++;

                    continue;
                }

                $persona = Directorio::dePersona($tenantId, null, $nombre !== '' ? $nombre : null, $telefono, $correo);

                if (! $existia) {
                    $persona->fill(['notes' => $notas !== '' ? $notas : null, 'tags' => $etiquetas ?: null])->save();
                    $creados++;

                    continue;
                }

                // Actualizar: una celda vacía no borra.
                if ($nombre !== '') {
                    $persona->name = mb_substr($nombre, 0, 200);
                }
                if ($notas !== '') {
                    $persona->notes = $notas;
                }
                if ($etiquetas !== []) {
                    $persona->tags = $etiquetas;
                }

                if ($persona->isDirty()) {
                    $persona->save();
                    $actualizados++;
                } else {
                    $sinCambios++;
                }
            }
        });

        if ($creados + $actualizados > 0) {
            Bitacora::anotar(
                ActivityLog::CLIENTES_IMPORTADOS,
                "Importó clientes desde un CSV: {$creados} nuevos y {$actualizados} actualizados.",
                ['modo' => $modo, 'creados' => $creados, 'actualizados' => $actualizados, 'sin_cambios' => $sinCambios, 'omitidos' => $omitidos, 'avisos' => count($errores)],
            );
        }

        return response()->json([
            'created_count'   => $creados,
            'updated_count'   => $actualizados,
            'unchanged_count' => $sinCambios,
            'skipped_count'   => $omitidos,
            'errors'          => $errores,
        ]);
    }

    /**
     * Los totales, siempre como numeros.
     *
     * Vienen de subconsultas con `sum()` y `count()`, y lo que devuelve el driver
     * NO es lo mismo en todas partes: MySQL da `"800.00"` (string) donde SQLite
     * -el de los tests- da `800`. Sin normalizar aqui, el panel tendria que
     * adivinar el tipo y la suite pasaria en verde probando algo distinto de lo
     * que corre en produccion.
     */
    private function conTotales(Customer $cliente): Customer
    {
        $cliente->pedidos_count = (int) $cliente->pedidos_count;
        $cliente->total_gastado = round((float) $cliente->total_gastado, 2);
        $cliente->favoritos_count = (int) $cliente->favoritos_count;
        $cliente->tiene_cuenta = $cliente->user_id !== null;
        $cliente->tags = $cliente->tags ?? [];

        return $cliente;
    }

    /**
     * La consulta comun: las personas del directorio de ESTA tienda con sus
     * totales de compra.
     *
     * Los agregados van como subconsultas para poder ordenar por ellos y, sobre
     * todo, para que **los pedidos cancelados no cuenten**: un pedido que se
     * anulo no es dinero que entro, y sumarlo convertiria el "total gastado" en
     * una cifra que no cuadra con la caja. Los de la papelera tampoco: los quita
     * el `SoftDeletes` de `Order` (MOD-8).
     */
    private function base(): Builder
    {
        $tenantId = app('currentTenant')->id;

        $deEsteCliente = fn ($q) => $q->whereColumn('orders.customer_id', 'customers.id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', '!=', 'cancelled');

        return Customer::query()
            ->select(['customers.*'])
            ->selectSub(
                Order::withoutTenant()->selectRaw('count(*)')->where($deEsteCliente),
                'pedidos_count',
            )
            ->selectSub(
                Order::withoutTenant()->selectRaw('coalesce(sum(orders.total), 0)')->where($deEsteCliente),
                'total_gastado',
            )
            ->selectSub(
                Order::withoutTenant()->selectRaw('max(orders.created_at)')->where($deEsteCliente),
                'ultima_compra',
            )
            ->selectSub(
                DB::table('user_favorites')->selectRaw('count(*)')->whereColumn('user_favorites.user_id', 'customers.user_id'),
                'favoritos_count',
            );
    }
}
