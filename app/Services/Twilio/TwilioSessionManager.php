<?php

namespace App\Services\Twilio;

use Illuminate\Support\Facades\Log;

class TwilioSessionManager
{
    /**
     * @var array<string, TwilioSession>
     */
    protected array $sessions = [];

    public function getOrCreate(string $sessionId): TwilioSession
    {
        if (! isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId] = new TwilioSession($sessionId);
            Log::info('Created new Twilio session.', ['session_id' => $sessionId]);
        }

        return $this->sessions[$sessionId];
    }

    public function remove(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }

    public function find(string $sessionId): ?TwilioSession
    {
        return $this->sessions[$sessionId] ?? null;
    }
}


