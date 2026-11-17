<?php

declare(strict_types=1);

namespace App\Domain\Assembly;

/** Verschickt den Einmal-Link zum Unterschreiben eines Protokolls. */
interface AssemblySignLinkMailer
{
    /**
     * @param array<string, mixed> $signer
     * @param array<string, mixed> $assembly
     */
    public function send(array $signer, array $assembly, string $communityName, string $url, string $expiresAt): void;
}
