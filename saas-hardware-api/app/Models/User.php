<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Concerns\BelongsToTenant;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids, BelongsToTenant;

    // Usar UUID v7 ordenados cronológicamente para evitar fragmentación de índices en MySQL
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid7();
    }

    protected $fillable = [
        'tenant_id', 'name', 'email', 'phone', 'password', 'role', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'is_active'         => 'boolean',
        'password'          => 'hashed',    // bcrypt automático en Laravel 10+
    ];

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function favorites(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'user_favorites')->withTimestamps();
    }
}
