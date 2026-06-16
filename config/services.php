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

    'gemini' => [
        'key'   => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
    ],

    'openai' => [
        'key'   => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'mistral' => [
        'key'          => env('MISTRAL_API_KEY'),
        'model'        => env('MISTRAL_MODEL', 'mistral-small-latest'),
        'vision_model' => env('MISTRAL_VISION_MODEL', 'pixtral-12b-2409'),
    ],

    'llm' => [
        'provider' => env('LLM_PROVIDER', 'gemini'),
    ],

    'browser' => [
        'timeout' => env('BROWSER_RENDER_TIMEOUT', 60),
        'render_delay_ms' => env('BROWSER_RENDER_DELAY_MS', 5000),
    ],

    'artifact' => [
        'http_timeout' => env('ARTIFACT_HTTP_TIMEOUT', 30),
        'min_pdf_bytes' => env('ARTIFACT_MIN_PDF_BYTES', 1500),
        'min_image_bytes' => env('ARTIFACT_MIN_IMAGE_BYTES', 3000),
        'min_image_side' => env('ARTIFACT_MIN_IMAGE_SIDE', 350),
        'min_text_chars' => env('ARTIFACT_MIN_TEXT_CHARS', 500),
    ],

];
