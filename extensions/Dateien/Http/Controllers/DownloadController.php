<?php

namespace Extensions\Dateien\Http\Controllers;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Version;
use Extensions\Dateien\Services\FileAccess;
use Extensions\Dateien\Services\FileService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DownloadController
{
    public function __invoke(Request $request, int $node, int $version, FileAccess $access, FileService $files): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $file = Node::query()->findOrFail($node);
        $access->authorize($user, $file, 'read');
        abort_unless($file->kind === 'file', 404);
        $revision = Version::query()->where('node_id', $file->id)->findOrFail($version);

        return $files->download($user, $file, $revision);
    }
}
