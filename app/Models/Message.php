<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $fillable = [
        'channel_id',
        'user_id',
        'parent_id',
        'ciphertext',
        'system_type',
        'reply_count',
        'last_reply_at',
        'edited_at',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'parent_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function scopeRetained(Builder $query, Workspace $workspace): Builder
    {
        if ($workspace->retainsForever()) {
            return $query;
        }

        return $query->where(
            'messages.created_at',
            '>=',
            now()->subDays(config('smackchat.retention_days'))
        );
    }
}
