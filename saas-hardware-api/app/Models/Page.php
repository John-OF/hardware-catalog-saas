<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Page extends Model
{
    use HasUuids, BelongsToTenant;

    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = ['title', 'slug', 'content', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
