<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Mews\Purifier\Facades\Purifier;

/**
 * Limpia el HTML ya guardado en products.description y pages.content (SEC-3).
 *
 * El cast App\Casts\SanitizedHtml solo cubre escrituras nuevas; lo que se guardo
 * antes seguiria sirviendose sucio desde la API. Se usa el query builder en vez de
 * los modelos para no disparar el global scope de tenant (aqui no hay currentTenant)
 * ni los eventos saved/updated de Product.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->sanitizeColumn('products', 'description');
        $this->sanitizeColumn('pages', 'content');
    }

    /**
     * No hay down(): revertir significaria restaurar el HTML malicioso original,
     * que ya no conservamos. Es una limpieza de datos de un solo sentido.
     */
    public function down(): void
    {
        //
    }

    private function sanitizeColumn(string $table, string $column): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($table, $column) {
                foreach ($rows as $row) {
                    $clean = Purifier::clean($row->{$column}, 'store_content');

                    if ($clean !== $row->{$column}) {
                        DB::table($table)->where('id', $row->id)->update([$column => $clean]);
                    }
                }
            });
    }
};
