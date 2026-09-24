<?php
// Datenbank-Konfiguration für die Integrationstests.
// Werte kommen aus Umgebungsvariablen, siehe README → „Integrationstests".

return [
    'host' => getenv('CACHECOUNTY_TEST_DB_HOST') ?: 'localhost',
    'name' => getenv('CACHECOUNTY_TEST_DB_NAME') ?: '',
    'user' => getenv('CACHECOUNTY_TEST_DB_USER') ?: '',
    'pass' => getenv('CACHECOUNTY_TEST_DB_PASS') ?: '',
];
