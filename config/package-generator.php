<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Base Directory
    |--------------------------------------------------------------------------
    |
    | This path specifies the root directory where newly generated optional
    | packages will be placed. By default, it resolves to packages/.
    |
    */
    'packages_path' => env('LARASEED_PACKAGES_PATH', 'packages'),

    /*
    |--------------------------------------------------------------------------
    | Advisory File Locking
    |--------------------------------------------------------------------------
    |
    | Configuration for cross-process concurrency locking during package
    | generation. Timeout specifies maximum seconds to wait for a held lock.
    |
    */
    'locking' => [
        'enabled' => true,
        'timeout' => 5.0,
        'locks_directory' => storage_path('framework/locks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Web Template Registrations
    |--------------------------------------------------------------------------
    |
    | Register external web capability templates that can be passed to
    | php artisan laraseed:make-web <pkg> --template=<name>.
    |
    */
    'web_templates' => [
        // 'admin-portal' => [
        //     'label' => 'Admin Portal Template',
        //     'description' => 'Custom enterprise dashboard template',
        //     'path' => base_path('stubs/custom-web-template'),
        // ],
    ],
];
