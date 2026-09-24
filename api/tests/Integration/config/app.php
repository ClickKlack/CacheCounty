<?php
// App-Konfiguration für die Integrationstests.
// SMTP bleibt bewusst leer: Die Tests dürfen keine Mails verschicken.

return [
    'base_url'         => getenv('CACHECOUNTY_TEST_BASE_URL') ?: 'http://127.0.0.1',
    'mail_from'        => 'test@example.com',
    'mail_from_name'   => 'CacheCounty Test',
    'smtp_host'        => '',
    'smtp_port'        => 587,
    'smtp_secure'      => '',
    'smtp_user'        => '',
    'smtp_pass'        => '',
    'trust_cloudflare' => false,
];
