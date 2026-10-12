<?php

/*
|--------------------------------------------------------------------------
| Specs con nombre fijo por tipo de componente (FUN-8, Fase 13 — paso 13.2)
|--------------------------------------------------------------------------
|
| Hasta aqui las specs eran pares clave/valor de texto libre, y el armador las
| buscaba por aproximacion ('socket', 'zócalo', 'zocalo'...). Esta es la lista de
| lo que se PREGUNTA en vez de adivinarlo. La decidio el paso 13.1, contrastada
| con trece fichas oficiales de fabricante; el porque de cada campo esta alli.
|
| Vive en config y no en una tabla por lo mismo que `plans.php`: cambia por una
| decision de producto, no por un cambio de esquema, y un test la fija con
| config()->set(). El frontend NO tiene copia: la pide a
| `GET /api/public/component-specs`, asi que añadir un campo aqui no obliga a
| tocarlo.
|
| Como se guarda: en la misma columna `products.specs`, con la CLAVE de aqui
| (`socket`, `tdp`...) y el valor siempre como texto —`"105"`, no `105`—,
| porque los filtros del catalogo comparan texto con texto y un numero en el
| JSON no casaria con el que llega por la URL. Lo normaliza y lo valida
| `App\Support\SpecsFijas`.
|
| OJO al renombrar una clave: es el nombre con el que esta guardado el dato.
| Cambiarla deja huerfano todo lo que ya se escribio con la vieja, igual que
| renombrar una columna sin migrarla. La `etiqueta`, en cambio, se cambia libre.
|
| Una clave que aparece en varios tipos (`socket`, `formato`, `capacidad`...)
| tiene que tener la misma etiqueta, el mismo tipo de valor y la misma unidad
| en todos: la ficha publica la pinta sin saber de que tipo es el producto.
| `SpecsFijasTest` lo comprueba.
|
| Tipos de valor:
|   - lista   -> uno de `opciones` (el nombre de una de las `listas`)
|   - varios  -> varios de `opciones`, guardados como "AM4, AM5" en el orden de la lista
|   - numero  -> entero >= 0, en `unidad`
|   - si_no   -> "si" o "no"
|   - texto   -> libre
|
| `armador` dice si el campo alimenta una regla del armador (13.5). Que falte
| nunca impide guardar el producto: el armador dira "no comprobado".
|
*/

