<?php

/*
|--------------------------------------------------------------------------
| Zonas horarias que puede elegir una tienda (MOD-13)
|--------------------------------------------------------------------------
|
| Fuente de verdad para la validacion de `tenants.timezone` y para agrupar las
| ventas por dia en los reportes (App\Support\Reportes).
|
| EL CRITERIO ES **DONDE HAY TIENDAS**, NO QUE MONEDA USAN.
|
| La primera version de esta lista se saco de config/currencies.php, y eso dejo
| fuera a Ecuador, Panama y El Salvador: los tres usan el dolar, asi que no
| tenian entrada propia en las monedas y desaparecieron de aqui. Un dueño de
| Guayaquil abria el selector y no encontraba su pais.
|
| Y la salida NO es que elija "Peru (Lima)" porque hoy coincidan en UTC-5.
| Ecuador tiene su propio identificador IANA (`America/Guayaquil`); que los dos
| desplazamientos coincidan es historia, no una garantia. El dia que uno de los
| dos cambiara sus reglas, la tienda del otro pais se moveria sola sin que nadie
| hubiera tocado nada, y nadie relacionaria el sintoma con la causa.
|
| Un pais con varios husos lleva **una entrada por huso**, no solo su capital:
| Mexico va de UTC-8 a UTC-6 y Brasil de UTC-5 a UTC-2.
|
| Sigue siendo una lista corta a proposito: PHP conoce mas de 400
| identificadores IANA y un selector con 400 entradas no ayuda a nadie a
| encontrar la suya. Al añadir un pais nuevo, la pregunta es "¿se vende alli?",
| no "¿tiene moneda propia?".
|
| OJO: el frontend tiene su propia copia en
| `saas-hardware-frontend/src/utils/timezones.ts`, porque necesita las
| etiquetas para el selector de Configuracion. Las dos listas tienen que tener
| LAS MISMAS CLAVES, y hay un test que lo comprueba
| (`ZonaHorariaDeLaTiendaTest::test_la_lista_del_frontend_no_se_ha_separado_de_esta`),
| ademas de otro que verifica que cada identificador existe de verdad: una
| errata en algo como `America/Argentina/Buenos_Aires` no se ve leyendo.
|
| Las etiquetas de aqui NO llevan el desplazamiento ("UTC-5") escrito: el
| selector lo calcula y lo pinta al vuelo, porque donde hay horario de verano
| cambia dos veces al año y un texto fijo mentiria media temporada.
|
| `UTC` va primero y es el valor por defecto: una tienda que no elige no cambia
| de comportamiento respecto a como estaba antes de MOD-13. El resto va
| alfabetico por pais, que es como lo busca quien lo busca.
|
*/

return [
    'UTC'                            => 'UTC (sin desplazamiento)',

    'America/Argentina/Buenos_Aires' => 'Argentina (Buenos Aires)',
    'America/La_Paz'                 => 'Bolivia (La Paz)',
    'America/Manaus'                 => 'Brasil (Manaos)',
    'America/Sao_Paulo'              => 'Brasil (São Paulo)',
    'America/Santiago'               => 'Chile (Santiago)',
    'America/Bogota'                 => 'Colombia (Bogotá)',
    'America/Costa_Rica'             => 'Costa Rica',
    'America/Havana'                 => 'Cuba (La Habana)',
    'America/Guayaquil'              => 'Ecuador (Guayaquil, Quito)',
    'Pacific/Galapagos'              => 'Ecuador (Galápagos)',
    'America/El_Salvador'            => 'El Salvador',
    'Europe/Madrid'                  => 'España (Madrid)',
    'America/Guatemala'              => 'Guatemala',
    'America/Tegucigalpa'            => 'Honduras (Tegucigalpa)',
    'America/Cancun'                 => 'México (Cancún)',
    'America/Mexico_City'            => 'México (Ciudad de México)',
    'America/Tijuana'                => 'México (Tijuana)',
    'America/Managua'                => 'Nicaragua (Managua)',
    'America/Panama'                 => 'Panamá',
    'America/Asuncion'               => 'Paraguay (Asunción)',
    'America/Lima'                   => 'Perú (Lima)',
    'America/Puerto_Rico'            => 'Puerto Rico',
    'America/Santo_Domingo'          => 'República Dominicana',
    'America/Montevideo'             => 'Uruguay (Montevideo)',
    'America/Caracas'                => 'Venezuela (Caracas)',
];
