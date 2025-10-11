<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Call extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'call_sid',
        'session_id',
        'from_number',
        'to_number',
        'forwarded_from',
        'caller_name',
        'status',
        'is_starred',
        'recording_url',
        'summary',
        'transcript_messages',
        'transcript_text',
        'started_at',
        'ended_at',
        'duration_seconds',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'summary' => 'array',
        'transcript_messages' => 'array',
        'is_starred' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}


