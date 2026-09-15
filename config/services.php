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

    // Lee de .env los datos necesarios para conectar el calendario institucional.
    'google_calendar' => [
        // Identifican esta aplicación al solicitar acceso a Google.
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        // Dirección del sistema a la que Google devuelve la autorización.
        'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
        // Calendario donde se guardan las visitas técnicas.
        'calendar_id' => env('GOOGLE_CALENDAR_ID'),
        'timezone' => 'America/La_Paz',
    ],

    // Clave para consultar mapas y direcciones en TomTom; coordenadas del centro inicial.
    'tomtom' => [
        'api_key' => env('TOMTOM_API_KEY'),
        'default_latitude' => (float) env('TOMTOM_DEFAULT_LATITUDE', -17.3975),
        'default_longitude' => (float) env('TOMTOM_DEFAULT_LONGITUDE', -66.0387),
    ],

];
