<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'observed_on',
        'repeats_yearly',
    ];

    protected function casts(): array
    {
        return [
            'observed_on' => 'date',
            'repeats_yearly' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
