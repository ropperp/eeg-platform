<?php

declare(strict_types=1);

/**
 * Passkey-Anmeldung (WebAuthn Level 2), 17.11.2026.
 *
 * Bewusst ohne Fremdbibliothek (kein Composer in diesem Projekt): ein kleiner CBOR-Leser, die
 * Umwandlung des öffentlichen COSE-Schlüssels in PEM und die Prüfung von Registrierung und
 * Anmeldung nach https://www.w3.org/TR/webauthn-2/#sctn-registering-a-new-credential bzw.
 * #sctn-verifying-assertion. Unterstützt werden ES256 (P-256, fast alle Passkeys) und RS256
 * (Windows Hello). Die Attestation wird nicht ausgewertet ("none") -- für Anmeldung ohne
 * Hardware-Herkunftsnachweis ist das der Standard; so entsteht auch kein Datenschutzproblem durch
 * Geräte-Kennungen.
 *
 * Alle prüfenden Funktionen werfen bei jedem Fehler eine \RuntimeException; nur ein vollständig
 * gültiger Ablauf liefert ein Ergebnis. Die Klasse selbst greift nicht auf DB/Session zu (testbar).
 */
final class WebAuthn
{
    public const ALG_ES256 = -7;
    public const ALG_RS256 = -257;

    // ── Kodierung ────────────────────────────────────────────────────────────────

