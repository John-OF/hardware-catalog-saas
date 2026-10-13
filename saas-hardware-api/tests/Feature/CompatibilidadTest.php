<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Compatibilidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Las quince reglas del armador de PC, en el servidor (FUN-8, paso 13.5).
 *
 * Las piezas son las de la verificacion del paso 13.1 —sus cifras salen de las
 * fichas oficiales de fabricante—, y los dos armados de alli son los primeros
 * casos: el que pasa todo, y el mismo en un gabinete NR200P, que tiene que dar
 * EXACTAMENTE sus tres errores. Un armador que se calla un error devuelve un
 * equipo a la tienda; uno que se inventa uno impide venderlo.
 */
class CompatibilidadTest extends TestCase
{
    use RefreshDatabase;

    /** Las piezas de la verificacion de 13.1, con los datos de sus fichas. */
    private const FICHAS = [
        'ryzen_7600x' => ['cpu', 'AMD Ryzen 5 7600X', ['socket' => 'AM5', 'tdp' => '105', 'graficos_integrados' => 'si', 'incluye_disipador' => 'no']],
        'i5_14400f'   => ['cpu', 'Intel Core i5-14400F', ['socket' => 'LGA1700', 'tdp' => '65', 'graficos_integrados' => 'no', 'incluye_disipador' => 'si']],
        'tuf_b650'    => ['motherboard', 'ASUS TUF GAMING B650-PLUS WIFI', ['socket' => 'AM5', 'tipo_memoria' => 'DDR5', 'slots_ram' => '4', 'formato' => 'ATX', 'slots_m2' => '3']],
        'vengeance'   => ['ram', 'Corsair Vengeance DDR5 2x16 GB', ['tipo_memoria' => 'DDR5', 'formato' => 'DIMM', 'modulos' => '2']],
        'rtx_5070'    => ['gpu', 'ASUS PRIME RTX 5070 OC', ['largo' => '304', 'consumo' => '250', 'fuente_recomendada' => '750', 'conector_energia' => '12V-2x6']],
        'samsung_990' => ['ssd', 'Samsung 990 PRO', ['interfaz' => 'M.2 NVMe']],
        'rm850x'      => ['power', 'Corsair RM850x', ['potencia' => '850', 'conectores_pcie' => '3', 'conector_12v2x6' => 'si', 'formato' => 'ATX']],
        'nh_d15'      => ['cooling', 'Noctua NH-D15 G2', ['refrigeracion' => 'Aire', 'sockets' => 'AM4, AM5, LGA1200, LGA1700, LGA1851, LGA1954', 'altura' => '168']],
        'lancool_216' => ['case', 'Lian Li Lancool 216', ['formatos_placa' => 'ATX, Micro-ATX, Mini-ITX', 'largo_max_gpu' => '392', 'altura_max_disipador' => '180', 'radiador_max' => '360', 'formatos_fuente' => 'ATX']],
        'nr200p'      => ['case', 'Cooler Master NR200P', ['formatos_placa' => 'Mini-ITX', 'largo_max_gpu' => '330', 'altura_max_disipador' => '153', 'radiador_max' => '280', 'formatos_fuente' => 'SFX, SFX-L']],
    ];

    /** El armado de 13.1 que pasa las quince reglas. */
    private const ARMADO_BUENO = ['ryzen_7600x', 'tuf_b650', 'vengeance', 'rtx_5070', 'samsung_990', 'rm850x', 'nh_d15', 'lancool_216'];

    /** Una pieza sin guardar, para probar las reglas sin pasar por la base. */
    private function pieza(string $ficha, array $cambios = []): Product
    {
        [, $nombre, $specs] = self::FICHAS[$ficha];

        return new Product(['name' => $nombre, 'specs' => array_filter([...$specs, ...$cambios], fn ($v) => $v !== null)]);
    }

