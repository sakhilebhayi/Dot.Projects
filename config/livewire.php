<?php

/*
 * Only what Dot.Projects changes from Livewire's defaults; every other key keeps the package value.
 * Temporary uploads allow up to 25 MB (R-34 P11b), the most Dot.Files accepts from another app,
 * and stay on the local disk until they are filed in Dot.Files.
 */
return [
    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => ['required', 'file', 'max:25600'],
        'directory' => null,
        'middleware' => 'throttle:30,1',
        'preview_mimes' => ['png', 'gif', 'jpg', 'jpeg', 'webp'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
