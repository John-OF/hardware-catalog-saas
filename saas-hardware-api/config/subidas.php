<?php

/*
|--------------------------------------------------------------------------
| Lo que el sistema acepta subir (UI-15)
|--------------------------------------------------------------------------
|
| Cuánto puede pesar cada cosa y de qué tipo. Vive aquí, y no escrito en cada
| regla, porque el mismo número lo necesita el navegador para avisar ANTES de
| subir: `saas-hardware-frontend/src/utils/subidas.ts` es la copia, y
| `LimitesDeSubidaTest` falla si las dos se separan. Antes cada regla llevaba
| su `max:10240` y el formulario de producto decía "Máx 5MB" mientras el
| servidor aceptaba 10.
|
| `kb` va en kilobytes porque es lo que espera la regla `max` de Laravel para
| un archivo; lo que se le enseña a una persona va en MB
| (`App\Support\Subidas::legible()`). `tipos` son las extensiones de la regla
| `mimes`, que el servidor comprueba por el CONTENIDO del archivo, no por su
| nombre.
|
| Subir un tope aquí no basta: PHP y el servidor web tienen los suyos y cortan
| antes (`INF-12`).
|
*/

return [

    // Foto principal, galería y fotos de variantes: las tres son del producto.
    'imagen' => ['kb' => 10240, 'tipos' => ['jpeg', 'jpg', 'png', 'webp']],

    'logo' => ['kb' => 2048, 'tipos' => ['jpeg', 'jpg', 'png', 'webp']],

    'banner' => ['kb' => 5120, 'tipos' => ['jpeg', 'jpg', 'png', 'webp']],

    // Sin la regla `image`, que no reconoce el .ico.
    'favicon' => ['kb' => 512, 'tipos' => ['png', 'ico']],

    'csv' => ['kb' => 4096, 'tipos' => ['csv', 'txt']],

];
