<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\Call $resource
 */
class CallResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->resource->id,
            'callSid' => $this->resource->call_sid,
            'sessionId' => $this->resource->session_id,
            'fromNumber' => $this->resource->from_number,
            'toNumber' => $this->resource->to_number,
            'forwardedFrom' => $this->resource->forwarded_from,
            'callerName' => $this->resource->caller_name,
            'status' => $this->resource->status,
            'isStarred' => $this->resource->is_starred,
            'recordingUrl' => $this->resource->recording_url,
            'summary' => $this->resource->summary,
            'transcriptMessages' => $this->resource->transcript_messages,
            'transcriptText' => $this->resource->transcript_text,
            'startedAt' => optional($this->resource->started_at)->toIso8601String(),
            'endedAt' => optional($this->resource->ended_at)->toIso8601String(),
            'durationSeconds' => $this->resource->duration_seconds,
            'createdAt' => $this->resource->created_at->toIso8601String(),
            'updatedAt' => $this->resource->updated_at->toIso8601String(),
        ];
    }
}


