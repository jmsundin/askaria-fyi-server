<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CallCollection;
use App\Http\Resources\CallResource;
use App\Models\Call;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CallController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $validated = $request->validate([
            'after' => ['nullable', 'date'],
            'before' => ['nullable', 'date'],
            'starred' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'search' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'in:in_progress,completed,archived'],
            'cursor' => ['nullable', 'string'],
            'to_number' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $businessNumber = PhoneNumber::normalize(optional($user->agentProfile)->business_phone_number);

        $query = Call::query()
            ->where(function (Builder $builder) use ($user, $businessNumber) {
                $builder->where('user_id', $user->getAuthIdentifier());

                if ($businessNumber !== null) {
                    $builder->orWhere('to_number', $businessNumber);
                }
            })
            ->orderByDesc('started_at')
            ->orderByDesc('id');

        if (! empty($validated['after'])) {
            $query->where('started_at', '>=', new CarbonImmutable($validated['after']));
        }

        if (! empty($validated['before'])) {
            $query->where('started_at', '<=', new CarbonImmutable($validated['before']));
        }

        if (Arr::exists($validated, 'starred')) {
            $query->where('is_starred', (bool) $validated['starred']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $normalized = Str::lower($validated['search']);
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $normalized);
            $term = '%'.$escaped.'%';

            $query->where(function (Builder $builder) use ($term) {
                $builder->whereRaw('LOWER(caller_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(from_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(to_number) LIKE ?', [$term]);
            });
        }

        if (! empty($validated['to_number'])) {
            $requestedNumber = PhoneNumber::normalize($validated['to_number']);

            if ($requestedNumber !== null) {
                $query->where('to_number', $requestedNumber);
            }
        }

        $perPage = (int) ($validated['limit'] ?? 25);

        $calls = $query->cursorPaginate(
            perPage: $perPage,
            columns: ['*'],
            cursorName: 'cursor',
            cursor: $validated['cursor'] ?? null,
        );

        return CallCollection::make($calls);
    }

    public function show(Request $request, string $callId): JsonResource
    {
        $call = $this->findForUserOrFail($request, $callId);

        return CallResource::make($call);
    }

    public function update(Request $request, string $callId): JsonResource
    {
        $call = $this->findForUserOrFail($request, $callId);

        $validated = $request->validate([
            'is_starred' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:in_progress,completed,archived'],
        ]);

        $call->fill($validated);
        $call->save();

        return CallResource::make($call->refresh());
    }

    protected function findForUserOrFail(Request $request, string $callId): Call
    {
        try {
            return Call::query()
                ->where(function (Builder $builder) use ($request) {
                    $builder->whereNull('user_id')
                        ->orWhere('user_id', $request->user()->getAuthIdentifier());
                })
                ->findOrFail($callId);
        } catch (ModelNotFoundException $exception) {
            abort(404, 'Call not found.');
        }
    }
}


