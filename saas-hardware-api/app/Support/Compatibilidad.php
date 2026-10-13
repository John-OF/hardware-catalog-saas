<?php

namespace App\Support;

use App\Models\Product;

/**
 * Las reglas de compatibilidad del armador de PC (FUN-8, paso 13.5).
 *
 * Es el UNICO sitio donde viven. Hasta aqui el armador las calculaba en el
 * navegador, leyendo las specs por aproximacion ('socket', 'zocalo', 'tdp'...),
 * y el servidor no comprobaba nada. Ahora el navegador pregunta y pinta: tener
 * dos copias de quince reglas es la forma mas segura de que digan cosas
 * distintas.
 *
 * Solo lee las specs con nombre fijo de `config/component_specs.php`. Una spec
 * libre ("Socket: AM5" escrita a mano) no cuenta: lo que no esta en su campo se
 * trata como que falta, y se dice.
 *
 * Tres tipos de incidencia:
 *  - `error`: no funciona asi (el socket no encaja, la tarjeta no cabe).
 *  - `warning`: funciona, pero hay algo que conviene saber.
 *  - `unknown`: no se pudo comprobar porque falta un dato, y en que producto.
 *    Nunca se pinta como "compatible": un visto verde por ignorancia es justo el
 *    fallo que FUN-8 vino a cerrar.
 *
 * `comprobadas` cuenta las reglas que llegaron a ejecutarse con sus datos
 * delante; es lo que deja decir "salio bien" sin mentir.
 *
 * La tabla de reglas, y el porque de cada una, esta en el paso 13.1 de
 * `mejoras_propuestas.md`.
 */
final class Compatibilidad
{
    /** Vatios que se suman por lo que no es procesador ni tarjeta: placa, RAM, discos, ventiladores. */
    private const RESTO_DEL_EQUIPO_W = 100;

    /** Lo que se supone que gasta una pieza que no dice su consumo, para poder estimar. */
    private const TDP_TIPICO_CPU_W = 65;

    private const CONSUMO_TIPICO_GPU_W = 120;

    /** Lo que da un conector PCIe de 8 pines; un adaptador de 12V-2x6 pide uno por cada tanto. */
    private const W_POR_CONECTOR_PCIE = 150;

    /**
     * Valores de las listas de la config que las reglas leen por su nombre. Si
     * se renombran alla, las reglas dejan de reconocerlos; `CompatibilidadTest`
     * comprueba que sigan existiendo.
     */
    public const CONECTOR_12V2X6 = '12V-2x6';

    public const SIN_CONECTOR = 'Ninguno';

    public const RAM_DE_LAPTOP = 'SO-DIMM';

    public const AIRE = 'Aire';

    public const LIQUIDA = 'Líquida (AIO)';

    /**
     * Las reglas 14 y 15 hablan de lo que le FALTA al armado (una tarjeta, un
     * disipador), no de que la pieza encaje mal: van en el resumen, pero no en
     * la etiqueta del candidato. Si no, todo procesador sin graficos saldria
     * con "Advertencia" en el cajon antes de llegar al paso de la tarjeta.
     */
    private const RECORDATORIOS = ['sin_video', 'sin_disipador'];

    /** @var array<string, Product> */
    private array $piezas;

    private bool $cerrar;

    /** @var list<array{tipo: string, regla: string, mensaje: string}> */
    private array $incidencias = [];

    private int $comprobadas = 0;

    /**
     * @param  array<string, Product>  $piezas  paso => producto
     * @param  bool  $cerrar  si es el armado que se va a pedir: entonces lo que
     *                        falta (la tarjeta de video, el disipador) ya no es
     *                        "todavia", es un problema
     */
    private function __construct(array $piezas, bool $cerrar)
    {
        $this->piezas = $piezas;
        $this->cerrar = $cerrar;
    }

    /**
     * Evalua un armado.
     *
     * @param  array<string, Product>  $piezas  paso => producto
     * @return array{incidencias: list<array{tipo: string, regla: string, mensaje: string}>, comprobadas: int, consumo: ?array{estimado: int, recomendado: int, asumido: bool}}
     */
    public static function evaluar(array $piezas, bool $cerrar = false): array
    {
        $armado = new self($piezas, $cerrar);
        $armado->comprobar();

        return [
            'incidencias' => $armado->incidencias,
            'comprobadas' => $armado->comprobadas,
            'consumo'     => $armado->consumo(),
        ];
    }

