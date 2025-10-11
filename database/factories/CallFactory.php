<?php

namespace Database\Factories;

use App\Models\Call;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Call>
 */
class CallFactory extends Factory
{
    protected $model = Call::class;

    public function definition(): array
    {
        $startedAt = CarbonImmutable::instance(
            $this->faker->dateTimeBetween('-2 weeks')
        );
        $durationSeconds = $this->faker->numberBetween(45, 1200);
        $endedAt = $startedAt->addSeconds($durationSeconds);

        return [
            'user_id' => User::factory(),
            'call_sid' => 'CA'.$this->faker->unique()->bothify(str_repeat('#', 32)),
            'session_id' => 'session_'.$this->faker->unique()->bothify(str_repeat('#', 16)),
            'from_number' => $this->faker->e164PhoneNumber(),
            'to_number' => $this->faker->e164PhoneNumber(),
            'forwarded_from' => $this->faker->optional()->e164PhoneNumber(),
            'caller_name' => $this->faker->name(),
            'status' => 'completed',
            'is_starred' => $this->faker->boolean(20),
            'recording_url' => $this->faker->url(),
            'summary' => [
                'customerName' => $this->faker->name(),
                'customerAvailability' => $this->faker->iso8601(),
                'specialNotes' => $this->faker->sentence(),
            ],
            'transcript_messages' => collect([
                ['offset' => 0, 'speaker' => 'caller', 'length' => 8],
                ['offset' => 42, 'speaker' => 'agent', 'length' => 10],
                ['offset' => 88, 'speaker' => 'caller', 'length' => 9],
            ])
                ->map(function (array $entry) use ($startedAt) {
                    return [
                        'id' => (string) Str::uuid(),
                        'speaker' => $entry['speaker'],
                        'content' => $this->faker->sentence($entry['length']),
                        'captured_at' => $startedAt
                            ->addSeconds($entry['offset'])
                            ->toAtomString(),
                    ];
                })
                ->all(),
            'transcript_text' => $this->faker->paragraphs(2, true),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $durationSeconds,
        ];
    }
}
