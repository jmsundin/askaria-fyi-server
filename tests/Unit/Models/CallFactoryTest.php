<?php

namespace Tests\Unit\Models;

use App\Models\Call;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_generates_summary_and_transcript_with_expected_structure(): void
    {
        $call = Call::factory()->create();

        $this->assertIsArray($call->summary);
        $this->assertArrayHasKey('customerName', $call->summary);
        $this->assertArrayHasKey('customerAvailability', $call->summary);
        $this->assertArrayHasKey('specialNotes', $call->summary);

        $this->assertIsArray($call->transcript_messages);
        $this->assertGreaterThan(0, count($call->transcript_messages));

        foreach ($call->transcript_messages as $message) {
            $this->assertArrayHasKey('id', $message);
            $this->assertArrayHasKey('speaker', $message);
            $this->assertArrayHasKey('content', $message);
            $this->assertArrayHasKey('captured_at', $message);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $message['captured_at']);
        }

        $this->assertNotEmpty($call->transcript_text);
    }
}
