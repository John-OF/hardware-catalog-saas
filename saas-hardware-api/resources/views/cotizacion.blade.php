{{--
    La cotización de un pedido en PDF (MOD-2).

    Escrita como HTML de 2005 a propósito: dompdf no entiende flexbox ni grid ni
    variables CSS, así que lo que aquí parezca anticuado es lo único que renderiza
    igual que en pantalla. Todo el CSS va en línea en esta misma vista porque el
    PDF se genera sin servidor de assets: una hoja externa no se cargaría.

    Los importes llegan ya formateados desde el controlador (`App\Support\Money`),
    para que la moneda de la tienda se escriba igual aquí que en el panel.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }} {{ $numero }}</title>
    <style>
        @page { margin: 26mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; }
        .cabecera { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .cabecera td { vertical-align: top; }
        .logo { max-height: 56px; max-width: 180px; }
        .tienda-nombre { font-size: 16px; font-weight: bold; color: #111827; }
        .tienda-dato { color: #6b7280; font-size: 10px; }
        .doc { text-align: right; }
        .doc-tipo { font-size: 13px; font-weight: bold; letter-spacing: 0.5px; color: #111827; }
        .doc-numero { font-size: 18px; font-weight: bold; color: {{ $color }}; }
        .doc-fecha { color: #6b7280; font-size: 10px; }

        .bloque { margin-bottom: 18px; }
        .bloque-titulo { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; margin-bottom: 4px; }

        table.lineas { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.lineas th {
            text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px;
            color: #6b7280; border-bottom: 1.5px solid #d1d5db; padding: 0 0 6px;
        }
        table.lineas td { padding: 7px 0; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .variante { color: #6b7280; font-size: 9.5px; }

        table.totales { width: 46%; border-collapse: collapse; float: right; }
        table.totales td { padding: 3px 0; }
        table.totales td.num { white-space: nowrap; }
        tr.total-final td {
            border-top: 1.5px solid #d1d5db; padding-top: 7px;
            font-size: 13px; font-weight: bold; color: #111827;
        }

        .nota { clear: both; padding-top: 26px; color: #6b7280; font-size: 9.5px; line-height: 1.5; }
        .aviso { margin-top: 10px; padding: 7px 9px; background: #f9fafb; border-left: 3px solid #d1d5db; color: #4b5563; }
    </style>
</head>
<body>

<table class="cabecera">
    <tr>
        <td width="60%">
            @if ($tienda->logo_url)
                {{-- Si la imagen no se puede descargar, dompdf deja el hueco y no
                     rompe el documento: el nombre de abajo sigue identificando la
                     tienda. --}}
                <img class="logo" src="{{ $tienda->logo_url }}" alt="">
                <br>
            @endif
            <span class="tienda-nombre">{{ $tienda->name }}</span>
            @if ($tienda->whatsapp_number)
                <br><span class="tienda-dato">WhatsApp {{ $tienda->whatsapp_number }}</span>
            @endif
            <br><span class="tienda-dato">{{ $urlTienda }}</span>
        </td>
        <td class="doc">
            <span class="doc-tipo">{{ mb_strtoupper($titulo) }}</span><br>
            <span class="doc-numero">{{ $numero }}</span><br>
            <span class="doc-fecha">{{ $fecha }}</span>
        </td>
    </tr>
</table>

<div class="bloque">
    <div class="bloque-titulo">Cliente</div>
    <strong>{{ $pedido->customer_name }}</strong>
    @if ($pedido->customer_phone)<br>{{ $pedido->customer_phone }}@endif
    @if ($pedido->customer_email)<br>{{ $pedido->customer_email }}@endif
</div>

<table class="lineas">
    <thead>
        <tr>
            <th>Descripción</th>
            <th class="num" width="8%">Cant.</th>
            <th class="num" width="18%">P. unitario</th>
            <th class="num" width="18%">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($pedido->items as $linea)
            <tr>
                <td>
                    {{ $linea->product_name }}
                    @if ($linea->variant_name)
                        <br><span class="variante">{{ $linea->variant_name }}</span>
                    @endif
                </td>
                <td class="num">{{ $linea->quantity }}</td>
                <td class="num">{{ $importes['lineas'][$loop->index]['unitario'] }}</td>
                <td class="num">{{ $importes['lineas'][$loop->index]['subtotal'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totales">
    {{-- MOD-4: solo si esta venta llevo cupon. Se nombra el codigo: una
         cotizacion con un descuento sin explicar invita a preguntar por que. --}}
    @if ($pedido->discount_amount !== null)
        <tr>
            <td>Subtotal</td>
            <td class="num">{{ $importes['subtotal'] }}</td>
        </tr>
        <tr>
            <td>Descuento{{ $pedido->coupon_code ? ' ('.$pedido->coupon_code.')' : '' }}</td>
            <td class="num">-{{ $importes['descuento'] }}</td>
        </tr>
    @endif

    @if ($pedido->delivery_cost > 0)
        <tr>
            <td>Envío</td>
            <td class="num">{{ $importes['envio'] }}</td>
        </tr>
    @endif

    {{-- El desglose solo aparece si esta venta llevó impuesto. En un pedido de
         antes de MOD-2, o de una tienda que no lo cobra, la cotización enseña el
         total y nada más, que es lo honesto: inventar una línea "IGV 0,00" diría
         que se cobró un impuesto del cero por ciento. --}}
    @if ($pedido->tax_amount !== null)
        <tr>
            <td>Op. gravada</td>
            <td class="num">{{ $importes['base'] }}</td>
        </tr>
        <tr>
            <td>{{ $pedido->tax_name }} ({{ rtrim(rtrim(number_format((float) $pedido->tax_rate, 2, ',', ''), '0'), ',') }}%)</td>
            <td class="num">{{ $importes['impuesto'] }}</td>
        </tr>
    @endif

    <tr class="total-final">
        <td>TOTAL</td>
        <td class="num">{{ $importes['total'] }}</td>
    </tr>
</table>

<div class="nota">
    @if ($pedido->customer_note)
        <strong>Nota:</strong> {{ $pedido->customer_note }}<br><br>
    @endif

    {{-- Lo que hace honesto a este documento. Llevar el logo y el desglose del
         impuesto lo hace PARECER un comprobante fiscal, y no lo es: para eso hay
         que firmarlo y declararlo ante la autoridad tributaria con un proveedor
         autorizado. Decirlo en el papel es lo que impide que alguien lo entregue
         creyendo que sirve para eso. --}}
    <div class="aviso">
        Documento interno de la tienda, emitido para cotizar y dejar constancia de la operación.
        <strong>No es un comprobante de pago electrónico</strong> ni tiene validez tributaria.
    </div>
</div>

</body>
</html>
