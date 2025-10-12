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
                    'content' => 'Extract customer details from the transcript: customerName, customerPhone (E.164 format if possible), callReason, and callbackTime. callbackTime must be an ISO 8601 date-time. Derive customerPhone from any spoken or provided digits. Summarize the main reason succinctly. Today\'s date is '.$today,
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
                            'customerName' => ['type' => 'string'],
                            'customerPhone' => ['type' => 'string'],
                            'callReason' => ['type' => 'string'],
                            'callbackTime' => ['type' => 'string'],
                        ],
                        'required' => ['customerName', 'customerPhone', 'callReason', 'callbackTime'],
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


