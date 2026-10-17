<?php

namespace Extensions\Dateien\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Space extends Model
{
    protected $table = 'x_files_spaces';

    protected $fillable = ['name', 'ext_location_id', 'quota_bytes'];

    protected function casts(): array
    {
        return ['quota_bytes' => 'integer', 'ext_location_id' => 'integer'];
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'target_id')->where('target_type', 'space');
    }
}
