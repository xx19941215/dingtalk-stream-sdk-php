<?php

return [
    'clientId' => 'YOUR_APP_KEY',
    'clientSecret' => 'YOUR_APP_SECRET',
    'subscriptions' => [
        ['type' => 'EVENT', 'topic' => '*'],
        ['type' => 'CALLBACK', 'topic' => '*'],
    ],
    'ua' => 'example/1.0.0',
    'localIp' => '',
    'debug' => true,
];

