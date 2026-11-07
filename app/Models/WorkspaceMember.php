<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMember extends Model
{
    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const MEMBER = 'member';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function managesOffice(): bool
    {
        return in_array($this->role, [self::OWNER, self::ADMIN], true);
    }
}
