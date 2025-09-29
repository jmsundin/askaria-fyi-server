<?php

namespace App\Services\OpenAI;

use Illuminate\Support\Facades\Log;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\Client\Exception as SwooleHttpClientException;

class RealtimeClientFactory
{
    public function create(): ?Client
    {
        $config = config('services.openai');

        $apiKey = (string) ($config['key'] ?? '');
        if ($apiKey === '') {
            Log::error('OpenAI realtime connection aborted: missing OPENAI_API_KEY configuration.');

            return null;
        }

        $model = (string) ($config['realtime_model'] ?? 'gpt-4o-realtime-preview-2024-10-01');

        if (! defined('SWOOLE_SSL')) {
            Log::error('OpenAI realtime connection aborted: Swoole extension missing OpenSSL support. Recompile Swoole with --enable-openssl.');

            return null;
        }

        try {
            $client = new Client('api.openai.com', 443, true);
        } catch (SwooleHttpClientException $exception) {
            Log::error('Failed to initialize OpenAI realtime client.', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
        $client->set(['timeout' => 5.0]);
        $client->setHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Host' => 'api.openai.com',
            'OpenAI-Beta' => 'realtime=v1',
            'User-Agent' => 'Askaria-Laravel-Octane',
        ]);

        $path = '/v1/realtime?model='.rawurlencode($model);
        if (! $client->upgrade($path)) {
            $errorCode = $client->errCode;
            $errorMessage = socket_strerror($errorCode) ?: 'unknown error';
            Log::error('Failed to upgrade OpenAI realtime connection', [
                'path' => $path,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);
            $client->close();

            return null;
        }

        return $client;
    }
}
