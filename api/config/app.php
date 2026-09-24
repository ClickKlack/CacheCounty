<?php
// Template – kopiere diese Datei zu app.local.php und passe die Werte an.
// app.local.php ist via .gitignore aus der Versionskontrolle ausgeschlossen.

return [
    // Basis-URL der Applikation (ohne abschließenden Slash)
    'base_url'       => 'https://example.com',

    // E-Mail-Absender
    'mail_from'      => 'noreply@example.com',
    'mail_from_name' => 'CacheCounty',

    // SMTP-Konfiguration (PHPMailer)
    'smtp_host'      => 'smtp.example.com',
    'smtp_port'      => 587,
    'smtp_secure'    => 'tls',   // 'tls', 'ssl' oder '' für keine Verschlüsselung
    'smtp_user'      => 'smtp-user@example.com',
    'smtp_pass'      => 'smtp-password',

    // Nur auf true setzen, wenn der Server ausschließlich über Cloudflare erreichbar ist.
    // Sonst kann jeder Client per CF-Connecting-IP-Header eine beliebige IP vortäuschen.
    'trust_cloudflare' => false,

    // Weitere Origins, von denen schreibende Requests kommen dürfen (neben base_url).
    // Nur nötig, wenn das Frontend lokal unter anderer Adresse läuft und der Browser
    // kein Sec-Fetch-Site sendet, z. B. ['http://localhost:8080']. In Produktion leer.
    'allowed_origins' => [],
];
