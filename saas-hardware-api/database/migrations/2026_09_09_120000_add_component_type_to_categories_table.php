<?php

use App\Enums\ComponentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué pieza vende cada categoría (FUN-8).
 *
 * El armador de PC emparejaba sus ocho pasos con las categorías por un trozo del
 * **nombre**: 'procesador', 'placa', 'tarjeta'… Quien llamara "CPU" a sus
 * procesadores no tenía armador, y sin ningún aviso: el paso salía vacío igual
 * que si no hubiera stock. Esta columna es la respuesta explícita, que el dueño
 * elige y no adivina. Ver `App\Enums\ComponentType`.
 *
 * **Se hace ahora por el disparador, no por la fecha:** mientras las categorías
 * las creamos nosotros, rellenarlas es este UPDATE de aquí abajo; con tiendas
 * dentro sería pedirle a cada dueño que reclasifique lo suyo.
 *
 * **Default `other` y no nulo.** Nulo sería "no se sabe", y el armador tendría
 * que volver a adivinar por el nombre justo en el caso en que nadie contestó:
 * la misma adivinanza con otra ropa. `other` significa "esto no es pieza del
 * armador", que es una respuesta, y una tienda de componentes también vende
 * sillas y cables.
 *
 * El relleno lee primero el icono y sólo si no dice nada mira el nombre. El
 * select de iconos del panel ya era esta misma lista haciendo de tipo en secreto
 * —el armador lo consultaba—, así que quien eligió icono ya contestó; el nombre
 * es para quien lo dejó en `folder`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('component_type', 20)->default(ComponentType::Other->value)->after('icon');
        });

        // Fila a fila y no con un CASE en SQL para que la deducción viva en un
        // solo sitio (el enum) y corra igual en el MySQL de producción y en el
        // SQLite de los tests. Son categorías: decenas por tienda, no miles.
        foreach (DB::table('categories')->get(['id', 'name', 'icon']) as $categoria) {
            $porIcono = ComponentType::tryFrom((string) $categoria->icon);

            $tipo = ($porIcono && $porIcono !== ComponentType::Other)
                ? $porIcono
                : ComponentType::inferirDeNombre($categoria->name);

            $cambios = ['component_type' => $tipo->value];

            // El icono pasa a ser el dibujo del tipo, no un dato aparte: se
            // arregla el de quien nunca eligio uno. Solo ese caso — un icono
            // elegido a mano se respeta, aunque no case con el tipo.
            if (in_array($categoria->icon, [null, '', 'folder'], true)) {
                $cambios['icon'] = $tipo->icono();
            }

            DB::table('categories')
                ->where('id', $categoria->id)
                ->update($cambios);
        }
    }

    /**
     * Se va la columna; los iconos que el relleno completo se quedan puestos.
     * Volver a ponerlos todos en `folder` seria borrar tambien los que ya
     * estaban bien, y un icono de mas no rompe nada.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('component_type');
        });
    }
};