    /** @return array<string, Product> */
    private function armado(array $fichas, array $cambios = []): array
    {
        $piezas = [];

        foreach ($fichas as $ficha) {
            $paso = self::FICHAS[$ficha][0];
            $piezas[$paso] = $this->pieza($ficha, $cambios[$ficha] ?? []);
        }

        return $piezas;
    }

    private function reglas(array $resultado, string $tipo): array
    {
        return array_column(array_filter($resultado['incidencias'], fn ($i) => $i['tipo'] === $tipo), 'regla');
    }

    // ---------------------------------------------------- los armados de 13.1

    public function test_el_armado_de_la_verificacion_pasa_sin_ninguna_incidencia(): void
    {
        $resultado = Compatibilidad::evaluar($this->armado(self::ARMADO_BUENO));

        $this->assertSame([], $resultado['incidencias']);
        // Doce reglas con sus dos piezas delante; la 14 y la 15 no aplican
        // porque hay tarjeta y disipador.
        $this->assertSame(12, $resultado['comprobadas']);
    }

    public function test_en_una_nr200p_da_exactamente_sus_tres_errores(): void
    {
        $fichas = [...array_diff(self::ARMADO_BUENO, ['lancool_216']), 'nr200p'];

        $resultado = Compatibilidad::evaluar($this->armado($fichas));

        $this->assertEqualsCanonicalizing(['disipador_cabe', 'formato_fuente', 'formato_placa'], $this->reglas($resultado, 'error'));
        $this->assertSame([], $this->reglas($resultado, 'warning'));
        $this->assertStringContainsString('168 mm de alto y en el gabinete caben 153 mm', implode(' ', array_column($resultado['incidencias'], 'mensaje')));
    }

    public function test_con_un_i5_14400f_solo_falla_el_socket(): void
    {
        $fichas = [...array_diff(self::ARMADO_BUENO, ['ryzen_7600x']), 'i5_14400f'];

        $resultado = Compatibilidad::evaluar($this->armado($fichas));

        $this->assertSame(['socket'], $this->reglas($resultado, 'error'));
        $this->assertSame('El procesador es LGA1700 y la placa madre AM5: no encajan.', $resultado['incidencias'][0]['mensaje']);
    }

    // -------------------------------------------------------- regla por regla

    public function test_memoria_de_otro_tipo(): void
    {
        $resultado = Compatibilidad::evaluar($this->armado(['tuf_b650', 'vengeance'], ['vengeance' => ['tipo_memoria' => 'DDR4']]));

        $this->assertSame(['memoria'], $this->reglas($resultado, 'error'));
    }

    public function test_memoria_de_laptop(): void
    {
        $resultado = Compatibilidad::evaluar($this->armado(['tuf_b650', 'vengeance'], ['vengeance' => ['formato' => 'SO-DIMM']]));

        $this->assertSame(['formato_ram'], $this->reglas($resultado, 'error'));
    }

    public function test_mas_modulos_que_ranuras(): void
    {
        $resultado = Compatibilidad::evaluar($this->armado(['tuf_b650', 'vengeance'], ['vengeance' => ['modulos' => '4'], 'tuf_b650' => ['slots_ram' => '2']]));

        $this->assertSame(['slots_ram'], $this->reglas($resultado, 'error'));
    }

    public function test_tarjeta_mas_larga_que_el_gabinete(): void
    {
        $resultado = Compatibilidad::evaluar($this->armado(['rtx_5070', 'lancool_216'], ['rtx_5070' => ['largo' => '400']]));

        $this->assertSame(['largo_gpu'], $this->reglas($resultado, 'error'));
    }

    public function test_un_radiador_que_no_cabe_y_un_gabinete_sin_radiadores(): void
    {
        $liquida = ['refrigeracion' => 'Líquida (AIO)', 'radiador' => '360', 'altura' => null];

        $resultado = Compatibilidad::evaluar($this->armado(['nh_d15', 'nr200p'], ['nh_d15' => $liquida]));
        $this->assertSame(['disipador_cabe'], $this->reglas($resultado, 'error'));

        $sinRadiadores = Compatibilidad::evaluar($this->armado(['nh_d15', 'nr200p'], ['nh_d15' => $liquida, 'nr200p' => ['radiador_max' => '0']]));
        $this->assertStringContainsString('no admite radiadores', $sinRadiadores['incidencias'][0]['mensaje']);
    }

