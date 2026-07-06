<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:url" content="{{ $url }}">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:title" content="{{ $title }}">
    <meta property="twitter:description" content="{{ $description }}">
    <meta property="twitter:image" content="{{ $image }}">
</head>
<body>
    <div style="font-family: sans-serif; max-width: 600px; margin: 2rem auto; text-align: center; padding: 1rem; color: #333;">
        @if($image)
            <img src="{{ $image }}" alt="{{ $title }}" style="max-width: 300px; max-height: 300px; width: auto; height: auto; border-radius: 8px; margin-bottom: 1.5rem; object-fit: contain;">
        @endif
        <h1>{{ $title }}</h1>
        <p style="color: #666; font-size: 1.1rem; line-height: 1.5;">{{ $description }}</p>
    </div>
</body>
</html>
