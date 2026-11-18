<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallSignal extends Model
{
    protected $fillable = [
        'call_id',
        'from_user_id',
        'to_user_id',
        'type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
