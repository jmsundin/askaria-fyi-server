<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class CallCollection extends ResourceCollection
{
    /**
     * @var string
     */
    public $collects = CallResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'perPage' => $this->resource->perPage(),
                'hasMore' => $this->resource->hasMorePages(),
                'nextCursor' => optional($this->resource->nextCursor())->encode(),
                'prevCursor' => optional($this->resource->previousCursor())->encode(),
            ],
        ];
    }
}


