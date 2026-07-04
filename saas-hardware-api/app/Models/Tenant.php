<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Concerns\UsesMultitenancyConfig;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;

class Tenant extends Model implements IsTenant
{
    use HasUuids, ImplementsTenant, UsesMultitenancyConfig;

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = [
        'slug', 'name', 'logo_url', 'primary_color', 'theme',
        'whatsapp_number', 'plan', 'is_active', 'custom_domain',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'theme'     => 'array',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
