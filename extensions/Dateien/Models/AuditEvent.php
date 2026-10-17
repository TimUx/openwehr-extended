<?php

namespace Extensions\Dateien\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    protected $table = 'x_files_audit_events';

    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'action', 'space_id', 'node_id', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