    /**
     * Lo que aporta cada candidato de un paso, sin heredar lo que ya fallaba.
     *
     * Se compara contra el armado SIN la pieza de ese paso: si la CPU y la placa
     * ya se llevaban mal, todos los candidatos de todos los pasos saldrian
     * "incompatibles" y el comprador no sabria cual elegir.
     *
     * @param  array<string, Product>  $piezas
     * @param  iterable<Product>  $candidatos
     * @return array<string, array{etiqueta: string, incidencias: list<array{tipo: string, regla: string, mensaje: string}>}>
     */
    public static function candidatos(array $piezas, string $paso, iterable $candidatos): array
    {
        $sinElPaso = $piezas;
        unset($sinElPaso[$paso]);

        $antes = self::evaluar($sinElPaso);
        $yaHabia = array_map(fn (array $i) => $i['regla'].'|'.$i['mensaje'], $antes['incidencias']);

        $resultado = [];

        foreach ($candidatos as $candidato) {
            $con = self::evaluar([...$sinElPaso, $paso => $candidato]);
            $nuevas = array_values(array_filter(
                $con['incidencias'],
                fn (array $i) => ! in_array($i['regla'].'|'.$i['mensaje'], $yaHabia, true)
                    && ! in_array($i['regla'], self::RECORDATORIOS, true),
            ));

            $resultado[$candidato->id] = [
                'etiqueta'    => self::etiqueta($nuevas, $con['comprobadas'] - $antes['comprobadas']),
                'incidencias' => $nuevas,
            ];
        }

        return $resultado;
    }

    /**
     * Cinco estados y no tres. "sin_datos" y "sin_comprobar" son los que faltaban:
     * un producto del que no se pudo comprobar nada no es "compatible".
     */
    private static function etiqueta(array $nuevas, int $comprobadasConEl): string
    {
        $tipos = array_column($nuevas, 'tipo');

        return match (true) {
            in_array('error', $tipos, true)   => 'incompatible',
            in_array('warning', $tipos, true) => 'advertencia',
            in_array('unknown', $tipos, true) => 'sin_datos',
            $comprobadasConEl > 0             => 'compatible',
            default                           => 'sin_comprobar',
        };
    }

    private function comprobar(): void
    {
        $this->socket();
        $this->memoria();
        $this->ramEnLaPlaca();
        $this->placaEnElGabinete();
        $this->tarjetaEnElGabinete();
        $this->disipadorEnElGabinete();
        $this->socketDelDisipador();
        $this->discoM2();
        $this->fuenteEnElGabinete();
        $this->potencia();
        $this->conectores();
        $this->sinVideo();
        $this->sinDisipador();
    }

    // ------------------------------------------------------------------ reglas

    /** 1. Socket del procesador = socket de la placa. */
    private function socket(): void
    {
        if (! $this->hay('cpu', 'motherboard')) {
            return;
        }

        if ($this->faltaAlguno('socket', 'el socket', ['cpu' => 'socket', 'motherboard' => 'socket'])) {
            return;
        }

        $this->comprobadas++;
        [$cpu, $placa] = [$this->valor('cpu', 'socket'), $this->valor('motherboard', 'socket')];

        if ($cpu !== $placa) {
            $this->error('socket', "El procesador es {$cpu} y la placa madre {$placa}: no encajan.");
        }
    }

    /** 2. Tipo de memoria de la RAM = el de la placa. */
    private function memoria(): void
    {
        if (! $this->hay('ram', 'motherboard')) {
            return;
        }

        if ($this->faltaAlguno('memoria', 'el tipo de memoria', ['ram' => 'tipo_memoria', 'motherboard' => 'tipo_memoria'])) {
            return;
        }

        $this->comprobadas++;
        [$ram, $placa] = [$this->valor('ram', 'tipo_memoria'), $this->valor('motherboard', 'tipo_memoria')];

        if ($ram !== $placa) {
            $this->error('memoria', "La memoria es {$ram} y la placa madre usa {$placa}: no entra.");
        }
    }

