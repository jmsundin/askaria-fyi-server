<?php

namespace App\Services\Twilio;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class TwilioSession
{
    public function __construct(
        public readonly string $id,
        public string $transcript = '',
        public ?string $streamSid = null,
        public bool $hasGreetedAgent = false,
        public ?int $callId = null,
        public ?string $callSid = null,
        public ?string $fromNumber = null,
        public ?string $toNumber = null,
        public ?string $forwardedFrom = null,
        public ?string $callerName = null,
        public ?int $userId = null,
    ) {
    }

    /**
     * @var list<array{ id: string, speaker: 'agent'|'caller', content: string, captured_at: string }>
     */
    public array $messages = [];

    public ?CarbonImmutable $startedAt = null;

    public ?CarbonImmutable $endedAt = null;

    public function appendTranscript(string $speaker, string $message): void
    {
        $message = trim($message);

        if ($message === '') {
            return;
        }

        $this->transcript = $this->transcript === ''
            ? sprintf('%s: %s', ucfirst($speaker), $message)
            : $this->transcript."\n".sprintf('%s: %s', ucfirst($speaker), $message);

        $this->messages[] = [
            'id' => (string) Str::uuid(),
            'speaker' => $speaker === 'agent' ? 'agent' : 'caller',
            'content' => $message,
            'captured_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }
}

