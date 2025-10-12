<?php

namespace App\Console\Commands;

use App\Services\OpenAI\RealtimeClientFactory;
use App\Services\OpenAI\RealtimeSessionConfigurator;
use App\Services\OpenAI\TranscriptProcessor;
use App\Services\Recording\TwilioAudioRecorder;
use App\Services\Twilio\TwilioSessionManager;
use App\WebSocket\TwilioProxyHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Swoole\Http\Request;
use Swoole\WebSocket\Server;

class ServeWebSocket extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ws:serve {--host=0.0.0.0} {--port=9502} {--path=/media-stream}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start Swoole WebSocket server for Twilio proxy';

    public function handle(): int
    {
        if (! extension_loaded('swoole')) {
            $this->error('The Swoole extension is not installed.');

            return self::FAILURE;
        }

        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        $path = (string) $this->option('path');

        $server = new Server($host, $port);

        // Basic server settings; tune as needed
        $server->set([
            'worker_num' => max(1, (int) swoole_cpu_num()),
            'max_request' => 10000,
            'open_http2_protocol' => false,
        ]);

        $handler = new TwilioProxyHandler(
            app(RealtimeClientFactory::class),
            app(RealtimeSessionConfigurator::class),
            app(TranscriptProcessor::class),
            app(TwilioAudioRecorder::class),
            app(TwilioSessionManager::class),
        );

        $server->on('start', function () use ($host, $port, $path) {
            Log::info('Swoole WebSocket server started', ['host' => $host, 'port' => $port, 'path' => $path]);
        });

        $server->on('open', function (Server $server, Request $request) use ($handler, $path) {
            // Only accept connections on the configured path
            $requestPath = $request->server['request_uri'] ?? '/';
            if ($requestPath !== $path) {
                $server->disconnect($request->fd, 1008, 'Invalid path');

                return;
            }
            $handler->onOpen($server, $request);
        });

        $server->on('message', function (Server $server, $frame) use ($handler) {
            $handler->onMessage($server, $frame);
        });

        $server->on('close', function (Server $server, int $fd) use ($handler) {
            $handler->onClose($server, $fd);
        });

        $this->info("WebSocket server listening on ws://{$host}:{$port}{$path}");

        $server->start();

        return self::SUCCESS;
    }
}
