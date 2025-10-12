<?php

namespace App\Services\Recording;

use App\Models\Call;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TwilioAudioRecorder
{
    private const LOCAL_DISK = 'local';
    private const PUBLIC_DISK = 'public';
    private const TEMP_DIRECTORY = 'call-recordings/tmp';
    private const FINAL_DIRECTORY = 'call-recordings';
    private const SAMPLE_RATE = 8000;

    public function start(string $sessionId): void
    {
        $normalized = $this->normalizeSessionId($sessionId);
        $tempPath = $this->tempPath($normalized);

        Storage::disk(self::LOCAL_DISK)->makeDirectory(self::TEMP_DIRECTORY);

        if (! Storage::disk(self::LOCAL_DISK)->exists($tempPath)) {
            Storage::disk(self::LOCAL_DISK)->put($tempPath, '');
        }
    }

    public function append(string $sessionId, string $base64Audio): void
    {
        $normalized = $this->normalizeSessionId($sessionId);
        $tempPath = $this->tempPath($normalized);

        if (! Storage::disk(self::LOCAL_DISK)->exists($tempPath)) {
            $this->start($sessionId);
        }

        $binary = base64_decode($base64Audio, true);

        if ($binary === false) {
            Log::warning('Failed to decode Twilio media payload.', ['session_id' => $sessionId]);

            return;
        }

        @file_put_contents(Storage::disk(self::LOCAL_DISK)->path($tempPath), $binary, FILE_APPEND);
    }

    public function finalize(string $sessionId, Call $call): ?string
    {
        $normalized = $this->normalizeSessionId($sessionId);
        $tempPath = $this->tempPath($normalized);

        if (! Storage::disk(self::LOCAL_DISK)->exists($tempPath)) {
            return null;
        }

        $rawAudio = @file_get_contents(Storage::disk(self::LOCAL_DISK)->path($tempPath));

        if ($rawAudio === false || $rawAudio === '') {
            Storage::disk(self::LOCAL_DISK)->delete($tempPath);

            return null;
        }

        $waveData = $this->wrapMuLawInWaveContainer($rawAudio);
        $finalPath = $this->finalPath($normalized, $call->id);

        Storage::disk(self::PUBLIC_DISK)->makeDirectory(self::FINAL_DIRECTORY);
        Storage::disk(self::PUBLIC_DISK)->put($finalPath, $waveData);

        Storage::disk(self::LOCAL_DISK)->delete($tempPath);

        return Storage::disk(self::PUBLIC_DISK)->url($finalPath);
    }

    public function discard(string $sessionId): void
    {
        $normalized = $this->normalizeSessionId($sessionId);
        $tempPath = $this->tempPath($normalized);

        Storage::disk(self::LOCAL_DISK)->delete($tempPath);
    }

    protected function wrapMuLawInWaveContainer(string $rawAudio): string
    {
        $dataLength = strlen($rawAudio);
        $chunkSize = 36 + $dataLength;
        $byteRate = self::SAMPLE_RATE;
        $blockAlign = 1;
        $bitsPerSample = 8;

        return 'RIFF'
            . pack('V', $chunkSize)
            . 'WAVEfmt '
            . pack('V', 16)
            . pack('v', 7)
            . pack('v', 1)
            . pack('V', self::SAMPLE_RATE)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', $bitsPerSample)
            . 'data'
            . pack('V', $dataLength)
            . $rawAudio;
    }

    protected function tempPath(string $normalizedSessionId): string
    {
        return self::TEMP_DIRECTORY.'/'.$normalizedSessionId.'.raw';
    }

    protected function finalPath(string $normalizedSessionId, int $callId): string
    {
        return sprintf('%s/call-%d-%s.wav', self::FINAL_DIRECTORY, $callId, $normalizedSessionId);
    }

    protected function normalizeSessionId(string $sessionId): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $sessionId) ?? 'session';
    }
}

