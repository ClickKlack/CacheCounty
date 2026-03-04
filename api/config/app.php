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
];
