<?php

namespace Tests\Feature\Api;

use App\Http\Resources\CallResource;
use App\Models\Call;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class CallControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_calls_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $visibleCalls = Call::factory()->count(2)->for($user)->create();
        Call::factory()->create();

        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withToken($token)
            ->getJson('/calls');

        $response->assertOk();

        $response->assertJson(function (AssertableJson $json): void {
            $json->has('data', 2)
                ->has('meta', function (AssertableJson $meta): void {
                    $meta->where('perPage', 25)
                        ->where('hasMore', false)
                        ->where('nextCursor', null)
                        ->etc();
                })
                ->etc();
        });

        $payload = $response->json('data');

        $this->assertNotNull($payload);
        $this->assertSameSize($visibleCalls, $payload);

        $expectedIds = $visibleCalls->pluck('id')->all();
        $responseIds = array_column($payload, 'id');

        $this->assertEqualsCanonicalizing($expectedIds, $responseIds);
    }

    public function test_index_supports_cursor_pagination(): void
    {
        $user = User::factory()->create();
        $calls = Call::factory()->count(5)->for($user)->create();
        $token = JWTAuth::fromUser($user);

        $firstResponse = $this
            ->withToken($token)
            ->getJson('/calls?limit=2');

        $firstResponse->assertOk();
        $firstData = $firstResponse->json('data');
        $firstMeta = $firstResponse->json('meta');

        $this->assertCount(2, $firstData);
        $this->assertTrue($firstMeta['hasMore']);
        $this->assertNotNull($firstMeta['nextCursor']);

        $secondResponse = $this
            ->withToken($token)
            ->getJson('/calls?limit=2&cursor='.urlencode($firstMeta['nextCursor']));

        $secondResponse->assertOk();
        $secondData = $secondResponse->json('data');
        $secondMeta = $secondResponse->json('meta');

        $this->assertCount(2, $secondData);
        $this->assertTrue($secondMeta['hasMore']);
        $this->assertNotNull($secondMeta['nextCursor']);
        $this->assertNotNull($secondMeta['prevCursor']);

        $thirdResponse = $this
            ->withToken($token)
            ->getJson('/calls?limit=2&cursor='.urlencode($secondMeta['nextCursor']));

        $thirdResponse->assertOk();
        $thirdData = $thirdResponse->json('data');
        $thirdMeta = $thirdResponse->json('meta');

        $this->assertCount(1, $thirdData);
        $this->assertFalse($thirdMeta['hasMore']);
        $this->assertNull($thirdMeta['nextCursor']);

        $allIds = array_merge(
            array_column($firstData, 'id'),
            array_column($secondData, 'id'),
            array_column($thirdData, 'id')
        );

        $this->assertEqualsCanonicalizing($calls->pluck('id')->all(), $allIds);
    }

    public function test_index_filters_starred_calls(): void
    {
        $user = User::factory()->create();
        $starredCall = Call::factory()->for($user)->state(['is_starred' => true])->create();
        Call::factory()->for($user)->state(['is_starred' => false])->create();
        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withToken($token)
            ->getJson('/calls?starred=1');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame($starredCall->id, $data[0]['id']);
        $this->assertTrue($data[0]['isStarred']);
    }

    public function test_index_filters_calls_by_date_range(): void
    {
        $user = User::factory()->create();
        $beforeWindow = CarbonImmutable::parse('2025-10-01T12:00:00Z');
        $insideWindow = CarbonImmutable::parse('2025-10-05T12:00:00Z');
        $afterWindow = CarbonImmutable::parse('2025-10-09T12:00:00Z');

        Call::factory()->for($user)->state(['started_at' => $beforeWindow])->create();
        $expectedCall = Call::factory()->for($user)->state(['started_at' => $insideWindow])->create();
        Call::factory()->for($user)->state(['started_at' => $afterWindow])->create();

        $token = JWTAuth::fromUser($user);

        $params = http_build_query([
            'after' => '2025-10-03T00:00:00Z',
            'before' => '2025-10-07T00:00:00Z',
        ]);

        $response = $this
            ->withToken($token)
            ->getJson('/calls?'.$params);

        $response->assertOk();

        $data = $response->json('data');

        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame($expectedCall->id, $data[0]['id']);
    }

    public function test_index_filters_by_status(): void
    {
        $user = User::factory()->create();
        $completed = Call::factory()->for($user)->state(['status' => 'completed'])->create();
        Call::factory()->for($user)->state(['status' => 'in_progress'])->create();

        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withToken($token)
            ->getJson('/calls?status=completed');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($completed->id, $data[0]['id']);
        $this->assertSame('completed', $data[0]['status']);
    }

    public function test_index_searches_by_caller_name_and_numbers(): void
    {
        $user = User::factory()->create();
        $needleCall = Call::factory()->for($user)->create([
            'caller_name' => 'Alexandria Johnson',
            'from_number' => '+15551234567',
            'to_number' => '+15559876543',
        ]);
        Call::factory()->for($user)->create([
            'caller_name' => 'Other Person',
            'from_number' => '+19990000000',
            'to_number' => '+18880000000',
        ]);

        $token = JWTAuth::fromUser($user);

        foreach ([
            'Alexandria',
            '1234567',
            '9876543',
        ] as $searchTerm) {
            $response = $this
                ->withToken($token)
                ->getJson('/calls?search='.urlencode($searchTerm));

            $response->assertOk();
            $data = $response->json('data');

            $this->assertCount(1, $data);
            $this->assertSame($needleCall->id, $data[0]['id']);
        }
    }

    public function test_show_returns_call_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $call = Call::factory()->for($user)->create();
        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withToken($token)
            ->getJson('/calls/'.$call->id);

        $response->assertOk();

        $expected = CallResource::make($call->fresh())->resolve();

        $this->assertSame($expected, $response->json('data'));
    }

    public function test_update_allows_authenticated_user_to_modify_call(): void
    {
        $user = User::factory()->create();
        $call = Call::factory()->for($user)->state([
            'is_starred' => false,
            'status' => 'in_progress',
        ])->create();
        $token = JWTAuth::fromUser($user);

        $payload = [
            'is_starred' => true,
            'status' => 'archived',
        ];

        $response = $this
            ->withToken($token)
            ->putJson('/calls/'.$call->id, $payload);

        $response->assertOk();

        $data = $response->json('data');

        $this->assertTrue($data['isStarred']);
        $this->assertSame('archived', $data['status']);

        $this->assertDatabaseHas('calls', [
            'id' => $call->id,
            'is_starred' => true,
            'status' => 'archived',
        ]);
    }

    public function test_update_returns_not_found_for_call_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $call = Call::factory()->for($owner)->create();
        $otherUser = User::factory()->create();
        $token = JWTAuth::fromUser($otherUser);

        $this
            ->withToken($token)
            ->putJson('/calls/'.$call->id, ['is_starred' => true])
            ->assertNotFound();

        $this->assertDatabaseHas('calls', [
            'id' => $call->id,
            'is_starred' => $call->is_starred,
        ]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/calls')->assertUnauthorized();
    }

    public function test_update_requires_authentication(): void
    {
        $call = Call::factory()->create();

        $this->putJson('/calls/'.$call->id, ['is_starred' => true])
            ->assertUnauthorized();
    }
}
