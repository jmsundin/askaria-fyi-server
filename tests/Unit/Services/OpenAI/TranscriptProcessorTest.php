<?php

namespace Tests\Unit\Services\OpenAI;

use App\Models\Call;
use App\Services\OpenAI\TranscriptProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranscriptProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_persists_summary_and_forwards_webhook(): void
    {
        config([
            'services.openai.key' => 'test-openai-key',
            'services.openai.chat_model' => 'gpt-test',
            'services.twilio.webhook_url' => 'https://webhook.test/ingest',
        ]);

        $call = Call::factory()->create([
            'session_id' => 'session_123',
            'summary' => null,
            'transcript_text' => null,
        ]);

        $expectedSummary = [
            'customerName' => 'Jane Doe',
            'customerAvailability' => '2025-10-10T12:34:56+0000',
            'specialNotes' => 'Needs a callback regarding billing.',
        ];

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode($expectedSummary, JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ]),
            'https://webhook.test/*' => Http::response(['ok' => true], 200),
        ]);

        $processor = app(TranscriptProcessor::class);

        $processor->processAndSendTranscript(
            'Caller provided billing details and requested follow-up.',
            'session_123'
        );

        $call->refresh();

        $this->assertEquals($expectedSummary, $call->summary);
        $this->assertSame('Caller provided billing details and requested follow-up.', $call->transcript_text);

        Http::assertSent(function ($request) use ($expectedSummary): bool {
            if (str_contains($request->url(), 'api.openai.com')) {
                $body = $request->data();

                return $body['model'] === 'gpt-test'
                    && $body['messages'][1]['content'] === 'Caller provided billing details and requested follow-up.';
            }

            if (str_contains($request->url(), 'webhook.test')) {
                return $request['customerName'] === $expectedSummary['customerName']
                    && $request['specialNotes'] === $expectedSummary['specialNotes'];
            }

            return false;
        });
    }

    public function test_process_with_empty_transcript_does_not_call_external_services(): void
    {
        config([
            'services.openai.key' => 'test-openai-key',
            'services.openai.chat_model' => 'gpt-test',
            'services.twilio.webhook_url' => 'https://webhook.test/ingest',
        ]);

        $call = Call::factory()->create([
            'session_id' => 'session_empty',
            'summary' => null,
            'transcript_text' => null,
        ]);

        Http::fake();

        $processor = app(TranscriptProcessor::class);

        $processor->processAndSendTranscript('', 'session_empty');

        $call->refresh();

        $this->assertNull($call->summary);
        $this->assertNull($call->transcript_text);

        Http::assertNothingSent();
    }
}

