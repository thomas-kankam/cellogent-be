<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Permission extends Model
{
    protected $fillable = [
        'name',
        'module',
    ];

    protected $hidden = [
        'updated_at',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permissions')->withTimestamps();
    }

    /**
     * Group permissions under their module for login / profile / role payloads.
     *
     * @param  iterable<int, Permission|array{id: int|string, module: string, name: string}>  $permissions
     * @return list<array{module: string, permissions: list<array{id: int, name: string}>}>
     */
    public static function groupByModule(iterable $permissions): array
    {
        return Collection::make($permissions)
            ->map(fn ($p) => [
                'id' => (int) (is_array($p) ? $p['id'] : $p->id),
                'module' => (string) (is_array($p) ? $p['module'] : $p->module),
                'name' => (string) (is_array($p) ? $p['name'] : $p->name),
            ])
            ->sortBy(['module', 'name'])
            ->groupBy('module')
            ->map(fn (Collection $items, string $module) => [
                'module' => $module,
                'permissions' => $items->map(fn (array $p) => [
                    'id' => $p['id'],
                    'name' => $p['name'],
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
