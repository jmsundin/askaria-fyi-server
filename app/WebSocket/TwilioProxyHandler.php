<?php

namespace App\WebSocket;

use App\Services\OpenAI\RealtimeClientFactory;
use App\Services\OpenAI\RealtimeSessionConfigurator;
use App\Services\OpenAI\TranscriptProcessor;
use App\Services\Twilio\TwilioSessionManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
     * Map of Twilio connection FDs to session identifiers.
     *
     * @var array<int, string>
     */
    protected array $connectionSessions = [];

    protected TwilioSessionManager $sessionManager;

    public function __construct(
        protected RealtimeClientFactory $clientFactory,
        protected RealtimeSessionConfigurator $sessionConfigurator,
        protected TranscriptProcessor $transcriptProcessor,
        ?TwilioSessionManager $sessionManager = null,
    ) {
        $this->sessionManager = $sessionManager ?? new TwilioSessionManager;
    }

    /**
     * Handle new WebSocket connection from Twilio.
     */
    public function onOpen(Server $server, Request $request): void
    {
        $twilioFd = $request->fd;

        Log::info('Twilio WebSocket connection opened', ['fd' => $twilioFd]);

        $sessionId = $this->resolveSessionId($request);
        $session = $this->sessionManager->getOrCreate($sessionId);
        $this->connectionSessions[$twilioFd] = $sessionId;

        Log::debug('Preparing OpenAI realtime client', ['fd' => $twilioFd, 'session_id' => $sessionId]);
        $openai = $this->clientFactory->create();

        if ($openai === null) {
            Log::error('Failed to prepare OpenAI realtime connection; closing Twilio connection', ['fd' => $twilioFd]);
            $server->close($twilioFd);

            return;
        }

        $this->connections[$twilioFd] = $openai;

        if (! $this->sendInitialSessionUpdate($openai, $sessionId)) {
            Log::warning('Initial realtime session update failed; closing Twilio connection', ['fd' => $twilioFd, 'session_id' => $sessionId]);
            $server->close($twilioFd);

            return;
        }
        $this->queueInitialGreeting($openai, $session);

        Log::info('Connected upstream for Twilio connection', ['fd' => $twilioFd, 'session_id' => $sessionId]);

        \Swoole\Coroutine::create(function () use ($server, $twilioFd, $openai, $sessionId) {
            $this->proxyToTwilio($server, $twilioFd, $openai, $sessionId);
        });
    }

    /**
     * Handle incoming message from Twilio.
     * The continuous proxy loop handles messages; nothing to do here.
     */
    public function onMessage(Server $server, Frame $frame): void
    {
        $twilioFd = $frame->fd;
        $sessionId = $this->connectionSessions[$twilioFd] ?? null;
        $openai = $this->connections[$twilioFd] ?? null;

        if ($sessionId === null || $openai === null) {
            Log::warning('Received Twilio frame for unknown connection.', ['fd' => $twilioFd]);

            return;
        }

        $this->handleIncomingTwilioPayload($server, $twilioFd, $frame->data, $openai, $sessionId);
    }

    /**
     * Proxy messages from OpenAI -> Twilio
     */
    protected function proxyToTwilio(Server $server, int $twilioFd, HttpClient $openai, string $sessionId): void
    {
        Log::debug('Starting realtime proxy loop', ['fd' => $twilioFd, 'session_id' => $sessionId]);

        while (true) {
            $response = $openai->recv(5.0);
            if ($response === false) {
                $errorCode = $openai->errCode;

                if ($this->isRecoverableOpenAiReadError($errorCode)) {
                    Log::debug('Retrying realtime recv after recoverable error', ['session_id' => $sessionId, 'error_code' => $errorCode]);

                    continue;
                }

                Log::warning('Stopping realtime proxy loop after upstream read failure.', [
                    'session_id' => $sessionId,
                    'error_code' => $errorCode,
                    'error_message' => $errorCode > 0 ? socket_strerror($errorCode) : null,
                ]);

                break;
            }

            if ($response === null) {
                Log::info('Realtime upstream closed connection.', ['session_id' => $sessionId]);

                break;
            }

            if (isset($response->data)) {
                Log::debug('Forwarding realtime payload to Twilio', ['session_id' => $sessionId, 'bytes' => strlen($response->data)]);
                $this->handleOpenAiPayload($server, $twilioFd, $response->data, $sessionId);
            }
        }
    }

    protected function isRecoverableOpenAiReadError(?int $errorCode): bool
    {
        if ($errorCode === null) {
            return false;
        }

        $recoverableCodes = [];

        if (defined('SOCKET_ETIMEDOUT')) {
            $recoverableCodes[] = SOCKET_ETIMEDOUT;
        }

        if (defined('SOCKET_EAGAIN')) {
            $recoverableCodes[] = SOCKET_EAGAIN;
        }

        if (defined('SOCKET_EINTR')) {
            $recoverableCodes[] = SOCKET_EINTR;
        }

        if (defined('SWOOLE_ERROR_CO_HTTP_CLIENT_TIMEOUT')) {
            $recoverableCodes[] = SWOOLE_ERROR_CO_HTTP_CLIENT_TIMEOUT;
        }

        if (defined('SWOOLE_ERROR_CO_TIMEOUT')) {
            $recoverableCodes[] = SWOOLE_ERROR_CO_TIMEOUT;
        }

        return in_array($errorCode, $recoverableCodes, true);
    }

    /**
     * Handle WebSocket connection close.
     */
    public function onClose(Server $server, int $fd): void
    {
        $sessionId = $this->connectionSessions[$fd] ?? null;

        Log::info('Twilio WebSocket connection closed', ['fd' => $fd, 'session_id' => $sessionId]);

        if (isset($this->connections[$fd])) {
            try {
                $this->connections[$fd]->close();
            } catch (\Throwable $e) {
                Log::warning('Error closing upstream client', ['fd' => $fd, 'error' => $e->getMessage()]);
            }

            unset($this->connections[$fd]);
        }

        if ($sessionId !== null) {
            $session = $this->sessionManager->find($sessionId);

            if ($session !== null && $session->transcript !== '') {
                $this->transcriptProcessor->processAndSendTranscript($session->transcript, $sessionId);
            }

            $this->sessionManager->remove($sessionId);
            unset($this->connectionSessions[$fd]);
        }

        // Dispatch job for call summary if needed
        // dispatch(new \App\Jobs\FinalizeCallSummary($fd));
    }

    protected function resolveSessionId(Request $request): string
    {
        $headerSession = $request->header['x-twilio-call-sid'] ?? null;
        $sessionId = is_string($headerSession) && $headerSession !== ''
            ? $headerSession
            : 'session_'.Str::orderedUuid()->toString();

        return $sessionId;
    }

    protected function sendInitialSessionUpdate(HttpClient $client, string $sessionId): bool
    {
        $payload = $this->sessionConfigurator->buildSessionUpdatePayload();
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        if ($client->push($encodedPayload) === false) {
            Log::warning('Failed to deliver initial realtime session update.', [
                'session_id' => $sessionId,
                'error_code' => $client->errCode,
            ]);

            return false;
        }

        Log::debug('Realtime session update acknowledged.', ['session_id' => $sessionId]);

        return true;
    }

    protected function handleIncomingTwilioPayload(Server $server, int $twilioFd, string $payload, HttpClient $openai, string $sessionId): void
    {
        $data = json_decode($payload, true);
        if (! is_array($data) || ! isset($data['event'])) {
            Log::warning('Received unrecognized payload from Twilio.', ['session_id' => $sessionId]);

            return;
        }

        $session = $this->sessionManager->getOrCreate($sessionId);

        $event = $data['event'];

        if ($event === 'media') {
            Log::debug('Received Twilio media frame', ['session_id' => $sessionId]);
            $this->forwardAudioToOpenAi($openai, $data);

            return;
        }

        if ($event === 'start') {
            $this->handleStartEvent($server, $twilioFd, $session, $data);

            return;
        }

        Log::info('Received non-media Twilio event.', ['session_id' => $sessionId, 'event' => $event]);
    }

    protected function forwardAudioToOpenAi(HttpClient $openai, array $payload): void
    {
        if (! isset($payload['media']['payload'])) {
            return;
        }

        $result = $openai->push(json_encode([
            'type' => 'input_audio_buffer.append',
            'audio' => $payload['media']['payload'],
        ], JSON_THROW_ON_ERROR));

        if ($result === false) {
            Log::warning('Failed to push audio buffer to OpenAI.', [
                'error_code' => $openai->errCode,
            ]);
        }
    }

    protected function handleOpenAiPayload(Server $server, int $twilioFd, string $payload, string $sessionId): void
    {
        $data = json_decode($payload, true);
        if (! is_array($data) || ! isset($data['type'])) {
            Log::warning('Received unrecognized payload from OpenAI.', ['session_id' => $sessionId]);

            return;
        }

        $session = $this->sessionManager->getOrCreate($sessionId);

        switch ($data['type']) {
            case 'conversation.item.input_audio_transcription.completed':
                $message = trim((string) ($data['transcript'] ?? ''));
                $session->appendTranscript('User', $message);
                Log::info('User transcript captured.', ['session_id' => $sessionId, 'transcript' => $message]);
                break;
            case 'response.done':
                $message = $this->extractAgentTranscript($data);
                if ($message !== null) {
                    $session->appendTranscript('Agent', $message);
                    Log::info('Agent transcript captured.', ['session_id' => $sessionId, 'transcript' => $message]);
                }
                break;
            case 'response.audio.delta':
                Log::debug('Received realtime audio delta', ['session_id' => $sessionId]);
                $this->forwardAudioDeltaToTwilio($server, $twilioFd, $session, $data);
                break;
            case 'session.updated':
                Log::info('Realtime session updated.', ['session_id' => $sessionId]);
                break;
            default:
                Log::debug('Realtime payload ignored', ['session_id' => $sessionId, 'type' => $data['type']]);
                break;
        }
    }

    protected function extractAgentTranscript(array $response): ?string
    {
        $output = $response['response']['output'][0]['content'] ?? null;
        if (! is_array($output)) {
            return null;
        }

        foreach ($output as $item) {
            if (isset($item['transcript']) && is_string($item['transcript'])) {
                return trim($item['transcript']);
            }
        }

        return null;
    }

    protected function forwardAudioDeltaToTwilio(Server $server, int $twilioFd, \App\Services\Twilio\TwilioSession $session, array $payload): void
    {
        $delta = $payload['delta'] ?? null;
        if (! is_string($delta)) {
            return;
        }

        if ($session->streamSid === null) {
            Log::warning('Cannot send audio delta without stream SID.', ['session_id' => $session->id]);

            return;
        }

        $server->push($twilioFd, json_encode([
            'event' => 'media',
            'streamSid' => $session->streamSid,
            'media' => ['payload' => $delta],
        ], JSON_THROW_ON_ERROR));
    }

    protected function queueInitialGreeting(HttpClient $client, \App\Services\Twilio\TwilioSession $session): void
    {
        if ($session->hasGreetedAgent) {
            return;
        }

        $greetingText = config('services.openai.realtime_greeting', 'Hi, this is Aria. How can I help you?');

        $payload = [
            'type' => 'response.create',
            'response' => [
                'instructions' => $greetingText,
                'conversation' => [
                    'messages' => [
                        [
                            'role' => 'assistant',
                            'content' => [
                                [
                                    'type' => 'output_text',
                                    'text' => $greetingText,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        if ($client->push($encodedPayload) === false) {
            Log::warning('Failed to push greeting to OpenAI.', ['session_id' => $session->id]);

            return;
        }

        $session->hasGreetedAgent = true;
        Log::info('Queued initial greeting with OpenAI.', ['session_id' => $session->id]);
    }

    protected function handleStartEvent(Server $server, int $twilioFd, \App\Services\Twilio\TwilioSession $session, array $payload): void
    {
        $start = $payload['start'] ?? null;

        if (! is_array($start)) {
            Log::warning('Received malformed Twilio start event.', ['session_id' => $session->id]);

            return;
        }

        $streamSid = $start['streamSid'] ?? null;

        if (is_string($streamSid) && $streamSid !== '') {
            $session->streamSid = $streamSid;

            Log::info('Twilio media stream started.', [
                'session_id' => $session->id,
                'stream_sid' => $streamSid,
            ]);
        } else {
            Log::warning('Twilio start event missing stream SID.', ['session_id' => $session->id]);
        }

        if (isset($start['customParameters']) && is_array($start['customParameters'])) {
            Log::debug('Twilio start event custom parameters captured.', [
                'session_id' => $session->id,
                'custom_parameters' => $start['customParameters'],
            ]);
        }

        if (isset($start['callSid']) && is_string($start['callSid']) && $start['callSid'] !== '') {
            Log::debug('Twilio call SID received from start event.', [
                'session_id' => $session->id,
                'call_sid' => $start['callSid'],
            ]);
        }

        $connectionInfo = $server->connection_info($twilioFd);
        $activeStatus = defined('WEBSOCKET_STATUS_ACTIVE') ? WEBSOCKET_STATUS_ACTIVE : 3;

        if ($connectionInfo === false || ($connectionInfo['websocket_status'] ?? 0) !== $activeStatus) {
            Log::warning('Twilio websocket not active after start event.', ['session_id' => $session->id]);
        }
    }
}
