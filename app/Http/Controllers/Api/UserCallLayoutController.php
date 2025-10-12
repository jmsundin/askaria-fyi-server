<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserCallLayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserCallLayoutController extends Controller
{
    public function show(Request $request): JsonResource
    {
        $user = $request->user();

        $layout = $user->callLayout;

        return JsonResource::make([
            'sectionOrder' => $layout?->section_order ?? [],
            'collapsedSections' => $layout?->collapsed_sections ?? [],
        ]);
    }

    public function upsert(Request $request): JsonResource
    {
        $payload = $request->validate([
            'section_order' => ['required', 'array', 'max:10'],
            'section_order.*' => ['string'],
            'collapsed_sections' => ['required'],
        ]);

        $user = $request->user();

        $collapsedSections = $payload['collapsed_sections'];
        if (is_array($collapsedSections)) {
            $collapsedSections = collect($collapsedSections)
                ->map(static fn (mixed $value): bool => (bool) $value)
                ->all();
        } else {
            $collapsedSections = [];
        }

        /** @var UserCallLayout $layout */
        $layout = UserCallLayout::query()->updateOrCreate(
            [
                'user_id' => $user->getAuthIdentifier(),
            ],
            [
                'section_order' => array_values($payload['section_order']),
                'collapsed_sections' => $collapsedSections,
            ],
        );

        return JsonResource::make([
            'sectionOrder' => $layout->section_order,
            'collapsedSections' => $layout->collapsed_sections,
        ]);
    }
}

