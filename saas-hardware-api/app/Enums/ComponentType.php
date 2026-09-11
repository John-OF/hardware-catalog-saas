<?php

namespace App\Enums;

/**
 * Qué pieza de PC vende una categoría (FUN-8).
 *
 * El armador emparejaba cada uno de sus ocho pasos con una categoría buscando un
 * **trozo de su nombre** ('procesador', 'placa', 'tarjeta'…). Una tienda que
 * llamara "CPU" a sus procesadores o "Gráficas" a sus GPU se quedaba sin armador
 * y sin ningún aviso: el paso aparecía vacío, como si no hubiera stock. La
 * función más diferenciadora del producto dependía de que el dueño adivinara los
 * nombres que esperaba un array del frontend.
 *
 * Esto es la respuesta explícita a esa pregunta, elegida por el dueño al crear la
 * categoría y guardada en `categories.component_type`.
 *
 * **Los valores reutilizan los slugs de icono que ya existían** (`cpu`, `gpu`,
 * `motherboard`…). No es casualidad: el select de iconos del panel ya era, de
 * hecho, esta misma lista haciendo de tipo a escondidas —el armador incluso lo
 * miraba (`cat.icon === step.iconSlug`)—, sólo que era opcional, caía en
 * `folder` y nadie sabía que decidía nada. Al compartir los valores, el relleno
 * de lo que ya existe es directo y el icono se sigue deduciendo de aquí.
 *
 * `Other` es un tipo de verdad, no un "sin clasificar": una tienda de
 * componentes vende también sillas, cables y licencias. Lo que no es pieza del
 * armador tiene que poder decirlo.
 */
enum ComponentType: string
{
    case Cpu = 'cpu';
    case Motherboard = 'motherboard';
    case Ram = 'ram';
    case Gpu = 'gpu';
    case Storage = 'ssd';
    case Psu = 'power';
    case Cooling = 'cooling';
    case Chassis = 'case';
    case Monitor = 'monitor';
    case Peripheral = 'peripheral';
    case Other = 'other';

    /**
     * Los ocho pasos del armador, en el orden en que se monta una PC.
     *
     * @return array<int, self>
     */
    public static function deArmador(): array
    {
        return [
            self::Cpu,
            self::Motherboard,
            self::Ram,
            self::Gpu,
            self::Storage,
            self::Psu,
            self::Cooling,
            self::Chassis,
        ];
    }

    public function esDeArmador(): bool
    {
        return in_array($this, self::deArmador(), true);
    }

    /** Slug para `CategoryIcon` del frontend. `Other` no tiene icono propio. */
    public function icono(): string
    {
        return $this === self::Other ? 'folder' : $this->value;
    }

    /**
     * Qué otras piezas se le ofrecen a quien está mirando esta (cross-selling).
     *
     * Es la lista de tipos complementarios de la ficha de producto. Antes esto
     * se resolvía con `str_contains` sobre el NOMBRE de la categoría
     * ('procesador', 'placa', 'tarjeta'…), o sea el mismo fallo que este enum
     * vino a cerrar en el armador, por la puerta de al lado: una tienda que
     * dijera "CPU" se quedaba sin sugerencias y sin aviso.
     *
     * Son parejas de montaje, no de venta cruzada agresiva: quien mira un
     * procesador necesita placa y memoria, y quien mira una GPU necesita fuente
     * y sitio donde meterla. Lo que no es pieza de armado no sugiere nada y cae
     * al respaldo de "lo más visto".
     *
     * @return array<int, self>
     */
    public function complementarios(): array
    {
        return match ($this) {
            self::Cpu         => [self::Motherboard, self::Ram],
            self::Motherboard => [self::Cpu, self::Ram],
            self::Ram         => [self::Motherboard, self::Cpu],
            self::Gpu         => [self::Psu, self::Chassis],
            self::Storage     => [self::Motherboard, self::Cpu],
            self::Psu         => [self::Gpu, self::Cpu],
            // El enfriamiento no tenía regla y ahora sí: se compra con el
            // procesador que va a enfriar y con el gabinete donde tiene que caber.
            self::Cooling     => [self::Cpu, self::Chassis],
            self::Chassis     => [self::Psu, self::Cooling],
            default           => [],
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cpu        => 'Procesadores (CPU)',
            self::Motherboard => 'Placas Madre (Motherboard)',
            self::Ram        => 'Memorias RAM',
            self::Gpu        => 'Tarjetas de Video (GPU)',
            self::Storage    => 'Almacenamiento (SSD/HDD)',
            self::Psu        => 'Fuentes de Poder',
            self::Cooling    => 'Enfriamiento / Disipadores',
            self::Chassis    => 'Gabinetes / Chasis',
            self::Monitor    => 'Monitores',
            self::Peripheral => 'Periféricos (Teclado/Mouse)',
            self::Other      => 'Otros / No es pieza de PC',
        };
    }

    /**
     * Deduce el tipo del nombre de la categoría.
     *
     * Es un apaño **de arranque**, no la fuente de verdad: sirve para rellenar
     * las categorías que ya existen y para las que nacen sin que nadie elija
     * (import CSV, clientes viejos de la API). Con el enum puesto, el dueño lo
     * corrige desde el panel y se acabó la adivinanza.
     *
     * El orden de las reglas importa. "Tarjetas de memoria" no es RAM y
     * "disco de estado sólido" no es un disipador, así que lo más específico va
     * primero y cada término se prueba entero.
     */
    public static function inferirDeNombre(?string $nombre): self
    {
        $n = mb_strtolower(trim((string) $nombre));

        if ($n === '') {
            return self::Other;
        }

        // Sin acentos: el dueño escribe "gráficas" o "graficas" según el día.
        $n = strtr($n, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);

        // Lista de pares y no un mapa con el enum de clave: una clave de array
        // solo puede ser int o string, y un caso de enum no lo es.
        $reglas = [
            [self::Gpu,        ['tarjeta de video', 'tarjeta grafica', 'tarjetas de video', 'tarjetas graficas', 'grafica', 'graficas', 'gpu', 'video']],
            [self::Motherboard, ['placa', 'motherboard', 'mainboard', 'mobo', 'tarjeta madre']],
            [self::Cpu,        ['procesador', 'microprocesador', 'cpu']],
            [self::Storage,    ['almacenamiento', 'disco', 'ssd', 'hdd', 'nvme', 'm.2']],
            [self::Ram,        ['memoria ram', 'memorias ram', 'memoria', 'ram', 'ddr']],
            [self::Psu,        ['fuente', 'psu', 'poder']],
            [self::Cooling,    ['enfriamiento', 'refrigeracion', 'disipador', 'cooler', 'ventilador', 'ventilacion']],
            [self::Chassis,    ['gabinete', 'chasis', 'case', 'torre', 'carcasa']],
            [self::Monitor,    ['monitor', 'monitores', 'pantalla']],
            [self::Peripheral, ['periferico', 'perifericos', 'teclado', 'mouse', 'raton', 'audifono', 'auricular', 'headset', 'microfono', 'webcam']],
        ];

        foreach ($reglas as [$tipo, $terminos]) {
            foreach ($terminos as $termino) {
                if (str_contains($n, $termino)) {
                    return $tipo;
                }
            }
        }

        return self::Other;
    }
}
