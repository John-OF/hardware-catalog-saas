<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * `TEC-17`. La cotización pesaba unos 860 KB por un pedido de una línea: dompdf
 * empotraba DejaVu Sans y su negrita enteras, porque el paquete trae el recorte
 * de fuentes apagado. Con el recorte, solo van los glifos que el documento usa.
 *
 * Lo que puede salir mal al recortar no es el tamaño sino que un carácter se
 * quede fuera del recorte y se imprima como un hueco: una tilde, la `ñ`, el
 * símbolo de la moneda. Por eso el segundo test no busca letras en el texto
 * sino que, para cada carácter dibujado, mira en el mapa CID→GID de la fuente
 * que lo dibuja que tenga un glifo que no sea el vacío (el 0). Es la misma
 * cuenta que hace el lector de PDF al pintarlo.
 */
class TamanioDeLaCotizacionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        // Guaraní a propósito: `₲` es el símbolo más raro de `config/currencies.php`
        // y va en cada importe, en la fuente normal y en la negrita del total.
        $this->tenant = Tenant::create([
            'slug' => 'computo-nandu', 'name' => 'Cómputo Ñandú', 'whatsapp_number' => '595981000000',
            'is_active' => true, 'is_published' => true, 'plan' => 'enterprise', 'currency' => 'PYG',
        ]);

        $this->admin = new User(['name' => 'Duenio', 'email' => 'duenio@nandu.test', 'password' => 'password123', 'role' => 'admin', 'is_active' => true]);
        $this->admin->tenant_id = $this->tenant->id;
        $this->admin->save();
    }

    public function test_la_cotizacion_no_lleva_las_fuentes_enteras(): void
    {
        $pdf = $this->pdf('Begoña Muñoz');

        // Sin recorte eran ~860 KB; con él, ~25. El techo deja sitio a un pedido
        // largo y al logo (~20 KB) sin dejar pasar una sola fuente entera (~350).
        $this->assertLessThan(150 * 1024, strlen($pdf), 'La cotización vuelve a empotrar las fuentes enteras.');
    }

    public function test_las_tildes_la_enie_y_la_moneda_tienen_su_glifo(): void
    {
        [$dibujados, $sinGlifo] = $this->glifos($this->pdf('Begoña Muñoz'));

        // Que de verdad se dibujaron —si no, la comprobación de abajo no probaría
        // nada—: en el nombre de la tienda y el del cliente (negrita), en la
        // línea y en la nota (normal), en la cabecera y en los importes.
        foreach (['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Ñ', 'Ó', '°', '¿', '¡', '₲'] as $caracter) {
            $this->assertContains($caracter, $dibujados, "No se dibujó «{$caracter}».");
        }

        $this->assertSame([], $sinGlifo, 'Hay caracteres que salen como un hueco: '.implode(' ', $sinGlifo));
    }

    /**
     * Control: la comprobación de glifos ve uno que falta. DejaVu Sans no trae
     * ideogramas, así que `漢` sale como hueco —con recorte y sin él: es la
     * fuente, no el recorte—; si la lista saliera vacía, el test de arriba no
     * estaría mirando nada.
     */
    public function test_la_comprobacion_ve_un_caracter_sin_glifo(): void
    {
        [, $sinGlifo] = $this->glifos($this->pdf('Cliente 漢'));

        $this->assertContains('漢', $sinGlifo);
    }

    // ------------------------------------------------------------ apoyo

    private function pdf(string $cliente): string
    {
        $producto = new Product([
            'name' => 'Tarjeta gráfica RTX — edición limpia', 'price' => 4500000, 'stock' => 10,
            'is_active' => true, 'status' => 'published',
        ]);
        $producto->tenant_id = $this->tenant->id;
        $producto->save();

        $id = $this->panel()->postJson('/api/orders', [
            'customer_name' => $cliente,
            'customer_note' => '¿Envío a Luque? ¡Sí!, 3.° piso, detrás del café',
            'status' => 'pending',
            'items' => [['product_id' => $producto->id, 'quantity' => 2]],
        ])->assertCreated()->json('id');

        return $this->panel()->get("/api/orders/{$id}/pdf")->assertOk()->getContent();
    }

    private function panel(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->admin->createToken('t', ['admin'])->plainTextToken,
            'X-Tenant' => $this->tenant->slug,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * Los caracteres que dibuja la página y los que caen en el glifo vacío.
     *
     * dompdf escribe el texto con fuentes `Identity-H`: cada carácter es su punto
     * de código en UTF-16BE, y la fuente lo traduce a un glifo con su mapa
     * `CIDToGIDMap` (dos bytes por punto de código). Un glifo 0 es el `.notdef`:
     * el hueco que se ve cuando un carácter no está en la fuente.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function glifos(string $pdf): array
    {
        $objetos = $this->objetos($pdf);

        // El mapa de cada fuente de la página, por su nombre en el contenido (/F1…).
        // Una fuente sin mapa es una de reserva de dompdf (Helvetica…): la
        // registra al buscar un carácter que DejaVu no tiene, la use o no.
        $mapas = [];
        $contenidos = [];
        foreach ($objetos as $objeto) {
            if (preg_match('#/Font\s*<<(.*?)>>#s', $objeto['dic'], $fuentes)) {
                preg_match_all('#/(F\d+) (\d+) 0 R#', $fuentes[1], $referencias, PREG_SET_ORDER);
                foreach ($referencias as [, $nombre, $fuente]) {
                    $mapas[$nombre] = preg_match('#/DescendantFonts \[(\d+) 0 R#', $objetos[$fuente]['dic'], $cid)
                        && preg_match('#/CIDToGIDMap (\d+) 0 R#', $objetos[$cid[1]]['dic'], $mapa)
                        ? $objetos[$mapa[1]]['stream']
                        : null;
                }
            }
            if (preg_match('#/Type /Page\b.*?/Contents (\d+) 0 R#s', $objeto['dic'], $contenido)) {
                $contenidos[] = $objetos[$contenido[1]]['stream'];
            }
        }

        $this->assertNotEmpty($mapas, 'No se encontraron las fuentes del PDF.');
        $this->assertNotEmpty($contenidos, 'No se encontró el contenido de la página.');

        $dibujados = [];
        $sinGlifo = [];

        foreach ($contenidos as $flujo) {
            $fuente = null;
            // En orden: un cambio de fuente (`/F2 12.0 Tf`) o una cadena de texto.
            preg_match_all('#/(F\d+)\s+[\d.]+\s+Tf|\(((?:\\\\.|[^\\\\)])*)\)#s', $flujo, $piezas, PREG_SET_ORDER);

            foreach ($piezas as $pieza) {
                if (($pieza[1] ?? '') !== '') {
                    $fuente = $pieza[1];

                    continue;
                }

                $bytes = preg_replace_callback('#\\\\(.)#s', fn ($m) => $m[1] === 'r' ? "\r" : $m[1], $pieza[2]);
                $mapa = $mapas[$fuente];

                if ($mapa === null) {
                    // Dibujado con la fuente de reserva, un byte por carácter: no
                    // estaba en DejaVu, y en la de reserva sale como `?`.
                    foreach (mb_str_split(mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252')) as $caracter) {
                        $dibujados[$caracter] = $sinGlifo[$caracter] = true;
                    }

                    continue;
                }

                foreach (str_split($bytes, 2) as $par) {
                    $punto = (ord($par[0]) << 8) | ord($par[1] ?? "\0");
                    $caracter = mb_chr($punto, 'UTF-8');
                    $glifo = strlen($mapa) > $punto * 2 + 1
                        ? (ord($mapa[$punto * 2]) << 8) | ord($mapa[$punto * 2 + 1])
                        : 0;

                    $dibujados[$caracter] = true;
                    if ($glifo === 0) {
                        $sinGlifo[$caracter] = true;
                    }
                }
            }
        }

        return [array_keys($dibujados), array_keys($sinGlifo)];
    }

    /**
     * Los objetos del PDF: su diccionario y, si lo tienen, el flujo ya
     * descomprimido. Basta para lo que escribe dompdf (longitudes directas, un
     * solo filtro), no es un lector de PDF general.
     *
     * Avanza objeto a objeto saltando cada flujo por su `/Length`, y no con una
     * sola expresión sobre el archivo entero: con las fuentes enteras (sin el
     * recorte) los diccionarios miden decenas de KB, la expresión agota el
     * límite de backtracking de PCRE y se pierden objetos sin ningún error.
     *
     * @return array<int, array{dic: string, stream: ?string}>
     */
    private function objetos(string $pdf): array
    {
        $objetos = [];
        $posicion = 0;

        while (preg_match('#(\d+) 0 obj#', $pdf, $cabecera, PREG_OFFSET_CAPTURE, $posicion)) {
            $inicio = $cabecera[0][1] + strlen($cabecera[0][0]);
            $fin = strpos($pdf, 'endobj', $inicio);
            $marca = strpos($pdf, 'stream', $inicio);
            $flujo = null;

            if ($marca !== false && $marca < $fin) {
                $diccionario = substr($pdf, $inicio, $marca - $inicio);
                $datos = $marca + strlen('stream') + (substr($pdf, $marca + 6, 2) === "\r\n" ? 2 : 1);
                preg_match('#/Length (\d+)#', $diccionario, $largo);
                $flujo = substr($pdf, $datos, (int) $largo[1]);

                if (str_contains($diccionario, '/FlateDecode')) {
                    $flujo = gzuncompress($flujo);
                }

                // El `endobj` de verdad va después de los datos, que son binarios
                // y pueden llevar esa palabra dentro.
                $fin = strpos($pdf, 'endobj', $datos + (int) $largo[1]);
            } else {
                $diccionario = substr($pdf, $inicio, $fin - $inicio);
            }

            $objetos[(int) $cabecera[1][0]] = ['dic' => $diccionario, 'stream' => $flujo];
            $posicion = $fin + strlen('endobj');
        }

        return $objetos;
    }
}
