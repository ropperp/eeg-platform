<?php

declare(strict_types=1);

namespace App\Http\Responses;

/** PDF-Antwort (inline im Browser) aus Bytes oder einer Datei; nie öffentlich zwischengespeichert. */
final class PdfResponse implements Response
{
    private function __construct(
        private readonly ?string $bytes,
        private readonly ?string $path,
        private readonly string $filename,
    ) {
    }

    public static function fromBytes(string $bytes, string $filename): self
    {
        return new self($bytes, null, $filename);
    }

    public static function fromFile(string $path, string $filename): self
    {
        return new self(null, $path, $filename);
    }

    public function send(): void
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $this->filename) ?: 'dokument.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $safe . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if ($this->path !== null) {
            header('Content-Length: ' . (string) filesize($this->path));
            readfile($this->path);
            return;
        }
        header('Content-Length: ' . strlen((string) $this->bytes));
        echo $this->bytes;
    }
}