    public static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $raw = base64_decode(strtr($s, '-_', '+/'), true);
        if ($raw === false) {
            throw new \RuntimeException('Ungültige Base64URL-Daten');
        }
        return $raw;
    }

    // ── Gegenstelle (Domain / Herkunft) ──────────────────────────────────────────

    /**
     * rpId = Domain, für die der Passkey gilt. Alle Unterdomains der Plattform (portal., admin., …)
     * teilen sich die Haupt-Domain, damit ein Passkey überall funktioniert; sonst der Host selbst.
     */
    public static function rpIdForHost(string $host, string $platformDomain): string
    {
        $host = strtolower(preg_replace('/:\d+$/', '', trim($host)) ?? '');
        $domain = strtolower(trim($platformDomain));
        if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
            return $domain;
        }
        return $host;
    }

    /** Ist die vom Browser gemeldete Herkunft (origin) für diese rpId zulässig? */
    public static function originAllowed(string $origin, string $rpId): bool
    {
        $p = parse_url($origin);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host']) || isset($p['path']) && $p['path'] !== '' && $p['path'] !== '/') {
            return false;
        }
        $host = strtolower($p['host']);
        $isLocal = $host === 'localhost' || $host === '127.0.0.1';
        if ($p['scheme'] !== 'https' && !($p['scheme'] === 'http' && $isLocal)) {
            return false;
        }
        return $host === $rpId || str_ends_with($host, '.' . $rpId);
    }

    // ── CBOR (nur der benötigte Teil) ────────────────────────────────────────────

    /** Liest einen CBOR-Wert ab $offset; $offset wird hinter den Wert gesetzt. */
    public static function cborDecode(string $data, int &$offset = 0, int $depth = 0)
    {
        if ($depth > 16 || $offset >= strlen($data)) {
            throw new \RuntimeException('CBOR ungültig');
        }
        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $info = $initial & 0x1f;
        $len = self::cborLength($data, $offset, $info);

        switch ($major) {
            case 0:
                return $len;
            case 1:
                return -1 - $len;
            case 2:
            case 3:
                if ($offset + $len > strlen($data)) {
                    throw new \RuntimeException('CBOR zu kurz');
                }
                $s = substr($data, $offset, $len);
                $offset += $len;
                return $s;
            case 4:
                if ($len > 1024) throw new \RuntimeException('CBOR-Array zu groß');
                $arr = [];
                for ($i = 0; $i < $len; $i++) {
                    $arr[] = self::cborDecode($data, $offset, $depth + 1);
                }
                return $arr;
            case 5:
                if ($len > 1024) throw new \RuntimeException('CBOR-Map zu groß');
                $map = [];
                for ($i = 0; $i < $len; $i++) {
                    $k = self::cborDecode($data, $offset, $depth + 1);
                    if (!is_int($k) && !is_string($k)) {
                        throw new \RuntimeException('CBOR-Schlüssel nicht unterstützt');
                    }
                    $map[$k] = self::cborDecode($data, $offset, $depth + 1);
                }
                return $map;
            case 6:
                return self::cborDecode($data, $offset, $depth + 1); // Tag ignorieren
            case 7:
                if ($info === 20) return false;
                if ($info === 21) return true;
                if ($info === 22 || $info === 23) return null;
                throw new \RuntimeException('CBOR-Typ nicht unterstützt');
        }
        throw new \RuntimeException('CBOR ungültig');
    }

    private static function cborLength(string $data, int &$offset, int $info): int
    {
        if ($info < 24) return $info;
        $bytes = match ($info) { 24 => 1, 25 => 2, 26 => 4, 27 => 8, default => throw new \RuntimeException('CBOR-Länge nicht unterstützt') };
        if ($offset + $bytes > strlen($data)) {
            throw new \RuntimeException('CBOR zu kurz');
        }
        $n = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $n = ($n << 8) | ord($data[$offset + $i]);
        }
        $offset += $bytes;
        if ($n < 0) {
            throw new \RuntimeException('CBOR-Zahl zu groß');
        }
        return $n;
    }

    // ── Öffentlicher Schlüssel (COSE → PEM) ──────────────────────────────────────

    private static function derLen(int $n): string
    {
        if ($n < 128) return chr($n);
        $b = ltrim(pack('N', $n), "\0");
        return chr(0x80 | strlen($b)) . $b;
    }

    private static function derTlv(int $tag, string $content): string
    {
        return chr($tag) . self::derLen(strlen($content)) . $content;
    }

    private static function derUInt(string $be): string
    {
        $be = ltrim($be, "\0");
        if ($be === '') $be = "\0";
        if (ord($be[0]) & 0x80) $be = "\0" . $be;
        return self::derTlv(0x02, $be);
    }

    private static function pem(string $spkiDer): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spkiDer), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** @param array<int,mixed> $cose */
    public static function coseToPem(array $cose): string
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;
        if ($kty === 2 && $alg === self::ALG_ES256) {
            if (($cose[-1] ?? null) !== 1) {
                throw new \RuntimeException('Nur P-256 wird unterstützt');
            }
            $x = $cose[-2] ?? null;
            $y = $cose[-3] ?? null;
            if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new \RuntimeException('Ungültiger EC-Schlüssel');
            }
            $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
            return self::pem($prefix . "\x04" . $x . $y);
        }
        if ($kty === 3 && $alg === self::ALG_RS256) {
            $n = $cose[-1] ?? null;
            $e = $cose[-2] ?? null;
            if (!is_string($n) || !is_string($e) || strlen($n) < 256 || strlen($e) < 1) {
                throw new \RuntimeException('Ungültiger RSA-Schlüssel');
            }
            $rsaKey = self::derTlv(0x30, self::derUInt($n) . self::derUInt($e));
            $algId = self::derTlv(0x30, hex2bin('06092a864886f70d010101') . "\x05\x00");
            return self::pem(self::derTlv(0x30, $algId . self::derTlv(0x03, "\0" . $rsaKey)));
        }
        throw new \RuntimeException('Schlüsselverfahren nicht unterstützt');
    }

    // ── Gemeinsame Prüfungen ─────────────────────────────────────────────────────

    /** @return array{challenge:string,origin:string,type:string} */
    private static function parseClientData(string $clientDataJson, string $expectedType, string $expectedChallenge, string $rpId): array
    {
        $cd = json_decode($clientDataJson, true);
        if (!is_array($cd)) {
            throw new \RuntimeException('clientDataJSON ungültig');
        }
        if (($cd['type'] ?? null) !== $expectedType) {
            throw new \RuntimeException('Falscher Anfragetyp');
        }
        if (!is_string($cd['challenge'] ?? null) || !hash_equals($expectedChallenge, $cd['challenge'])) {
            throw new \RuntimeException('Challenge stimmt nicht');
        }
        if (!is_string($cd['origin'] ?? null) || !self::originAllowed($cd['origin'], $rpId)) {
            throw new \RuntimeException('Herkunft nicht zulässig');
        }
        if (!empty($cd['crossOrigin'])) {
            throw new \RuntimeException('Fremde Herkunft (crossOrigin)');
        }
        return $cd;
    }

    /** @return array{flags:int,signCount:int,rest:string} */
    private static function parseAuthData(string $authData, string $rpId): array
    {
        if (strlen($authData) < 37) {
            throw new \RuntimeException('authenticatorData zu kurz');
        }
        if (!hash_equals(hash('sha256', $rpId, true), substr($authData, 0, 32))) {
            throw new \RuntimeException('rpIdHash stimmt nicht');
        }
        $flags = ord($authData[32]);
        if (!($flags & 0x01)) {
            throw new \RuntimeException('Anwesenheit (UP) fehlt');
        }
        // Der Passkey soll den Benutzer selbst prüfen (Fingerabdruck/Gesicht/PIN) -- dann ist die
        // Anmeldung allein schon zwei Faktoren (Besitz + Biometrie/Wissen).
        if (!($flags & 0x04)) {
            throw new \RuntimeException('Benutzerprüfung (UV) fehlt');
        }
        $signCount = unpack('N', substr($authData, 33, 4))[1];
        return ['flags' => $flags, 'signCount' => (int)$signCount, 'rest' => substr($authData, 37)];
    }

    // ── Registrierung ────────────────────────────────────────────────────────────

    /**
     * @return array{credentialId:string,publicKeyPem:string,signCount:int,alg:int}  credentialId roh
     */
    public static function verifyRegistration(string $clientDataJson, string $attestationObject, string $expectedChallenge, string $rpId): array
    {
        self::parseClientData($clientDataJson, 'webauthn.create', $expectedChallenge, $rpId);

        $off = 0;
        $att = self::cborDecode($attestationObject, $off);
        if (!is_array($att) || !is_string($att['authData'] ?? null)) {
            throw new \RuntimeException('attestationObject ungültig');
        }
        $auth = self::parseAuthData($att['authData'], $rpId);
        if (!($auth['flags'] & 0x40)) {
            throw new \RuntimeException('Keine Zugangsdaten (AT) enthalten');
        }
        $rest = $auth['rest'];
        if (strlen($rest) < 18) {
            throw new \RuntimeException('Zugangsdaten zu kurz');
        }
        $idLen = unpack('n', substr($rest, 16, 2))[1];
        if ($idLen < 16 || $idLen > 1023 || strlen($rest) < 18 + $idLen) {
            throw new \RuntimeException('Ungültige Credential-ID');
        }
        $credentialId = substr($rest, 18, $idLen);
        $coseRaw = substr($rest, 18 + $idLen);
        $o = 0;
        $cose = self::cborDecode($coseRaw, $o);
        if (!is_array($cose)) {
            throw new \RuntimeException('Öffentlicher Schlüssel ungültig');
        }
        $pem = self::coseToPem($cose);
        if (openssl_pkey_get_public($pem) === false) {
            throw new \RuntimeException('Öffentlicher Schlüssel nicht lesbar');
        }
        return ['credentialId' => $credentialId, 'publicKeyPem' => $pem, 'signCount' => $auth['signCount'], 'alg' => (int)$cose[3]];
    }

    // ── Anmeldung ────────────────────────────────────────────────────────────────

    /**
     * Prüft eine Anmelde-Antwort gegen den gespeicherten Schlüssel. Gibt den neuen Zählerstand zurück.
     * @param string $storedPem   gespeicherter öffentlicher Schlüssel (PEM)
     * @param int    $storedCount gespeicherter Signatur-Zähler
     */
    public static function verifyAssertion(
        string $clientDataJson, string $authenticatorData, string $signature,
        string $storedPem, int $storedCount, string $expectedChallenge, string $rpId
    ): int {
        self::parseClientData($clientDataJson, 'webauthn.get', $expectedChallenge, $rpId);
        $auth = self::parseAuthData($authenticatorData, $rpId);

        $signed = $authenticatorData . hash('sha256', $clientDataJson, true);
        $ok = openssl_verify($signed, $signature, $storedPem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new \RuntimeException('Signatur ungültig');
        }
        // Zähler: viele Passkeys (z. B. iCloud-Schlüsselbund) melden dauerhaft 0 -- dann keine
        // Prüfung. Meldet ein Gerät einen Zähler, muss er steigen, sonst ist der Schlüssel kopiert.
        if (($auth['signCount'] > 0 || $storedCount > 0) && $auth['signCount'] <= $storedCount) {
            throw new \RuntimeException('Zähler nicht gestiegen (möglicherweise kopierter Schlüssel)');
        }
        return $auth['signCount'];
    }
}
