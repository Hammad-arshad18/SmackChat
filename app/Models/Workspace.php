<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'logo_path',
        'owner_id',
        'timezone',
        'work_start',
        'work_end',
        'work_days',
        'location_restricted',
        'location_latitude',
        'location_longitude',
        'location_radius_m',
        'plan',
        'plan_seats',
        'plan_activated_at',
    ];

    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'location_restricted' => 'boolean',
            'location_latitude' => 'float',
            'location_longitude' => 'float',
            'location_radius_m' => 'integer',
            'plan_activated_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function retainsForever(): bool
    {
        return $this->plan === 'archive';
    }

    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        $version = $this->updated_at?->getTimestamp() ?? $this->id;

        return '/api/workspaces/'.$this->slug.'/logo?v='.$version;
    }

    public function clockLabel(string $value): string
    {
        return substr((string) $value, 0, 5);
    }
}
