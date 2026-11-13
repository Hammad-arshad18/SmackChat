<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class E2eVault extends Model
{
    protected $fillable = [
        'user_id',
        'salt',
        'wrapped_master',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