return [

    'listas' => [
        'sockets'          => ['AM4', 'AM5', 'LGA1200', 'LGA1700', 'LGA1851', 'LGA1954'],
        'tipos_de_memoria' => ['DDR4', 'DDR5'],
        'formatos_de_placa' => ['E-ATX', 'ATX', 'Micro-ATX', 'Mini-ITX'],
        'formatos_de_ram'  => ['DIMM', 'SO-DIMM'],
        'formatos_de_fuente' => ['ATX', 'SFX', 'SFX-L'],
        'conectores_de_gpu' => ['Ninguno', '1 conector PCIe', '2 conectores PCIe', '3 conectores PCIe', '12V-2x6'],
        'interfaces_de_disco' => ['M.2 NVMe', 'M.2 SATA', 'SATA 2.5"', 'SATA 3.5"'],
        'certificaciones'  => ['Ninguna', '80 Plus White', '80 Plus Bronze', '80 Plus Gold', '80 Plus Platinum', '80 Plus Titanium'],
        'modularidad'      => ['No', 'Semi', 'Total'],
        'refrigeraciones'  => ['Aire', 'Líquida (AIO)'],
        'radiadores'       => ['120', '240', '280', '360', '420'],
    ],

    'tipos' => [

        'cpu' => [
            'socket' => ['etiqueta' => 'Socket', 'tipo' => 'lista', 'opciones' => 'sockets', 'armador' => true],
            'tdp' => [
                'etiqueta' => 'TDP / potencia base', 'tipo' => 'numero', 'unidad' => 'W', 'armador' => true,
                'ayuda' => 'AMD lo llama Default TDP; Intel, Processor Base Power.',
            ],
            'graficos_integrados' => [
                'etiqueta' => 'Gráficos integrados', 'tipo' => 'si_no', 'armador' => true,
                'ayuda' => 'Sin ellos, la PC no da imagen si no lleva tarjeta de video.',
            ],
            'incluye_disipador' => [
                'etiqueta' => 'Incluye disipador', 'tipo' => 'si_no', 'armador' => true,
                'ayuda' => 'Lo dice la caja que vendes, no la ficha: el mismo procesador se vende con disipador (en caja) y sin él (en bandeja).',
            ],
            'nucleos' => ['etiqueta' => 'Núcleos', 'tipo' => 'numero', 'armador' => false],
            'hilos'   => ['etiqueta' => 'Hilos', 'tipo' => 'numero', 'armador' => false],
        ],

        'motherboard' => [
            'socket'       => ['etiqueta' => 'Socket', 'tipo' => 'lista', 'opciones' => 'sockets', 'armador' => true],
            'tipo_memoria' => ['etiqueta' => 'Tipo de memoria', 'tipo' => 'lista', 'opciones' => 'tipos_de_memoria', 'armador' => true],
            'slots_ram'    => ['etiqueta' => 'Slots de RAM', 'tipo' => 'numero', 'armador' => true],
            'formato'      => ['etiqueta' => 'Formato', 'tipo' => 'lista', 'opciones' => 'formatos_de_placa', 'armador' => true],
            'slots_m2'     => ['etiqueta' => 'Slots M.2', 'tipo' => 'numero', 'armador' => true],
            'chipset'      => ['etiqueta' => 'Chipset', 'tipo' => 'texto', 'armador' => false],
            'wifi'         => ['etiqueta' => 'Wi-Fi', 'tipo' => 'si_no', 'armador' => false],
        ],

        'ram' => [
            'tipo_memoria' => ['etiqueta' => 'Tipo de memoria', 'tipo' => 'lista', 'opciones' => 'tipos_de_memoria', 'armador' => true],
            'formato' => [
                'etiqueta' => 'Formato', 'tipo' => 'lista', 'opciones' => 'formatos_de_ram', 'armador' => true,
                'ayuda' => 'DIMM (o UDIMM) es la de escritorio; SO-DIMM, la de laptop.',
            ],
            'modulos' => [
                'etiqueta' => 'Módulos', 'tipo' => 'numero', 'armador' => true,
                'ayuda' => 'Los del kit: un kit de 2×16 GB son 2.',
            ],
            'capacidad' => [
                'etiqueta' => 'Capacidad', 'tipo' => 'numero', 'unidad' => 'GB', 'armador' => false,
                'ayuda' => 'La del kit entero.',
            ],
            'velocidad' => [
                'etiqueta' => 'Velocidad', 'tipo' => 'numero', 'unidad' => 'MT/s', 'armador' => false,
                'ayuda' => 'Muchas tiendas la escriben en MHz: es el mismo número.',
            ],
        ],

        'gpu' => [
            'largo' => [
                'etiqueta' => 'Largo', 'tipo' => 'numero', 'unidad' => 'mm', 'armador' => true,
                'ayuda' => 'El de este modelo, no el del chip: dos RTX 5070 pueden medir 242 y 304 mm.',
            ],
            'consumo' => [
                'etiqueta' => 'Consumo', 'tipo' => 'numero', 'unidad' => 'W', 'armador' => true,
                'ayuda' => 'NVIDIA lo llama Total Graphics Power; AMD, Typical Board Power.',
            ],
            'fuente_recomendada' => ['etiqueta' => 'Fuente recomendada', 'tipo' => 'numero', 'unidad' => 'W', 'armador' => true],
            'conector_energia'   => ['etiqueta' => 'Conector de energía', 'tipo' => 'lista', 'opciones' => 'conectores_de_gpu', 'armador' => true],
            'vram'               => ['etiqueta' => 'VRAM', 'tipo' => 'numero', 'unidad' => 'GB', 'armador' => false],
        ],

        'ssd' => [
            'interfaz'  => ['etiqueta' => 'Interfaz', 'tipo' => 'lista', 'opciones' => 'interfaces_de_disco', 'armador' => true],
            'capacidad' => ['etiqueta' => 'Capacidad', 'tipo' => 'numero', 'unidad' => 'GB', 'armador' => false],
        ],

        'power' => [
            'potencia' => ['etiqueta' => 'Potencia', 'tipo' => 'numero', 'unidad' => 'W', 'armador' => true],
            'conectores_pcie' => [
                'etiqueta' => 'Conectores PCIe', 'tipo' => 'numero', 'armador' => true,
                'ayuda' => 'Los de 6+2 pines.',
            ],
            'conector_12v2x6' => ['etiqueta' => 'Conector 12V-2x6', 'tipo' => 'si_no', 'armador' => true],
            'formato'         => ['etiqueta' => 'Formato', 'tipo' => 'lista', 'opciones' => 'formatos_de_fuente', 'armador' => true],
            'certificacion'   => ['etiqueta' => 'Certificación', 'tipo' => 'lista', 'opciones' => 'certificaciones', 'armador' => false],
            'modular'         => ['etiqueta' => 'Modular', 'tipo' => 'lista', 'opciones' => 'modularidad', 'armador' => false],
        ],

        'cooling' => [
            'refrigeracion' => ['etiqueta' => 'Refrigeración', 'tipo' => 'lista', 'opciones' => 'refrigeraciones', 'armador' => true],
            'sockets' => [
                'etiqueta' => 'Sockets compatibles', 'tipo' => 'varios', 'opciones' => 'sockets', 'armador' => true,
                'ayuda' => 'Los que soporta con lo que trae la caja, sin kits aparte.',
            ],
            'altura' => [
                'etiqueta' => 'Altura', 'tipo' => 'numero', 'unidad' => 'mm', 'armador' => true,
                'ayuda' => 'Solo si es de aire.',
            ],
            'radiador' => [
                'etiqueta' => 'Radiador', 'tipo' => 'lista', 'opciones' => 'radiadores', 'unidad' => 'mm', 'armador' => true,
                'ayuda' => 'Solo si es líquida.',
            ],
        ],

        'case' => [
            'formatos_placa' => ['etiqueta' => 'Formatos de placa', 'tipo' => 'varios', 'opciones' => 'formatos_de_placa', 'armador' => true],
            'largo_max_gpu'  => ['etiqueta' => 'Largo máx. de GPU', 'tipo' => 'numero', 'unidad' => 'mm', 'armador' => true],
            'altura_max_disipador' => [
                'etiqueta' => 'Altura máx. de disipador', 'tipo' => 'numero', 'unidad' => 'mm', 'armador' => true,
                'ayuda' => 'Si la ficha da varias, la menor con lo que trae de fábrica.',
            ],
            'radiador_max' => [
                'etiqueta' => 'Radiador máx.', 'tipo' => 'numero', 'unidad' => 'mm', 'armador' => true,
                'ayuda' => 'El mayor de todas las posiciones; 0 si no admite radiadores.',
            ],
            'formatos_fuente' => ['etiqueta' => 'Formatos de fuente', 'tipo' => 'varios', 'opciones' => 'formatos_de_fuente', 'armador' => true],
        ],

    ],

];
