<?php

return [
    'max_upload_kb' => 20480,
    'extensions' => \App\Support\FileResponse::allowedExtensions(),
    'retention_days' => 30,
];
