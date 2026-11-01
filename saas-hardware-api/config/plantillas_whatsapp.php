<?php

/*
|--------------------------------------------------------------------------
| Plantillas de los mensajes de WhatsApp (MOD-25)
|--------------------------------------------------------------------------
|
| Los diez textos que el sistema deja escritos para abrir WhatsApp: los tres
| que manda el COMPRADOR desde el catalogo (pedido, consulta, armado) y los
| siete que manda la TIENDA desde el panel (un pedido segun su estado, el
| recordatorio de pago, el contacto general y el aviso de que volvio el stock).
|
| Esta es la UNICA copia de los textos de fabrica. El frontend no tiene otra:
| el catalogo publico recibe ya resueltas las del comprador (con el tenant) y
| el panel las diez por `GET /api/whatsapp-templates`. Cada tienda guarda en
| `tenants.whatsapp_templates` solo las que cambio; lo demas sale de aqui, asi
| que mejorar un texto de fabrica llega a todas las que no lo tocaron.
|
| Los mensajes se RELLENAN en el navegador y no en el servidor a proposito:
| abrir WhatsApp despues de esperar una peticion es lo que el navegador
| bloquea como ventana emergente (`PcBuilderPage`, `PublicBuilderController`).
| Las reglas de rellenado viven en `utils/plantillasWhatsapp.ts`.
|
| Convenciones:
|
|   - Las CLAVES de plantilla y de variable van sin tildes: se guardan en la
|     base y renombrarlas deja huerfano lo que una tienda ya escribio.
|   - `grupo` es `comprador` (lo escribe el comprador y le llega a la tienda;
|     sale en el catalogo publico) o `tienda` (lo manda la tienda desde el
|     panel; NUNCA sale en el catalogo publico).
|   - `variables` es la lista de las que admite cada plantilla. Guardar un
|     texto con una variable que no esta en su lista se rechaza: un `{clinete}`
|     mal escrito le llegaria tal cual al cliente.
|   - Una linea cuyas variables quedan TODAS vacias no se manda: asi "Nota:
|     {nota}" desaparece cuando no hay nota, sin condicionales en el texto.
|
*/