    /** 3 y 4. Que la RAM sea de escritorio, y que sus módulos quepan en las ranuras. */
    private function ramEnLaPlaca(): void
    {
        if (! $this->hay('ram', 'motherboard')) {
            return;
        }

        if (! $this->faltaAlguno('formato_ram', 'si la memoria es de escritorio', ['ram' => 'formato'])) {
            $this->comprobadas++;

            if ($this->valor('ram', 'formato') === self::RAM_DE_LAPTOP) {
                $this->error('formato_ram', 'La memoria es SO-DIMM, de laptop: no entra en una placa de escritorio.');
            }
        }

        if (! $this->faltaAlguno('slots_ram', 'si caben los módulos', ['ram' => 'modulos', 'motherboard' => 'slots_ram'])) {
            $this->comprobadas++;
            [$modulos, $ranuras] = [$this->numero('ram', 'modulos'), $this->numero('motherboard', 'slots_ram')];

            if ($modulos > $ranuras) {
                $this->error('slots_ram', "La memoria trae {$modulos} módulos y la placa madre tiene {$ranuras} ranuras.");
            }
        }
    }

    /** 5. El formato de la placa, admitido por el gabinete. */
    private function placaEnElGabinete(): void
    {
        if (! $this->hay('motherboard', 'case')) {
            return;
        }

        if ($this->faltaAlguno('formato_placa', 'si la placa cabe en el gabinete', ['motherboard' => 'formato', 'case' => 'formatos_placa'])) {
            return;
        }

        $this->comprobadas++;
        $formato = $this->valor('motherboard', 'formato');

        if (! in_array($formato, $this->lista('case', 'formatos_placa'), true)) {
            $this->error('formato_placa', "La placa madre es {$formato} y el gabinete admite ".$this->valor('case', 'formatos_placa').'.');
        }
    }

    /** 6. El largo de la tarjeta, dentro del que admite el gabinete. */
    private function tarjetaEnElGabinete(): void
    {
        if (! $this->hay('gpu', 'case')) {
            return;
        }

        if ($this->faltaAlguno('largo_gpu', 'si la tarjeta de video cabe en el gabinete', ['gpu' => 'largo', 'case' => 'largo_max_gpu'])) {
            return;
        }

        $this->comprobadas++;
        [$largo, $maximo] = [$this->numero('gpu', 'largo'), $this->numero('case', 'largo_max_gpu')];

        if ($largo > $maximo) {
            $this->error('largo_gpu', "La tarjeta de video mide {$largo} mm y en el gabinete caben {$maximo} mm.");
        }
    }

    /** 7. De aire: su altura cabe. Líquida: su radiador cabe. */
    private function disipadorEnElGabinete(): void
    {
        if (! $this->hay('cooling', 'case')) {
            return;
        }

        if ($this->faltaAlguno('disipador_cabe', 'si el disipador cabe en el gabinete', ['cooling' => 'refrigeracion'])) {
            return;
        }

        if ($this->valor('cooling', 'refrigeracion') === self::AIRE) {
            if ($this->faltaAlguno('disipador_cabe', 'si el disipador cabe en el gabinete', ['cooling' => 'altura', 'case' => 'altura_max_disipador'])) {
                return;
            }

            $this->comprobadas++;
            [$altura, $maximo] = [$this->numero('cooling', 'altura'), $this->numero('case', 'altura_max_disipador')];

            if ($altura > $maximo) {
                $this->error('disipador_cabe', "El disipador mide {$altura} mm de alto y en el gabinete caben {$maximo} mm.");
            }

            return;
        }

        if ($this->faltaAlguno('disipador_cabe', 'si el radiador cabe en el gabinete', ['cooling' => 'radiador', 'case' => 'radiador_max'])) {
            return;
        }

        $this->comprobadas++;
        [$radiador, $maximo] = [$this->numero('cooling', 'radiador'), $this->numero('case', 'radiador_max')];

        if ($radiador > $maximo) {
            $this->error('disipador_cabe', $maximo === 0
                ? "El disipador lleva un radiador de {$radiador} mm y el gabinete no admite radiadores."
                : "El disipador lleva un radiador de {$radiador} mm y en el gabinete cabe uno de {$maximo} mm.");
        }
    }

