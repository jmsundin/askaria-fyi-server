<?php

namespace App\Services\OpenAI;

use App\Models\Call;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranscriptProcessor
{
    public function processAndSendTranscript(string $transcript, ?string $sessionId): void
    {
        $config = config('services.openai');
        $apiKey = (string) ($config['key'] ?? '');
        $model = (string) ($config['chat_model'] ?? 'gpt-4o-2024-08-06');
        $webhookUrl = config('services.twilio.webhook_url');

        if ($transcript === '') {
            Log::warning('Transcript processor received empty transcript.', ['session_id' => $sessionId]);
            return;
        }

        if ($apiKey === '' || empty($webhookUrl)) {
            Log::warning('Transcript processor missing configuration.', [
                'session_id' => $sessionId,
                'has_api_key' => $apiKey !== '',
                'has_webhook' => ! empty($webhookUrl),
            ]);
            return;
        }

        $payload = $this->buildChatCompletionPayload($transcript, $model);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        if ($response->failed()) {
            Log::error('Chat completion API call failed.', [
                'session_id' => $sessionId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return;
        }

        $parsed = $this->extractJsonPayload($response->json(), $sessionId);
        if ($parsed === null) {
            return;
        }

        if ($sessionId !== null) {
            $call = Call::query()
                ->where('session_id', $sessionId)
                ->first();

            if ($call !== null) {
                $call->summary = $parsed;
                $call->transcript_text = $transcript;
                $call->save();
            }
        }

        $webhookResponse = Http::acceptJson()
            ->asJson()
            ->post($webhookUrl, $parsed);

        if ($webhookResponse->failed()) {
            Log::error('Failed to forward transcript details to webhook.', [
                'session_id' => $sessionId,
                'status' => $webhookResponse->status(),
                'body' => $webhookResponse->body(),
            ]);
            return;
        }

        Log::info('Transcript details forwarded to webhook.', [
            'session_id' => $sessionId,
        ]);
    }

    protected function buildChatCompletionPayload(string $transcript, string $model): array
    {
        $today = now()->toIso8601String();

        return [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Extract detailed customer information from the call transcript. Pay special attention to:

- callerName: The caller\'s FULL NAME (first and last name). If only first or last is provided, include what you have. This is critical for personalization.
- callbackPhone: The callback phone number in E.164 format if possible (e.g., +12345678900). Extract from spoken or provided digits.
- reason: A clear, concise summary (1-2 sentences) of WHY they called. Be specific - include what product/service they\'re asking about, what they need, or what issue they have.
- callbackTime: When they want to be called back as an ISO 8601 date-time. If they said "afternoon", "morning", or a time range, convert it to a specific datetime. Today\'s date is '.$today.'
- notes: Any additional important context, special requests, account numbers mentioned, urgency indicators, or details that would help the business owner prepare for the callback.
- urgency: Rate the urgency as "high", "medium", or "low" based on the caller\'s tone and situation.

Be thorough - missing information could mean a poor callback experience.',
                ],
                [
                    'role' => 'user',
                    'content' => $transcript,
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'customer_details_extraction',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'callerName' => [
                                'type' => 'string',
                                'description' => 'Full name of the caller (first and last name)',
                            ],
                            'callbackPhone' => [
                                'type' => 'string',
                                'description' => 'Callback phone number in E.164 format',
                            ],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Clear, specific reason for the call',
                            ],
                            'callbackTime' => [
                                'type' => 'string',
                                'description' => 'ISO 8601 datetime for callback',
                            ],
                            'notes' => [
                                'type' => 'string',
                                'description' => 'Additional context or important details',
                            ],
                            'urgency' => [
                                'type' => 'string',
                                'enum' => ['high', 'medium', 'low'],
                                'description' => 'Urgency level of the callback',
                            ],
                        ],
                        'required' => ['callerName', 'callbackPhone', 'reason', 'callbackTime', 'notes', 'urgency'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    protected function extractJsonPayload(array $response, ?string $sessionId): ?array
    {
        $choices = $response['choices'] ?? null;
        if (! is_array($choices) || count($choices) === 0) {
            Log::warning('Unexpected chat completion response: missing choices.', ['session_id' => $sessionId]);
            return null;
        }

        $message = $choices[0]['message']['content'] ?? null;
        if (! is_string($message) || $message === '') {
            Log::warning('Chat completion message missing content.', ['session_id' => $sessionId]);
            return null;
        }

        try {
            $decoded = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            Log::error('Failed to decode chat completion response.', [
                'session_id' => $sessionId,
                'error' => $exception->getMessage(),
                'message' => $message,
            ]);
            return null;
        }

        return $decoded;
    }
}


