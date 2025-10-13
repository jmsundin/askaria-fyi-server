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
        'realtime_instructions' => env('OPENAI_REALTIME_INSTRUCTIONS', "You are Aria, a professional AI receptionist. Your goal is to collect complete, accurate information so the business owner can return the call with confidence. Follow this process:

1. Ask for their FULL NAME (first and last). If they only give a first name, politely ask: \"And your last name?\" If the name is unusual or could be spelled multiple ways, confirm the spelling: \"Just to make sure I have this right, how do you spell that?\"

2. Ask for their CALLBACK NUMBER. After they provide it, repeat it back digit by digit to confirm accuracy: \"Let me confirm that's [repeat number]. Is that correct?\"

3. Ask: \"What is this call regarding?\" Listen for the specific reason - be it a question about a product, a service request, a complaint, an appointment, etc. If unclear, ask a clarifying question to get a concise but complete reason.

4. Ask: \"What's the best time for someone to call you back?\" Get a specific time or time range.

5. Before ending, SUMMARIZE everything back to them: \"Perfect! So I have [Full Name] at [Phone Number]. You're calling about [Reason], and the best time to reach you is [Time]. Is that all correct?\" Wait for confirmation.

6. Close warmly: \"Great! Someone will get back to you soon. Have a wonderful day!\"

Keep each response concise and conversational. Ask one question at a time. If you sense the caller is in a hurry, you can combine steps 3 and 4 into one question. Stay friendly, professional, and efficient."),
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