    /** 8. El socket del procesador, entre los que soporta el disipador. */
    private function socketDelDisipador(): void
    {
        if (! $this->hay('cpu', 'cooling')) {
            return;
        }

        if ($this->faltaAlguno('socket_disipador', 'si el disipador sirve para este procesador', ['cpu' => 'socket', 'cooling' => 'sockets'])) {
            return;
        }

        $this->comprobadas++;
        $socket = $this->valor('cpu', 'socket');

        if (! in_array($socket, $this->lista('cooling', 'sockets'), true)) {
            $this->error('socket_disipador', "El procesador es {$socket} y el disipador sirve para ".$this->valor('cooling', 'sockets').'.');
        }
    }

    /** 9. Un disco M.2 necesita una ranura M.2 en la placa. */
    private function discoM2(): void
    {
        if (! $this->hay('ssd', 'motherboard')) {
            return;
        }

        if ($this->faltaAlguno('m2', 'si el disco tiene dónde conectarse', ['ssd' => 'interfaz'])) {
            return;
        }

        // Un disco SATA no se comprueba: no se preguntan los puertos SATA de la
        // placa, y decir que salio bien seria prometer una revision que no hubo.
        if (! str_starts_with($this->valor('ssd', 'interfaz'), 'M.2')) {
            return;
        }

        if ($this->faltaAlguno('m2', 'si el disco tiene dónde conectarse', ['motherboard' => 'slots_m2'])) {
            return;
        }

        $this->comprobadas++;

        if ($this->numero('motherboard', 'slots_m2') === 0) {
            $this->error('m2', 'El disco es M.2 y la placa madre no tiene ranuras M.2.');
        }
    }

    /** 10. El formato de la fuente, admitido por el gabinete. */
    private function fuenteEnElGabinete(): void
    {
        if (! $this->hay('power', 'case')) {
            return;
        }

        if ($this->faltaAlguno('formato_fuente', 'si la fuente cabe en el gabinete', ['power' => 'formato', 'case' => 'formatos_fuente'])) {
            return;
        }

        $this->comprobadas++;
        $formato = $this->valor('power', 'formato');

        if (! in_array($formato, $this->lista('case', 'formatos_fuente'), true)) {
            $this->error('formato_fuente', "La fuente es {$formato} y el gabinete admite ".$this->valor('case', 'formatos_fuente').'.');
        }
    }

    /** 11. Los vatios de la fuente frente al consumo estimado y a lo que pide la tarjeta. */
    private function potencia(): void
    {
        if (! isset($this->piezas['power']) || (! isset($this->piezas['cpu']) && ! isset($this->piezas['gpu']))) {
            return;
        }

        if ($this->faltaAlguno('potencia', 'la potencia', ['power' => 'potencia'])) {
            return;
        }

        $this->comprobadas++;
        $vatios = $this->numero('power', 'potencia');
        $consumo = $this->consumo();

        if ($vatios < $consumo['estimado']) {
            $this->error('potencia', "La fuente da {$vatios} W y el equipo puede pedir unos {$consumo['estimado']} W.");
        } elseif ($vatios < $consumo['recomendado']) {
            $porLaTarjeta = $consumo['recomendado'] === $this->numero('gpu', 'fuente_recomendada');

            $this->aviso('potencia', $porLaTarjeta
                ? "La fuente da {$vatios} W y el fabricante de la tarjeta de video recomienda {$consumo['recomendado']} W."
                : "La fuente da {$vatios} W: alcanza, pero va justa. Se recomiendan {$consumo['recomendado']} W.");
        }

        // De ese numero depende el veredicto: si lleva dentro un valor supuesto,
        // hay que decirlo.
        if ($consumo['asumido']) {
            $faltan = [];

            if (isset($this->piezas['cpu']) && $this->numero('cpu', 'tdp') === null) {
                $faltan[] = $this->falta('cpu', 'tdp');
            }

            if (isset($this->piezas['gpu']) && $this->numero('gpu', 'consumo') === null) {
                $faltan[] = $this->falta('gpu', 'consumo');
            }

            $this->desconocido('consumo', 'El consumo es una estimación con un valor típico: falta '.implode(' y ', $faltan).'.');
        }
    }

