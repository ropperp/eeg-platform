<?php

declare(strict_types=1);

// EDA-Mail-Import: nur Download-Links auf den bekannten EDA-Host (EdaAutoImporter::isTrustedDownloadUrl()).

test('EDA-Download: echter Link auf prod-api.eda-portal.at ist erlaubt', function () {
    assertTrue(EdaAutoImporter::isTrustedDownloadUrl('https://prod-api.eda-portal.at/exports/download/6a1b?expires=1&signature=ab'));
    assertTrue(EdaAutoImporter::isTrustedDownloadUrl('https://eda-portal.at/x'));
});

test('EDA-Download: fremde Hosts, interne Adressen und Täuschungsversuche werden abgelehnt', function () {
    foreach ([
        'https://evil.example/exports/download/x', 'http://prod-api.eda-portal.at/x',                 // http statt https
        'https://eda-portal.at.evil.example/x', 'https://evil-eda-portal.at/x',                        // nur ähnlich
        'https://eda-portal.at@evil.example/x', 'https://user:pw@prod-api.eda-portal.at/x',            // Benutzerteil-Trick
        'http://10.0.0.250/x', 'https://localhost/x', 'https://169.254.169.254/latest/meta-data',      // intern
        'ftp://eda-portal.at/x', 'file:///etc/passwd', 'javascript:alert(1)', '', 'not a url',
    ] as $url) {
        assertFalse(EdaAutoImporter::isTrustedDownloadUrl($url), "fälschlich erlaubt: {$url}");
    }
});
