<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Call extends Model
{
    public const AUDIO = 'audio';

    public const VIDEO = 'video';

    public const LIVE = 'live';

    public const ENDED = 'ended';

    protected $fillable = [
        'workspace_id',
        'channel_id',
        'started_by',
        'kind',
        'status',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CallParticipant::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(CallSignal::class);
    }

    public function isLive(): bool
    {
        return $this->status === self::LIVE;
    }
}