    public function test_disipador_que_no_sirve_para_el_socket(): void
    {
        $resultado = Compatibilidad::evaluar($this->armado(['ryzen_7600x', 'nh_d15'], ['nh_d15' => ['sockets' => 'LGA1700, LGA1851']]));

        $this->assertSame(['socket_disipador'], $this->reglas($resultado, 'error'));
    }

    public function test_disco_m2_en_una_placa_sin_ranuras_y_un_sata_que_no_se_comprueba(): void
    {
        $sinRanuras = Compatibilidad::evaluar($this->armado(['tuf_b650', 'samsung_990'], ['tuf_b650' => ['slots_m2' => '0']]));
        $this->assertSame(['m2'], $this->reglas($sinRanuras, 'error'));

        // No se preguntan los puertos SATA: decir que salio bien seria mentir.
        $sata = Compatibilidad::evaluar(['ssd' => $this->pieza('samsung_990', ['interfaz' => 'SATA 2.5"']), 'motherboard' => $this->pieza('tuf_b650', ['slots_m2' => '0'])]);
        $soloPlacaYDisco = Compatibilidad::evaluar(['motherboard' => $this->pieza('tuf_b650')]);
        $this->assertSame([], $sata['incidencias']);
        $this->assertSame($soloPlacaYDisco['comprobadas'], $sata['comprobadas']);
    }

    public function test_fuente_que_no_cabe_en_el_gabinete(): void
    {
        $resultado = Compatibilidad::evaluar($this->armado(['rm850x', 'nr200p']));

        $this->assertSame(['formato_fuente'], $this->reglas($resultado, 'error'));
    }

    public function test_potencia_por_debajo_del_consumo_es_error_y_justa_es_aviso(): void
    {
        // Con disipador, para que el aviso de la regla 15 no se mezcle.
        $piezas = $this->armado(['ryzen_7600x', 'rtx_5070', 'rm850x', 'nh_d15']);

        // 105 + 250 + 100 = 455 W de consumo estimado.
        $piezas['power'] = $this->pieza('rm850x', ['potencia' => '450']);
        $this->assertSame(['potencia'], $this->reglas(Compatibilidad::evaluar($piezas), 'error'));

        // Por encima del consumo pero debajo de los 750 W que pide ASUS para su tarjeta.
        $piezas['power'] = $this->pieza('rm850x', ['potencia' => '650']);
        $resultado = Compatibilidad::evaluar($piezas);
        $this->assertSame(['potencia'], $this->reglas($resultado, 'warning'));
        $this->assertStringContainsString('el fabricante de la tarjeta de video recomienda 750 W', $resultado['incidencias'][0]['mensaje']);
        $this->assertSame(['estimado' => 455, 'recomendado' => 750, 'asumido' => false], $resultado['consumo']);
    }

    public function test_un_consumo_que_no_se_sabe_se_estima_y_se_dice(): void
    {
        $piezas = ['cpu' => $this->pieza('ryzen_7600x', ['tdp' => null]), 'power' => $this->pieza('rm850x')];

        $resultado = Compatibilidad::evaluar($piezas);

        $this->assertTrue($resultado['consumo']['asumido']);
        $this->assertSame(65 + 100, $resultado['consumo']['estimado']);
        $this->assertSame(['consumo'], $this->reglas($resultado, 'unknown'));
        $this->assertStringContainsString('«TDP / potencia base» en AMD Ryzen 5 7600X', $resultado['incidencias'][0]['mensaje']);
    }

