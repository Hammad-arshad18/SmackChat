<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    public const PUBLIC = 'public';

    public const PRIVATE = 'private';

    public const DM = 'dm';

    public const ATTENDANCE = 'attendance';

    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'type',
        'description',
        'topic',
        'created_by',
        'dm_key',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'channel_user')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isOpen(): bool
    {
        return $this->type === self::PUBLIC;
    }
}
