<?php

namespace App\WebSocket;

use Illuminate\Support\Facades\Log;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class TwilioProxyHandler
{
    /**
     * Map of Twilio connection file descriptors to OpenAI coroutine clients.
     *
     * @var array<int, HttpClient>
     */
    protected array $connections = [];

    /**
     * Handle new WebSocket connection from Twilio.
     */
    public function onOpen(Server $server, Request $request): void
    {
        $twilioFd = $request->fd;

        Log::info('Twilio WebSocket connection opened', ['fd' => $twilioFd]);

        // Example: extract query params if needed
        // $businessId = $request->get['businessId'] ?? null;

        $openai = new HttpClient('api.openai.com', 443, true);
        $headers = [
            'Host' => 'api.openai.com',
            'User-Agent' => 'Askaria-Laravel-Octane',
            // 'Authorization' => 'Bearer '.config('services.openai.key'),
            // Add any other headers required by your upstream
        ];

        foreach ($headers as $name => $value) {
            $openai->setHeaders([$name => $value]);
        }

        $upgraded = $openai->upgrade('/v1/realtime'); // Placeholder path

        if ($upgraded) {
            $this->connections[$twilioFd] = $openai;

            Log::info('Connected upstream for Twilio connection', ['fd' => $twilioFd]);

            \Swoole\Coroutine::create(function () use ($server, $twilioFd, $openai) {
                $this->proxyToOpenAi($server, $twilioFd, $openai);
            });

            \Swoole\Coroutine::create(function () use ($server, $twilioFd, $openai) {
                $this->proxyToTwilio($server, $twilioFd, $openai);
            });
        } else {
            Log::error('Failed to upgrade upstream connection; closing Twilio connection', ['fd' => $twilioFd]);
            $server->close($twilioFd);
        }
    }

    /**
     * Handle incoming message from Twilio.
     * The continuous proxy loop handles messages; nothing to do here.
     */
    public function onMessage(Server $server, Frame $frame): void
    {
        // Intentionally left blank: handled by coroutine proxy loops
    }

    /**
     * Proxy messages from Twilio -> OpenAI
     */
    protected function proxyToOpenAi(Server $server, int $twilioFd, HttpClient $openai): void
    {
        while (true) {
            $frame = $server->recv($twilioFd, 5.0);
            if ($frame === false) {
                break;
            }

            if ($frame instanceof Frame) {
                $openai->push($frame->data);
            } else {
                break;
            }
        }
    }

    /**
     * Proxy messages from OpenAI -> Twilio
     */
    protected function proxyToTwilio(Server $server, int $twilioFd, HttpClient $openai): void
    {
        while (true) {
            $response = $openai->recv(5.0);
            if ($response === false || $response === null) {
                break;
            }

            if (isset($response->data)) {
                $server->push($twilioFd, $response->data);
            }
        }
    }

    /**
     * Handle WebSocket connection close.
     */
    public function onClose(Server $server, int $fd): void
    {
        Log::info('Twilio WebSocket connection closed', ['fd' => $fd]);

        if (isset($this->connections[$fd])) {
            try {
                $this->connections[$fd]->close();
            } catch (\Throwable $e) {
                Log::warning('Error closing upstream client', ['fd' => $fd, 'error' => $e->getMessage()]);
            }

            unset($this->connections[$fd]);
        }

        // Dispatch job for call summary if needed
        // dispatch(new \App\Jobs\FinalizeCallSummary($fd));
    }
}