    public function test_conectores_pcie_que_no_alcanzan(): void
    {
        $resultado = Compatibilidad::evaluar($this->armado(['rtx_5070', 'rm850x'], [
            'rtx_5070' => ['conector_energia' => '3 conectores PCIe'],
            'rm850x'   => ['conectores_pcie' => '2'],
        ]));

        $this->assertSame(['conectores'], $this->reglas($resultado, 'error'));
    }

    /**
     * 250 W de la RTX 5070 / 150 W por conector = 2, lo mismo que pide NVIDIA en
     * su guia para el adaptador.
     */
    public function test_12v2x6_sin_el_conector_en_la_fuente_depende_del_adaptador(): void
    {
        $sinConector = ['conector_12v2x6' => 'no'];

        $alcanza = Compatibilidad::evaluar($this->armado(['rtx_5070', 'rm850x'], ['rm850x' => [...$sinConector, 'conectores_pcie' => '2']]));
        $this->assertSame(['conectores'], $this->reglas($alcanza, 'warning'));
        $this->assertStringContainsString('pide 2 conectores PCIe', $alcanza['incidencias'][0]['mensaje']);

        $noAlcanza = Compatibilidad::evaluar($this->armado(['rtx_5070', 'rm850x'], ['rm850x' => [...$sinConector, 'conectores_pcie' => '1']]));
        $this->assertSame(['conectores'], $this->reglas($noAlcanza, 'error'));
    }

    /** Mientras se elige es un aviso; en el armado que se va a pedir, un error. */
    public function test_sin_tarjeta_y_sin_graficos_integrados(): void
    {
        $piezas = $this->armado(['i5_14400f', 'nh_d15']);

        $this->assertSame(['sin_video'], $this->reglas(Compatibilidad::evaluar($piezas), 'warning'));
        $this->assertSame(['sin_video'], $this->reglas(Compatibilidad::evaluar($piezas, cerrar: true), 'error'));

        // Con graficos integrados no hay nada que decir.
        $this->assertSame([], Compatibilidad::evaluar($this->armado(['ryzen_7600x', 'nh_d15']))['incidencias']);
    }

    public function test_sin_disipador_y_un_procesador_que_no_lo_trae(): void
    {
        $resultado = Compatibilidad::evaluar($this->armado(['ryzen_7600x']));

        $this->assertSame(['sin_disipador'], $this->reglas($resultado, 'warning'));
        $this->assertSame([], $this->reglas(Compatibilidad::evaluar($this->armado(['i5_14400f', 'rtx_5070'])), 'warning'));
    }

    /** Lo que falta mientras se elige no hace ruido; al pedir, se dice. */
    public function test_lo_que_no_se_sabe_del_procesador_solo_se_dice_al_cerrar(): void
    {
        $piezas = ['cpu' => $this->pieza('ryzen_7600x', ['graficos_integrados' => null, 'incluye_disipador' => null])];

        $this->assertSame([], Compatibilidad::evaluar($piezas)['incidencias']);
        $this->assertEqualsCanonicalizing(['sin_video', 'sin_disipador'], $this->reglas(Compatibilidad::evaluar($piezas, cerrar: true), 'unknown'));
    }

    // ------------------------------------------------- lo que no se sabe

    public function test_un_dato_que_falta_se_dice_con_el_campo_y_el_producto_y_no_cuenta(): void
    {
        $resultado = Compatibilidad::evaluar($this->armado(['ryzen_7600x', 'tuf_b650'], ['tuf_b650' => ['socket' => null]]));

        $this->assertSame(['socket'], $this->reglas($resultado, 'unknown'));
        $this->assertSame('No podemos comprobar el socket: falta «Socket» en ASUS TUF GAMING B650-PLUS WIFI.', $resultado['incidencias'][0]['mensaje']);
        $this->assertSame([], $this->reglas($resultado, 'error'));
    }

