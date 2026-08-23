<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indice de la consulta principal del catalogo publico (AUD-20).
 *
 * La consulta que sirve la portada de cada tienda filtra por
 * `tenant_id + is_active + status` y ordena por `sort_order`. Los indices que
 * habia se quedaban en las dos primeras columnas, asi que MySQL resolvia el
 * filtro con el indice y despues ordenaba en memoria (filesort) el resultado
 * entero. Con el compuesto completo, el propio indice ya viene ordenado y la
 * consulta se resuelve sin ordenar nada.
 *
 * `idx_products_active` desaparece porque el nuevo lo contiene: `(tenant_id,
 * is_active)` es su prefijo por la izquierda, asi que todo lo que resolvia aquel
 * lo resuelve este. Mantener los dos solo anadiria trabajo en cada escritura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'is_active', 'status', 'sort_order'],
                'idx_products_catalogo'
            );

            $table->dropIndex('idx_products_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['tenant_id', 'is_active'], 'idx_products_active');

            $table->dropIndex('idx_products_catalogo');
        });
    }
};