    /** 12 y 13. Los conectores que pide la tarjeta, frente a los que trae la fuente. */
    private function conectores(): void
    {
        if (! $this->hay('gpu', 'power')) {
            return;
        }

        if ($this->faltaAlguno('conectores', 'los conectores de la tarjeta de video', ['gpu' => 'conector_energia'])) {
            return;
        }

        $conector = $this->valor('gpu', 'conector_energia');

        if ($conector === self::SIN_CONECTOR) {
            $this->comprobadas++;

            return;
        }

        if ($conector !== self::CONECTOR_12V2X6) {
            if ($this->faltaAlguno('conectores', 'los conectores de la tarjeta de video', ['power' => 'conectores_pcie'])) {
                return;
            }

            $this->comprobadas++;
            $pide = (int) $conector;
            $tiene = $this->numero('power', 'conectores_pcie');

            if ($tiene < $pide) {
                $this->error('conectores', "La tarjeta de video pide {$pide} conectores PCIe y la fuente trae {$tiene}.");
            }

            return;
        }

        if ($this->faltaAlguno('conectores', 'el conector 12V-2x6', ['power' => 'conector_12v2x6'])) {
            return;
        }

        if ($this->valor('power', 'conector_12v2x6') === 'si') {
            $this->comprobadas++;

            return;
        }

        // Sin 12V-2x6 en la fuente, la tarjeta trae un adaptador que se
        // alimenta con un conector de 8 pines por cada 150 W de consumo.
        if ($this->faltaAlguno('conectores', 'si la fuente alcanza para el adaptador de la tarjeta', ['gpu' => 'consumo', 'power' => 'conectores_pcie'])) {
            return;
        }

        $this->comprobadas++;
        $pide = (int) ceil($this->numero('gpu', 'consumo') / self::W_POR_CONECTOR_PCIE);
        $tiene = $this->numero('power', 'conectores_pcie');

        if ($tiene >= $pide) {
            $this->aviso('conectores', "La tarjeta de video usa un conector 12V-2x6 y la fuente no lo trae: hay que usar el adaptador de la tarjeta, que pide {$pide} conectores PCIe (la fuente trae {$tiene}).");
        } else {
            $this->error('conectores', "La tarjeta de video usa un conector 12V-2x6 que la fuente no trae, y su adaptador pide {$pide} conectores PCIe: la fuente trae {$tiene}.");
        }
    }

    /** 14. Sin tarjeta de video, el procesador tiene que dar imagen. */
    private function sinVideo(): void
    {
        if (! isset($this->piezas['cpu']) || isset($this->piezas['gpu'])) {
            return;
        }

        $graficos = $this->valor('cpu', 'graficos_integrados');

        if ($graficos === 'no') {
            // Mientras se elige es un aviso —el paso de la tarjeta viene
            // despues—; en el armado que se va a pedir, es un error.
            $this->cerrar
                ? $this->error('sin_video', 'El procesador no tiene gráficos integrados y el armado no lleva tarjeta de video: no daría imagen.')
                : $this->aviso('sin_video', 'El procesador no tiene gráficos integrados: el armado necesita una tarjeta de video.');
        } elseif ($graficos === null && $this->cerrar) {
            $this->desconocido('sin_video', 'No podemos comprobar si el armado da imagen sin tarjeta de video: falta '.$this->falta('cpu', 'graficos_integrados').'.');
        }
    }

    /** 15. Sin disipador aparte, el procesador tiene que traer uno. */
    private function sinDisipador(): void
    {
        if (! isset($this->piezas['cpu']) || isset($this->piezas['cooling'])) {
            return;
        }

        $incluye = $this->valor('cpu', 'incluye_disipador');

        if ($incluye === 'no') {
            $this->aviso('sin_disipador', 'El procesador no trae disipador: el armado necesita uno.');
        } elseif ($incluye === null && $this->cerrar) {
            $this->desconocido('sin_disipador', 'No podemos comprobar si el procesador trae disipador: falta '.$this->falta('cpu', 'incluye_disipador').'.');
        }
    }

    // ----------------------------------------------------------------- consumo