    /** Lo que FUN-8 vino a cerrar: una spec libre no se adivina. */
    public function test_una_spec_libre_no_cuenta_como_el_campo_fijo(): void
    {
        $cpu = new Product(['name' => 'Ryzen escrito a mano', 'specs' => ['Socket' => 'AM5', 'TDP' => '105W']]);

        $resultado = Compatibilidad::evaluar(['cpu' => $cpu, 'motherboard' => $this->pieza('tuf_b650')]);

        $this->assertSame(['socket'], $this->reglas($resultado, 'unknown'));
        $this->assertSame(0, array_sum(array_map(fn () => 1, $this->reglas($resultado, 'error'))));
    }

    /** Las reglas leen estos valores por su nombre: si la config los renombra, tiene que fallar aqui. */
    public function test_los_valores_que_leen_las_reglas_existen_en_la_config(): void
    {
        $listas = config('component_specs.listas');

        $this->assertContains(Compatibilidad::CONECTOR_12V2X6, $listas['conectores_de_gpu']);
        $this->assertContains(Compatibilidad::SIN_CONECTOR, $listas['conectores_de_gpu']);
        $this->assertContains(Compatibilidad::RAM_DE_LAPTOP, $listas['formatos_de_ram']);
        $this->assertContains(Compatibilidad::AIRE, $listas['refrigeraciones']);
        $this->assertContains(Compatibilidad::LIQUIDA, $listas['refrigeraciones']);

        // Los de "N conectores PCIe" se leen por su numero.
        foreach (array_diff($listas['conectores_de_gpu'], [Compatibilidad::CONECTOR_12V2X6, Compatibilidad::SIN_CONECTOR]) as $opcion) {
            $this->assertMatchesRegularExpression('/^\d+ /', $opcion);
        }
    }

    // --------------------------------------------------------- los candidatos

    /**
     * Un conflicto entre otras dos piezas no se le achaca al candidato: con la
     * CPU y la placa ya peleadas, una memoria que encaja sigue siendo compatible.
     */
    public function test_el_candidato_no_hereda_lo_que_ya_fallaba(): void
    {
        $piezas = $this->armado(['i5_14400f', 'tuf_b650']);
        $buena = $this->pieza('vengeance');
        $buena->id = 'buena';
        $mala = $this->pieza('vengeance', ['tipo_memoria' => 'DDR4']);
        $mala->id = 'mala';

        $candidatos = Compatibilidad::candidatos($piezas, 'ram', [$buena, $mala]);

        $this->assertSame('compatible', $candidatos['buena']['etiqueta']);
        $this->assertSame([], $candidatos['buena']['incidencias']);
        $this->assertSame('incompatible', $candidatos['mala']['etiqueta']);
        $this->assertSame(['memoria'], array_column($candidatos['mala']['incidencias'], 'regla'));
    }

    /**
     * Sin nada con qué compararlo no es "compatible": es "sin comprobar". Y que
     * le falte la tarjeta al armado no es culpa del procesador: el recordatorio
     * va en el resumen, no en su etiqueta.
     */
    public function test_el_primer_candidato_sale_sin_comprobar_aunque_el_armado_tenga_recordatorios(): void
    {
        $cpu = $this->pieza('i5_14400f');
        $cpu->id = 'cpu';

        $this->assertSame('sin_comprobar', Compatibilidad::candidatos([], 'cpu', [$cpu])['cpu']['etiqueta']);
        $this->assertSame(['sin_video'], $this->reglas(Compatibilidad::evaluar(['cpu' => $cpu]), 'warning'));
    }

    // -------------------------------------------------------------- la ruta

    private Tenant $tenant;

    private function tienda(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->tenant = Tenant::create([
            'slug' => 'tienda-armador', 'name' => 'Tienda Armador', 'whatsapp_number' => '51999999999',
            'is_active' => true, 'is_published' => true,
        ]);
    }

