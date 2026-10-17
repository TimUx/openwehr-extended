<?php

namespace Extensions\Dateien\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FileGroup extends Model
{
    protected $table = 'x_files_groups';

    protected $fillable = ['name', 'ext_location_id'];

    protected function casts(): array
    {
        return ['ext_location_id' => 'integer'];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'x_files_group_members', 'group_id', 'user_id')->withPivot('is_manager');
    }

    public function users(): BelongsToMany
    {
        return $this->members();
    }
}