    /**
     * Procesador + tarjeta + lo demas. La recomendada es la mayor entre ese
     * total con margen y la que pide el fabricante de la tarjeta, que conoce
     * sus picos mejor que una formula.
     *
     * @return ?array{estimado: int, recomendado: int, asumido: bool}
     */
    private function consumo(): ?array
    {
        if ($this->piezas === []) {
            return null;
        }

        $cpu = isset($this->piezas['cpu']) ? $this->numero('cpu', 'tdp') : null;
        $gpu = isset($this->piezas['gpu']) ? $this->numero('gpu', 'consumo') : null;

        $estimado = (isset($this->piezas['cpu']) ? ($cpu ?? self::TDP_TIPICO_CPU_W) : 0)
            + (isset($this->piezas['gpu']) ? ($gpu ?? self::CONSUMO_TIPICO_GPU_W) : 0)
            + self::RESTO_DEL_EQUIPO_W;

        return [
            'estimado'    => $estimado,
            'recomendado' => max((int) round($estimado * 1.25 + 50), $this->numero('gpu', 'fuente_recomendada') ?? 0),
            'asumido'     => (isset($this->piezas['cpu']) && $cpu === null) || (isset($this->piezas['gpu']) && $gpu === null),
        ];
    }

    // ---------------------------------------------------------------- lectura

    private function hay(string ...$pasos): bool
    {
        foreach ($pasos as $paso) {
            if (! isset($this->piezas[$paso])) {
                return false;
            }
        }

        return true;
    }

    /** El valor de un campo fijo, o null si no esta. Una spec libre no cuenta. */
    private function valor(string $paso, string $clave): ?string
    {
        $valor = $this->piezas[$paso]->specs[$clave] ?? null;

        return is_scalar($valor) && trim((string) $valor) !== '' ? trim((string) $valor) : null;
    }

    /** Un campo numerico: un valor que no es un entero limpio cuenta como que falta. */
    private function numero(string $paso, string $clave): ?int
    {
        if (! isset($this->piezas[$paso])) {
            return null;
        }

        $valor = $this->valor($paso, $clave);

        return $valor !== null && preg_match('/^\d+$/', $valor) ? (int) $valor : null;
    }

    /** Un campo de varios valores: "AM4, AM5" → ['AM4', 'AM5']. */
    private function lista(string $paso, string $clave): array
    {
        return array_map('trim', explode(',', (string) $this->valor($paso, $clave)));
    }

    /**
     * Si falta alguno de los campos, lo anota como desconocido —diciendo cual y
     * en que producto— y devuelve true para que la regla no siga.
     *
     * @param  array<string, string>  $campos  paso => clave
     */
    private function faltaAlguno(string $regla, string $que, array $campos): bool
    {
        $faltan = [];

        foreach ($campos as $paso => $clave) {
            $tipo = SpecsFijas::todas()[$clave]['tipo'] ?? null;
            $vacio = $tipo === 'numero' ? $this->numero($paso, $clave) === null : $this->valor($paso, $clave) === null;

            if ($vacio) {
                $faltan[] = $this->falta($paso, $clave);
            }
        }

        if ($faltan === []) {
            return false;
        }

        $this->desconocido($regla, "No podemos comprobar {$que}: falta ".implode(' y ', $faltan).'.');

        return true;
    }

    /** "«Socket» en Intel Core i5-14600K". */
    private function falta(string $paso, string $clave): string
    {
        $etiqueta = SpecsFijas::todas()[$clave]['etiqueta'] ?? $clave;

        return "«{$etiqueta}» en {$this->piezas[$paso]->name}";
    }

    private function error(string $regla, string $mensaje): void
    {
        $this->incidencias[] = ['tipo' => 'error', 'regla' => $regla, 'mensaje' => $mensaje];
    }

    private function aviso(string $regla, string $mensaje): void
    {
        $this->incidencias[] = ['tipo' => 'warning', 'regla' => $regla, 'mensaje' => $mensaje];
    }

    private function desconocido(string $regla, string $mensaje): void
    {
        $this->incidencias[] = ['tipo' => 'unknown', 'regla' => $regla, 'mensaje' => $mensaje];
    }
}
