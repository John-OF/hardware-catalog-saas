<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Anti-bot de las resenias publicas (SEC-5).
     *
     * Deliberadamente SIN valor por defecto: antes se leia con
     * env('TURNSTILE_SECRET_KEY', '1x0000...AA') y ese fallback es la clave de
     * prueba de Cloudflare, que aprueba cualquier token. Con config:cache activo
     * env() devuelve null en runtime, asi que produccion caia al fallback y se
     * quedaba sin anti-bot en silencio. Si falta la clave preferimos fallar.
     */
    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET_KEY'),
    ],

];
