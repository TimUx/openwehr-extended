<?php

namespace Extensions\Dateien\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Node extends Model
{
    use SoftDeletes;

    protected $table = 'x_files_nodes';

    protected $fillable = ['space_id', 'parent_id', 'name', 'kind', 'inherit_permissions'];

    protected function casts(): array
    {
        return ['inherit_permissions' => 'boolean'];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Version::class)->orderByDesc('number');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'target_id')->where('target_type', 'node');
    }
}
