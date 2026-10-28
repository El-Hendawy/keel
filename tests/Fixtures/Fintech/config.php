<?php

declare(strict_types=1);

// A test-only key pair: never reuse it.
return [
    'crypto' => [
        'e2e' => [
            'keys' => ['test-2026' => <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgux4PXtAikX/pUcQz
Nlc6PabpxnL/Kww6OOlcTPVOVMGhRANCAATSPvfVhqJggXGyey0PqQaWsLS9OXRm
T5XZRUr7veiHehXCsvSMJwOLKwodny7pdUxIJIdU0QxpccrBqk5n8YWb
-----END PRIVATE KEY-----
PEM],
            'required' => ['/secure/'],
        ],
    ],
];
