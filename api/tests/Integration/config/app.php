<?php
// App-Konfiguration für die Integrationstests.
// SMTP bleibt bewusst leer: Die Tests dürfen keine Mails verschicken.

return [
    'base_url'         => getenv('CACHECOUNTY_TEST_BASE_URL') ?: 'http://127.0.0.1',
    'mail_from'        => 'test@example.com',
    'mail_from_name'   => 'CacheCounty Test',
    // Geschlossener Port: Der Versand scheitert sofort (bekannte Adressen testen den Fehlerpfad)
    'smtp_host'        => '127.0.0.1',
    'smtp_port'        => 1,
    'smtp_secure'      => '',
    'smtp_user'        => '',
    'smtp_pass'        => '',
    'trust_cloudflare' => false,

    // Rate Limiting mit kleinen Werten, damit die Tests schnell bleiben
    'magic_link_ip_limit_per_hour'    => 5,
    'magic_link_user_limit_per_15min' => 3,
    'magic_link_min_response_ms'      => 150,
];
