<?php

namespace Extensions\Dateien\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Version extends Model
{
    protected $table = 'x_files_versions';

    public const UPDATED_AT = null;

    protected $fillable = ['node_id', 'number', 'storage_key', 'checksum', 'size', 'mime', 'extension', 'created_by', 'source'];

    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'size' => 'integer'];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
