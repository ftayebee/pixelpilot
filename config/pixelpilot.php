<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Generated images
    |--------------------------------------------------------------------------
    |
    | Pixelforge image URLs expire 24 hours after a generation completes. By
    | default PixelPilot shows the provider URL until then. Set
    | PIXELPILOT_STORE_IMAGES=true to copy each image to a filesystem disk
    | (e.g. "s3") once it succeeds, so it keeps working afterwards.
    |
    */

    'images' => [
        'store' => (bool) env('PIXELPILOT_STORE_IMAGES', false),
        'disk' => env('PIXELPILOT_IMAGE_DISK', env('FILESYSTEM_DISK', 'local')),
        'max_bytes' => 20 * 1024 * 1024,
    ],

];
