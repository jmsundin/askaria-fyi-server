<?php

namespace App\Services\Twilio;

class TwilioSession
{
    public function __construct(
        public readonly string $id,
        public string $transcript = '',
        public ?string $streamSid = null,
        public bool $hasGreetedAgent = false,
    ) {
    }

    public function appendTranscript(string $speaker, string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        $this->transcript .= $speaker.': '.$message."\n";
    }
}


