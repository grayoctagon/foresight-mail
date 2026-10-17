<?php
declare(strict_types=1);

// Copy to deployment.php. All relative paths start at the project root, not this config directory.
return [
    'schema_version' => 1,
    'app' => [
        'session_name' => 'foresight_session',
        'allow_registration' => true,
    ],
    'paths' => [
        'mailstore_root' => '../foresight-data/mailstore',
        'secret_store_root' => '../foresight-data/secrets',
    ],
];
