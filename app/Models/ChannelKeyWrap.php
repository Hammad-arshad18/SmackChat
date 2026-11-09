<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelKeyWrap extends Model
{
    protected $fillable = [
        'channel_id',
        'device_id',
        'wrapped',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(E2eDevice::class, 'device_id');
    }
}
