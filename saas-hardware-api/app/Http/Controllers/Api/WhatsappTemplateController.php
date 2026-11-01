<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Support\Bitacora;
use App\Support\PlantillasWhatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Los mensajes de WhatsApp de la tienda (MOD-25).
 *
 * Leerlos no pide permiso: *Pedidos* y *Lista de espera* los necesitan para
 * armar sus botones, y son textos sin datos de nadie. Cambiarlos pide
 * `tienda.configurar`, como el resto de la configuración (ver `routes/api.php`).
 */
class WhatsappTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(PlantillasWhatsapp::paraElPanel(app('currentTenant')));
    }

    /**
     * Guarda las plantillas que llegan; las que no llegan no se tocan.
     *
     * Cuerpo: `{"plantillas": {"pedido_listo": "…", "pedido_cancelado": null}}`.
     * `null`, vacío o el texto de fábrica significan "volver al de fábrica".
     */
    public function update(Request $request): JsonResponse
    {
        $tenant = app('currentTenant');
        $catalogo = PlantillasWhatsapp::catalogo();

        $reglas = [
            'plantillas' => [
                'required', 'array',
                function (string $atributo, $valor, \Closure $fallar) use ($catalogo) {
                    $desconocidas = array_diff(array_keys((array) $valor), array_keys($catalogo));

                    if ($desconocidas !== []) {
                        $fallar('No existe ningún mensaje llamado «'.implode('», «', $desconocidas).'».');
                    }
                },
            ],
        ];

        // Con el nombre que ve el dueño: "El campo mensaje «Pedido listo» no
        // debe ser mayor que 1000 caracteres", no "plantillas.pedido_listo".
        $nombres = [];

        foreach ($catalogo as $clave => $plantilla) {
            $nombres["plantillas.{$clave}"] = "mensaje «{$plantilla['etiqueta']}»";

            $reglas["plantillas.{$clave}"] = [
                'sometimes', 'nullable', 'string', 'max:'.PlantillasWhatsapp::maximo(),
                // Una variable mal escrita le llegaría tal cual al cliente
                // ("Hola {clinete}"), así que se rechaza al guardar y no se
                // descubre al mandar el primer mensaje.
                function (string $atributo, $valor, \Closure $fallar) use ($clave, $plantilla) {
                    $sobran = PlantillasWhatsapp::variablesQueNoAdmite($clave, (string) $valor);

                    if ($sobran === []) {
                        return;
                    }

                    $usa = implode(', ', array_map(fn ($v) => '{'.$v.'}', $sobran));
                    $admitidas = array_map(fn ($v) => '{'.$v.'}', $plantilla['variables']);
                    $ultima = array_pop($admitidas);
                    $lista = $admitidas === [] ? $ultima : implode(', ', $admitidas).' y '.$ultima;

                    $fallar("«{$plantilla['etiqueta']}» usa {$usa}, que no existe en ese mensaje. Puedes usar {$lista}.");
                },
            ];
        }

        $datos = $request->validate($reglas, [], $nombres);

        $antes = PlantillasWhatsapp::resueltas($tenant);
        $propiasAntes = is_array($tenant->whatsapp_templates) ? $tenant->whatsapp_templates : [];

        $tenant->update(['whatsapp_templates' => PlantillasWhatsapp::fusionar($tenant, $datos['plantillas'])]);

        // Las del comprador salen en el catálogo público, que va por caché.
        Cache::forget("tenant:{$tenant->slug}");

        $this->anotar($antes, $propiasAntes, $tenant->fresh());

        return response()->json(PlantillasWhatsapp::paraElPanel($tenant->fresh()));
    }

    /**
     * Una línea con qué mensajes cambiaron (INF-3). Se nombran, no se copian:
     * son textos largos y la línea es de una frase.
     *
     * @param  array<string, string>  $antes
     * @param  array<string, string>  $propiasAntes
     */
    private function anotar(array $antes, array $propiasAntes, Tenant $tenant): void
    {
        $despues = PlantillasWhatsapp::resueltas($tenant);
        $propias = is_array($tenant->whatsapp_templates) ? $tenant->whatsapp_templates : [];
        $catalogo = PlantillasWhatsapp::catalogo();

        $cambiadas = [];
        $restauradas = [];

        foreach ($despues as $clave => $texto) {
            if ($texto === $antes[$clave]) {
                continue;
            }

            if (array_key_exists($clave, $propiasAntes) && ! array_key_exists($clave, $propias)) {
                $restauradas[] = $clave;
            } else {
                $cambiadas[] = $clave;
            }
        }

        if ($cambiadas === [] && $restauradas === []) {
            return;
        }

        $nombres = fn (array $claves) => '«'.implode('», «', array_map(fn ($c) => $catalogo[$c]['etiqueta'], $claves)).'»';

        $partes = [];

        if ($cambiadas !== []) {
            $partes[] = 'cambió '.$nombres($cambiadas);
        }

        if ($restauradas !== []) {
            $partes[] = 'volvió al texto de fábrica en '.$nombres($restauradas);
        }

        Bitacora::anotar(
            ActivityLog::CONFIGURACION_EDITADA,
            'Mensajes de WhatsApp: '.implode('; ', $partes).'.',
            ['plantillas_cambiadas' => $cambiadas, 'plantillas_restauradas' => $restauradas],
        );
    }
}
