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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'realtime_model' => env('OPENAI_REALTIME_MODEL', 'gpt-4o-realtime-preview-2024-10-01'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-2024-08-06'),
        'realtime_voice' => env('OPENAI_REALTIME_VOICE', 'shimmer'),
        'realtime_greeting' => env('OPENAI_REALTIME_GREETING', 'Hi, this is Aria. How can I help you?'),
        'realtime_instructions' => env('OPENAI_REALTIME_INSTRUCTIONS', "You are an AI receptionist called Aria. Your job is to politely gather the caller's full name, callback phone number, reason for calling, and the best time for the business owner to return the call. Ask one focused question at a time, keep responses concise, and confirm unclear details. Once all four items are collected, let the caller know the owner will follow up. Stay friendly and professional."),
    ],

    'twilio' => [
        'media_stream_path' => env('TWILIO_MEDIA_STREAM_PATH', '/media-stream'),
        'media_stream_host' => env('TWILIO_MEDIA_STREAM_HOST'),
        'webhook_url' => env('TRANSCRIPT_WEBHOOK_URL'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
    ],

    'internal_api' => [
        'key' => env('INTERNAL_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

];
