<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | The package looks in resources/js/pages by default, and this project
    | keeps its components in resources/js/Pages. On a case-insensitive file
    | system the two are the same directory, so nothing complains locally; on
    | Linux the finder comes up empty and every assertInertia()->component()
    | fails with "page component file does not exist".
    |
    | The extensions are repeated here on purpose. Laravel merges package
    | configuration one level deep, so declaring 'pages' at all replaces the
    | package's whole block - leaving them out would take the extensions with
    | it, and then nothing would resolve anywhere.
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | assertInertia()->component() checks that the component it was handed
    | exists on disk. That is worth keeping: it catches a renamed or misspelled
    | page, which otherwise only shows up as a blank screen in the browser.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];