return [

    /** Largo maximo de una plantilla. El texto viaja entero en la URL de wa.me. */
    'max' => 1000,

    /** Lo que significa cada variable, para el panel. */
    'variables' => [
        'tienda'    => 'El nombre de tu tienda',
        'cliente'   => 'El nombre del cliente',
        'pedido'    => 'El número del pedido, con su # (por ejemplo, #47)',
        'productos' => 'Los productos, uno por línea, con cantidad y precio',
        'total'     => 'Lo que paga el cliente, con la moneda',
        'entrega'   => 'Recojo en tienda, o delivery con su costo',
        'pagos'     => 'Tus métodos de pago activos, con sus datos',
        'nota'      => 'La nota que dejó el comprador',
        'producto'  => 'El nombre del producto, con la variante elegida',
        'precio'    => 'El precio del producto',
        'enlace'    => 'El enlace a la ficha del producto o al armado',
        'piezas'    => 'Las piezas del armado, una por línea, con su precio',
        'consumo'   => 'El consumo estimado del armado y la fuente recomendada',
    ],

    'plantillas' => [

        // ------------------------------------------- lo que escribe el comprador

        'pedido_nuevo' => [
            'grupo'     => 'comprador',
            'etiqueta'  => 'Pedido desde el carrito',
            'cuando'    => 'Lo envía el comprador al confirmar su pedido en el carrito, y te llega a ti.',
            'variables' => ['tienda', 'pedido', 'productos', 'total', 'cliente', 'entrega', 'nota', 'pagos'],
            'texto'     => "Hola {tienda}, quiero hacer este pedido:\n\n{productos}\n\n*Total: {total}*\n\nNombre: {cliente}\nEntrega: {entrega}\nNota: {nota}\n\nFormas de pago: {pagos}\n\n(Pedido {pedido})",
        ],

        'consulta_producto' => [
            'grupo'     => 'comprador',
            'etiqueta'  => 'Consulta desde la ficha de un producto',
            'cuando'    => 'Lo envía el comprador con el botón de WhatsApp de la ficha de un producto.',
            'variables' => ['tienda', 'producto', 'precio', 'enlace'],
            'texto'     => "Hola, me interesa el producto *{producto}* (precio: {precio}) de tu catálogo. ¿Está disponible?\n\nEnlace del producto: {enlace}",
        ],

        'armado_pc' => [
            'grupo'     => 'comprador',
            'etiqueta'  => 'Armado desde el armador de PC',
            'cuando'    => 'Lo envía el comprador desde el armador de PC, con las piezas que eligió.',
            'variables' => ['tienda', 'piezas', 'total', 'consumo', 'enlace'],
            'texto'     => "*¡Hola! Armé una PC desde tu catálogo:*\n\n{piezas}\n\n*Total estimado:* {total}\n*Consumo del sistema:* {consumo}\n*Ver el armado:* {enlace}\n\nPor favor, confírmenme stock y disponibilidad. ¡Gracias!",
        ],

        // ------------------------------------------------- lo que manda la tienda

        'pedido_recibido' => [
            'grupo'     => 'tienda',
            'etiqueta'  => 'Pedido recibido',
            'cuando'    => 'Lo mandas tú desde un pedido en estado «Pendiente».',
            'variables' => ['cliente', 'tienda', 'pedido', 'productos', 'total', 'entrega', 'pagos'],
            'texto'     => "Hola {cliente}, recibimos tu pedido {pedido} en {tienda}:\n\n{productos}\n\nTotal: {total}\n\nPronto empezamos a prepararlo.",
        ],

        'pedido_en_proceso' => [
            'grupo'     => 'tienda',
            'etiqueta'  => 'Pedido en preparación',
            'cuando'    => 'Lo mandas tú desde un pedido en estado «En proceso».',
            'variables' => ['cliente', 'tienda', 'pedido', 'productos', 'total', 'entrega', 'pagos'],
            'texto'     => "Hola {cliente}, tu pedido {pedido} ya se está preparando en {tienda}. Te avisamos apenas esté listo.",
        ],

        'pedido_listo' => [
            'grupo'     => 'tienda',
            'etiqueta'  => 'Pedido listo',
            'cuando'    => 'Lo mandas tú desde un pedido en estado «Atendido».',
            'variables' => ['cliente', 'tienda', 'pedido', 'productos', 'total', 'entrega', 'pagos'],
            'texto'     => "¡Hola {cliente}! Tu pedido {pedido} ya está listo en {tienda}.\n\n{productos}\n\nTotal: {total}\nEntrega: {entrega}\n\n¡Gracias por tu compra!",
        ],

        'pedido_cancelado' => [
            'grupo'     => 'tienda',
            'etiqueta'  => 'Pedido cancelado',
            'cuando'    => 'Lo mandas tú desde un pedido en estado «Cancelado».',
            'variables' => ['cliente', 'tienda', 'pedido', 'productos', 'total', 'entrega', 'pagos'],
            'texto'     => "Hola {cliente}, tu pedido {pedido} en {tienda} fue cancelado. Si tienes alguna duda, escríbenos por aquí.",
        ],

        'recordatorio_pago' => [
            'grupo'     => 'tienda',
            'etiqueta'  => 'Recordatorio de pago',
            'cuando'    => 'Lo eliges tú desde cualquier pedido, para recordarle al cliente que falta su pago.',
            'variables' => ['cliente', 'tienda', 'pedido', 'productos', 'total', 'entrega', 'pagos'],
            'texto'     => "Hola {cliente}, te escribimos de {tienda} por tu pedido {pedido} de {total}. Todavía no vemos tu pago.\n\nPuedes pagarnos por: {pagos}\n\nSi ya pagaste, mándanos el comprobante por aquí. ¡Gracias!",
        ],

        'contacto_pedido' => [
            'grupo'     => 'tienda',
            'etiqueta'  => 'Contacto sobre un pedido',
            'cuando'    => 'Lo eliges tú desde cualquier pedido, para escribirle al cliente sin un motivo fijo.',
            'variables' => ['cliente', 'tienda', 'pedido', 'productos', 'total', 'entrega', 'pagos'],
            'texto'     => 'Hola {cliente}, te escribo de {tienda} por tu pedido {pedido}.',
        ],

        'stock_disponible' => [
            'grupo'     => 'tienda',
            'etiqueta'  => 'Volvió el stock',
            'cuando'    => 'Lo mandas tú desde la lista de espera, a quien pidió que le avisaras.',
            'variables' => ['cliente', 'tienda', 'producto', 'enlace'],
            'texto'     => "Hola {cliente}, te escribimos de {tienda} porque {producto} volvió a estar disponible. Avísanos si todavía lo quieres y te lo separamos.\n\n{enlace}",
        ],
    ],
];
