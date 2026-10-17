<?php

namespace Extensions\Dateien\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $table = 'x_files_permissions';

    protected $fillable = ['subject_type', 'subject_id', 'target_type', 'target_id', 'level'];

    protected $hidden = ['subject_user_id', 'subject_group_id', 'target_space_id', 'target_node_id'];
}
