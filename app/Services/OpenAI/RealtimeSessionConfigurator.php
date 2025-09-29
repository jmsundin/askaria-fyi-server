<?php

namespace App\Services\OpenAI;

class RealtimeSessionConfigurator
{
    public function buildSessionUpdatePayload(): array
    {
        $config = config('services.openai');

        $instructions = (string) ($config['realtime_instructions'] ?? '');
        $voice = (string) ($config['realtime_voice'] ?? 'shimmer');

        return [
            'type' => 'session.update',
            'session' => [
                'turn_detection' => ['type' => 'server_vad'],
                'input_audio_format' => 'g711_ulaw',
                'output_audio_format' => 'g711_ulaw',
                'voice' => $voice,
                'instructions' => $instructions,
                'modalities' => ['text', 'audio'],
                'temperature' => 0.8,
                'input_audio_transcription' => [
                    'model' => 'whisper-1',
                ],
            ],
        ];
    }
}