    /** Guarda una de las fichas en la tienda, en una categoria de su tipo. */
    private function guardada(string $ficha, array $atributos = [], ?Tenant $tienda = null): Product
    {
        $tienda ??= $this->tenant;
        [$paso, $nombre, $specs] = self::FICHAS[$ficha];

        $categoria = Category::withoutTenant()->where('tenant_id', $tienda->id)->where('component_type', $paso)->first();

        if (! $categoria) {
            $categoria = new Category(['name' => "Categoria {$paso}", 'component_type' => $paso, 'is_active' => true]);
            $categoria->tenant_id = $tienda->id;
            $categoria->save();
        }

        $producto = new Product($atributos + [
            'name' => $nombre, 'price' => 100, 'stock' => 5, 'is_active' => true, 'status' => 'published',
            'category_id' => $categoria->id, 'specs' => $specs,
        ]);
        $producto->tenant_id = $tienda->id;
        $producto->save();

        return $producto;
    }

    private function preguntar(array $parametros): TestResponse
    {
        return $this->getJson('/api/public/tienda-armador/builder/compatibility?'.http_build_query($parametros));
    }

    public function test_la_ruta_devuelve_el_armado_el_consumo_y_los_candidatos(): void
    {
        $this->tienda();
        $cpu = $this->guardada('i5_14400f');
        $placa = $this->guardada('tuf_b650');
        $ryzen = $this->guardada('ryzen_7600x');

        $respuesta = $this->preguntar([
            'seleccion'  => ['cpu' => $cpu->id, 'motherboard' => $placa->id],
            'paso'       => 'cpu',
            'candidatos' => [$cpu->id, $ryzen->id],
        ])->assertOk();

        $this->assertSame('socket', $respuesta->json('armado.incidencias.0.regla'));
        $this->assertSame(65 + 100, $respuesta->json('consumo.estimado'));
        $this->assertSame('incompatible', $respuesta->json("candidatos.{$cpu->id}.etiqueta"));
        $this->assertSame('compatible', $respuesta->json("candidatos.{$ryzen->id}.etiqueta"));
    }

    /** Al pedir, la falta de tarjeta deja de ser un aviso: los dos veredictos vienen juntos. */
    public function test_al_pedir_es_error_lo_que_mientras_se_elige_es_aviso(): void
    {
        $this->tienda();
        $cpu = $this->guardada('i5_14400f');

        $this->preguntar(['seleccion' => ['cpu' => $cpu->id]])
            ->assertJsonPath('armado.incidencias.0.tipo', 'warning')
            ->assertJsonPath('al_pedir.incidencias.0.tipo', 'error');
    }

    /**
     * Un borrador, uno desactivado o uno de otra tienda no entran en el armado:
     * se tratan como no elegidos y se devuelven aparte.
     */
    public function test_lo_que_no_esta_en_el_catalogo_no_entra_en_el_armado(): void
    {
        $this->tienda();
        $otra = Tenant::create(['slug' => 'otra', 'name' => 'Otra', 'whatsapp_number' => '51988888888', 'is_active' => true, 'is_published' => true]);

        $borrador = $this->guardada('i5_14400f', ['status' => 'draft']);
        $ajena = $this->guardada('tuf_b650', [], $otra);

        $respuesta = $this->preguntar(['seleccion' => ['cpu' => $borrador->id, 'motherboard' => $ajena->id]])->assertOk();

        $this->assertEqualsCanonicalizing([$borrador->id, $ajena->id], $respuesta->json('no_disponibles'));
        $this->assertSame([], $respuesta->json('armado.incidencias'));
        $this->assertNull($respuesta->json('consumo'));
    }

    public function test_un_producto_en_el_paso_equivocado_se_rechaza(): void
    {
        $this->tienda();
        $placa = $this->guardada('tuf_b650');

        $this->preguntar(['seleccion' => ['cpu' => $placa->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('seleccion.cpu');
    }

    public function test_un_paso_que_no_existe_se_rechaza(): void
    {
        $this->tienda();

        $this->preguntar(['seleccion' => ['monitor' => '01900000-0000-7000-8000-000000000000']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('seleccion');
    }
}
