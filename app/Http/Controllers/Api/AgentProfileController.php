<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAgentProfileRequest;
use App\Models\AgentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $profile = $user->agentProfile;

        if ($profile === null) {
            return response()->json([
                'business_name' => $user->name,
                'business_phone_number' => null,
                'business_overview' => null,
            ]);
        }

        return response()->json([
            'business_name' => $user->name,
            'business_phone_number' => $profile->business_phone_number,
            'business_overview' => $profile->business_overview,
        ]);
    }

    public function update(UpdateAgentProfileRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $validated = $request->validated();

        return DB::transaction(function () use ($user, $validated) {
            $user->update([
                'name' => $validated['business_name'],
            ]);

            /** @var AgentProfile $profile */
            $profile = $user->agentProfile()->firstOrNew([]);
            $profile->fill([
                'business_phone_number' => $validated['business_phone_number'],
                'business_overview' => $validated['business_overview'],
            ]);
            $user->agentProfile()->save($profile);

            return response()->json([
                'business_name' => $user->name,
                'business_phone_number' => $profile->business_phone_number,
                'business_overview' => $profile->business_overview,
            ]);
        });
    }
}
