{{--
    Lo que ve un crawler de una tienda (`INF-4`).

    Se llamaba así porque al principio sólo servía para la vista previa de
    WhatsApp: etiquetas `og:`, un `<h1>` y nada más. Eso a un buscador le parece
    una página vacía y, peor, un callejón sin salida: sin un solo enlace no hay
    nada que rastrear detrás. Ahora lleva contenido, enlaces y JSON-LD, y sigue
    valiendo para lo de antes porque las `og:` no se han tocado.

    HTML plano y CSS en línea a propósito: esto lo pide un robot, no un
    navegador, y no hay servidor de assets detrás. Lo que importa es que el
    contenido esté en el HTML sin ejecutar JavaScript, que es exactamente lo que
    el SPA no puede ofrecer.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">

    {{-- La URL que hay que indexar. Con dominio propio es esta misma; con slug
         es la del frontend, que es la que la gente comparte y a la que sale
         redirigido un humano (ver `App\Support\Seo`). --}}
    <link rel="canonical" href="{{ $canonical ?? $url }}">
    <meta name="robots" content="index, follow">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="{{ isset($producto) ? 'product' : 'website' }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:url" content="{{ $url }}">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:title" content="{{ $title }}">
    <meta property="twitter:description" content="{{ $description }}">
    <meta property="twitter:image" content="{{ $image }}">

    {{-- JSON-LD: es lo que produce el resultado enriquecido de Google (precio,
         disponibilidad, marca debajo del enlace). Va con `JSON_UNESCAPED_*` para
         que los acentos y las barras de las URL no salgan escapados, que es
         válido pero ilegible al depurarlo. --}}
    @foreach ($jsonLd ?? [] as $bloque)
        <script type="application/ld+json">{!! json_encode($bloque, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endforeach

    <style>
        body { font-family: system-ui, sans-serif; max-width: 900px; margin: 2rem auto; padding: 1rem; color: #1f2937; line-height: 1.5; }
        header { text-align: center; margin-bottom: 2rem; }
        header img { max-width: 240px; max-height: 240px; border-radius: 8px; }
        h1 { font-size: 1.6rem; }
        .lead { color: #4b5563; }
        ul.catalogo { list-style: none; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 0.75rem; }
        ul.catalogo li { border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.75rem; }
        ul.catalogo a { color: #111827; font-weight: 600; text-decoration: none; }
        .precio { color: #4b5563; font-size: 0.9rem; }
        .ficha dt { font-weight: 600; margin-top: 0.4rem; }
        .ficha dd { margin: 0 0 0 1rem; color: #4b5563; }
        nav.paginas { margin-top: 2rem; border-top: 1px solid #e5e7eb; padding-top: 1rem; font-size: 0.9rem; }
        nav.paginas a { color: #4b5563; margin-right: 0.75rem; }
    </style>
</head>
<body>

<header>
    @if ($image)
        <img src="{{ $image }}" alt="{{ $title }}">
    @endif
    <h1>{{ $title }}</h1>
    <p class="lead">{{ $description }}</p>
</header>

@isset($producto)
    <section>
        <p>
            <strong>{{ \App\Support\Money::format($producto->sale_price ?? $producto->price, $tienda->currency) }}</strong>
            @if ($producto->sale_price)
                <span class="precio"><s>{{ \App\Support\Money::format($producto->price, $tienda->currency) }}</s></span>
            @endif
            — {{ (int) $producto->stock > 0 ? 'Disponible' : 'Agotado' }}
        </p>

        @if ($producto->brand)
            <p class="precio">Marca: {{ $producto->brand }}</p>
        @endif

        {{-- Las specs son lo que de verdad busca alguien en este rubro
             ("socket AM5", "DDR5 6000"), así que van en el HTML y no sólo en la
             ficha del SPA. --}}
        @if (! empty($producto->specs))
            <dl class="ficha">
                @foreach ($producto->specs as $clave => $valor)
                    <dt>{{ $clave }}</dt>
                    <dd>{{ is_array($valor) ? implode(', ', $valor) : $valor }}</dd>
                @endforeach
            </dl>
        @endif
    </section>
@endisset

@isset($cuerpo)
    {{-- El HTML de una página informativa ya viene saneado al guardar (SEC-3). --}}
    <section>{!! $cuerpo !!}</section>
@endisset

@if (! empty($enlaces))
    <section>
        <h2>{{ isset($producto) || isset($cuerpo) ? 'Seguir mirando' : 'Productos' }}</h2>
        <ul class="catalogo">
            @foreach ($enlaces as $enlace)
                <li>
                    <a href="{{ $enlace['url'] }}">{{ $enlace['texto'] }}</a>
                    @isset($enlace['precio'])
                        <div class="precio">{{ $enlace['precio'] }}</div>
                    @endisset
                </li>
            @endforeach
        </ul>
    </section>
@endif

@if (! empty($paginas))
    <nav class="paginas">
        @foreach ($paginas as $pagina)
            <a href="{{ $pagina['url'] }}">{{ $pagina['texto'] }}</a>
        @endforeach
    </nav>
@endif

</body>
</html>
