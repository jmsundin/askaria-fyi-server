<?php

namespace App\WebSocket;

use App\Models\AgentProfile;
use App\Models\Call;
use App\Support\PhoneNumber;
use App\Support\PhoneNumber;
use App\Services\OpenAI\RealtimeClientFactory;
use App\Services\OpenAI\RealtimeSessionConfigurator;
use App\Services\OpenAI\TranscriptProcessor;
use App\Services\Recording\TwilioAudioRecorder;
use App\Services\Twilio\TwilioSession;
use App\Services\Twilio\TwilioSessionManager;
use Carbon\CarbonImmutable;
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

    /**
     * Twilio payloads received before the upstream OpenAI client becomes available.
     *
     * @var array<int, string[]>
     */
    protected array $pendingTwilioPayloads = [];

    /**
     * Cached map of normalized business phone numbers to user identifiers.
     *
     * @var array<string, int>
     */
    protected array $businessNumberUserMap = [];

    protected TwilioSessionManager $sessionManager;

    public function __construct(
        protected RealtimeClientFactory $clientFactory,
        protected RealtimeSessionConfigurator $sessionConfigurator,
        protected TranscriptProcessor $transcriptProcessor,
        protected TwilioAudioRecorder $audioRecorder,
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

        $call = Call::query()->firstOrCreate(
            ['session_id' => $sessionId],
            [
                'status' => 'in_progress',
                'started_at' => now(),
            ]
        );

        $session->callId = $call->id;
        $session->callSid = $call->call_sid;
        $this->connectionSessions[$twilioFd] = $sessionId;
        $this->pendingTwilioPayloads[$twilioFd] = [];

        $this->audioRecorder->start($sessionId);

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
        if ($this->sendInitialSystemMessage($openai, $session)) {
            $this->requestInitialResponse($openai, $session);
        }
        $this->drainPendingTwilioPayloads($server, $twilioFd, $openai, $sessionId);

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
            $this->queuePendingTwilioPayload($twilioFd, $frame->data);
            Log::debug('Queued Twilio payload while upstream initializes.', ['fd' => $twilioFd]);

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

        unset($this->pendingTwilioPayloads[$fd]);

        if ($sessionId !== null) {
            $session = $this->sessionManager->find($sessionId);

            if ($session !== null) {
                if ($session->transcript !== '') {
                    $this->transcriptProcessor->processAndSendTranscript($session->transcript, $sessionId);
                }

                $this->finalizeCall($session);

                $call = $session->callId !== null ? Call::query()->find($session->callId) : null;

                if ($call !== null) {
                    $recordingUrl = $this->audioRecorder->finalize($sessionId, $call);

                    if ($recordingUrl !== null) {
                        $call->recording_url = $recordingUrl;
                        $call->save();
                    }
                } else {
                    $this->audioRecorder->discard($sessionId);
                }
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

            $encoded = $data['media']['payload'] ?? null;
            if (is_string($encoded) && $encoded !== '') {
                $this->audioRecorder->append($sessionId, $encoded);
            }

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
                $session->appendTranscript('caller', $message);
                $this->persistLatestTranscriptMessage($session);
                Log::info('User transcript captured.', ['session_id' => $sessionId, 'transcript' => $message]);
                break;
            case 'response.done':
                $message = $this->extractAgentTranscript($data);
                if ($message !== null) {
                    $session->appendTranscript('agent', $message);
                    $this->persistLatestTranscriptMessage($session);
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
            case 'session.created':
                Log::info('Realtime session created.', ['session_id' => $sessionId]);

                break;
            case 'error':
                Log::error('Realtime error received from OpenAI.', [
                    'session_id' => $sessionId,
                    'error' => $data['error'] ?? null,
                    'payload' => $data,
                ]);
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
            'media' => ['payload' => $delta, 'track' => 'outbound'],
        ], JSON_THROW_ON_ERROR));
    }

    protected function persistLatestTranscriptMessage(TwilioSession $session): void
    {
        if ($session->callId === null || $session->messages === []) {
            return;
        }

        $latestMessage = end($session->messages);
        if (! is_array($latestMessage)) {
            return;
        }

        $call = Call::query()->find($session->callId);
        if ($call === null) {
            return;
        }

        $messages = $call->transcript_messages ?? [];
        foreach ($messages as $existingMessage) {
            if (($existingMessage['id'] ?? null) === ($latestMessage['id'] ?? null)) {
                return;
            }
        }

        $messages[] = $latestMessage;
        $call->transcript_messages = $messages;
        $call->save();
    }

    protected function finalizeCall(TwilioSession $session): void
    {
        if ($session->callId === null) {
            return;
        }

        $call = Call::query()->find($session->callId);
        if ($call === null) {
            return;
        }

        $endedAt = CarbonImmutable::now();
        $session->endedAt = $endedAt;

        $attributes = [
            'status' => 'completed',
            'ended_at' => $endedAt,
        ];

        if ($session->startedAt === null && $call->started_at !== null) {
            $session->startedAt = CarbonImmutable::instance($call->started_at);
        }

        if ($session->startedAt !== null) {
            $attributes['duration_seconds'] = max(0, $endedAt->diffInSeconds($session->startedAt));
        }

        if ($session->callSid !== null && $call->call_sid === null) {
            $attributes['call_sid'] = $session->callSid;
        }

        if ($session->fromNumber !== null && $call->from_number === null) {
            $attributes['from_number'] = $session->fromNumber;
        }

        if ($session->toNumber !== null && $call->to_number === null) {
            $attributes['to_number'] = $session->toNumber;
        }

        if ($session->forwardedFrom !== null && $call->forwarded_from === null) {
            $attributes['forwarded_from'] = $session->forwardedFrom;
        }

        if ($session->callerName !== null && $call->caller_name === null) {
            $attributes['caller_name'] = $session->callerName;
        }

        if ($session->userId !== null && $call->user_id === null) {
            $attributes['user_id'] = $session->userId;
        }

        $isDirty = false;

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            $current = $call->getAttribute($key);

            if ($current === null || $current === '') {
                $call->setAttribute($key, $value);
                $isDirty = true;
            }
        }

        if ($session->messages !== []) {
            $call->transcript_messages = $session->messages;
            $isDirty = true;
        }

        if ($session->transcript !== '') {
            $call->transcript_text = $session->transcript;
            $isDirty = true;
        }

        if ($isDirty) {
            $call->save();
        }
    }

    protected function sendInitialSystemMessage(HttpClient $client, \App\Services\Twilio\TwilioSession $session): bool
    {
        $instructions = config('services.openai.realtime_instructions', '');

        if ($instructions === '') {
            Log::warning('Realtime instructions missing while attempting to send system message.', ['session_id' => $session->id]);

            return false;
        }

        $payload = [
            'type' => 'conversation.item.create',
            'item' => [
                'type' => 'message',
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $instructions,
                    ],
                ],
            ],
        ];

        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        if ($client->push($encodedPayload) === false) {
            Log::warning('Failed to push initial system message to OpenAI.', ['session_id' => $session->id]);
            $session->hasGreetedAgent = false;

            return false;
        }

        Log::info('Queued initial system message with OpenAI.', ['session_id' => $session->id]);
        $session->hasGreetedAgent = true;

        return true;
    }

    protected function requestInitialResponse(HttpClient $client, \App\Services\Twilio\TwilioSession $session): void
    {
        $payload = [
            'type' => 'response.create',
            'response' => [
                'instructions' => 'Generate an immediate response to the most recent system message.',
                'modalities' => ['audio', 'text'],
                'conversation' => 'auto',
            ],
        ];

        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        if ($client->push($encodedPayload) === false) {
            Log::warning('Failed to request initial response from OpenAI.', ['session_id' => $session->id]);

            return;
        }

        Log::info('Requested initial response from OpenAI.', ['session_id' => $session->id]);
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
            $rawFrom = $start['customParameters']['from'] ?? null;
            $rawTo = $start['customParameters']['to'] ?? null;
            $rawForwarded = $start['customParameters']['forwarded_from'] ?? null;
            $rawCallerName = $start['customParameters']['caller_name'] ?? null;

            $session->fromNumber = PhoneNumber::normalize(is_string($rawFrom) ? $rawFrom : null);
            $session->toNumber = PhoneNumber::normalize(is_string($rawTo) ? $rawTo : null);
            $session->forwardedFrom = PhoneNumber::normalize(is_string($rawForwarded) ? $rawForwarded : null);
            $session->callerName = is_string($rawCallerName) ? trim($rawCallerName) : null;

            $attributes = [];

            if ($session->fromNumber !== null) {
                $attributes['from_number'] = $session->fromNumber;
            }

            if ($session->toNumber !== null) {
                $attributes['to_number'] = $session->toNumber;
            }

            if ($session->forwardedFrom !== null) {
                $attributes['forwarded_from'] = $session->forwardedFrom;
            }

            if ($session->callerName !== null) {
                $attributes['caller_name'] = $session->callerName;
            }

            if ($session->toNumber !== null) {
                $session->userId = $this->resolveUserIdForBusinessNumber($session->toNumber);
                if ($session->userId !== null) {
                    $attributes['user_id'] = $session->userId;
                }
            }

            if ($attributes !== []) {
                $this->updateCallRecord($session, $attributes);
            }
        }

        if (isset($start['callSid']) && is_string($start['callSid']) && $start['callSid'] !== '') {
            Log::debug('Twilio call SID received from start event.', [
                'session_id' => $session->id,
                'call_sid' => $start['callSid'],
            ]);
            $session->callSid = $start['callSid'];
            $this->updateCallRecord($session, ['call_sid' => $session->callSid]);
        }

        $connectionInfo = $server->connection_info($twilioFd);
        $activeStatus = defined('WEBSOCKET_STATUS_ACTIVE') ? WEBSOCKET_STATUS_ACTIVE : 3;

        if ($connectionInfo === false || ($connectionInfo['websocket_status'] ?? 0) !== $activeStatus) {
            Log::warning('Twilio websocket not active after start event.', ['session_id' => $session->id]);
        }
    }

    protected function queuePendingTwilioPayload(int $twilioFd, string $payload): void
    {
        if (! isset($this->pendingTwilioPayloads[$twilioFd])) {
            $this->pendingTwilioPayloads[$twilioFd] = [];
        }

        $decodedPayload = json_decode($payload, true);
        if (is_array($decodedPayload) && ($decodedPayload['event'] ?? null) === 'media') {
            Log::debug('Dropping Twilio media frame while upstream initializes.', ['fd' => $twilioFd]);

            return;
        }

        $this->pendingTwilioPayloads[$twilioFd][] = $payload;
    }

    protected function drainPendingTwilioPayloads(Server $server, int $twilioFd, HttpClient $openai, string $sessionId): void
    {
        if (empty($this->pendingTwilioPayloads[$twilioFd])) {
            unset($this->pendingTwilioPayloads[$twilioFd]);

            return;
        }

        foreach ($this->pendingTwilioPayloads[$twilioFd] as $payload) {
            $this->handleIncomingTwilioPayload($server, $twilioFd, $payload, $openai, $sessionId);
        }

        unset($this->pendingTwilioPayloads[$twilioFd]);
    }

    protected function getConnection(int $twilioFd): ?HttpClient
    {
        return $this->connections[$twilioFd] ?? null;
    }

    protected function updateCallRecord(TwilioSession $session, array $attributes): void
    {
        if ($session->callId !== null) {
            $call = Call::query()->find($session->callId);
        } elseif ($session->callSid !== null) {
            $call = Call::query()->firstOrCreate(['call_sid' => $session->callSid], [
                'status' => 'in_progress',
                'started_at' => CarbonImmutable::now(),
            ]);
            $session->callId = $call->id;
        } else {
            return;
        }

        if ($call === null) {
            return;
        }

        $isDirty = false;

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            $current = $call->getAttribute($key);

            if ($current === null || $current === '') {
                $call->setAttribute($key, $value);
                $isDirty = true;
            }
        }

        if ($isDirty) {
            $call->save();
        }
    }

    protected function normalizePhoneNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $number);
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '1') && strlen($digits) === 11) {
            return '+'.$digits;
        }

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        return '+'.$digits;
    }

    protected function resolveUserIdForBusinessNumber(?string $number): ?int
    {
        $normalized = PhoneNumber::normalize($number);
        if ($normalized === null) {
            return null;
        }

        $this->loadBusinessNumberUserMap();

        return $this->businessNumberUserMap[$normalized] ?? null;
    }

    protected function loadBusinessNumberUserMap(): void
    {
        if ($this->businessNumberUserMap !== []) {
            return;
        }

        AgentProfile::query()
            ->whereNotNull('business_phone_number')
            ->get(['business_phone_number', 'user_id'])
            ->each(function (AgentProfile $profile): void {
                $normalized = PhoneNumber::normalize($profile->business_phone_number);

                if ($normalized !== null && ! isset($this->businessNumberUserMap[$normalized])) {
                    $this->businessNumberUserMap[$normalized] = $profile->user_id;
                }
            });
    }
}
