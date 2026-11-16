<?php

declare(strict_types=1);

namespace App\Http\Responses;

/**
 * Rendert ein PHP-Template aus src/views mit genau den übergebenen Variablen. Templates enthalten
 * nur Darstellung; Daten holen die Controller vorher.
 */
final class HtmlResponse implements Response
{
    /** @param array<string, mixed> $vars */
    public function __construct(
        private readonly string $template,
        private readonly array $vars = [],
        private readonly int $status = 200,
    ) {
    }

    /** Einfache Textantwort (z. B. 404 "Ticket nicht gefunden."), wie die bisherigen echo-Stellen. */
    public static function text(string $text, int $status): self
    {
        return new self('', ['__text' => $text], $status);
    }

    public function send(): void
    {
        if ($this->status !== 200) {
            http_response_code($this->status);
        }
        if ($this->template === '') {
            echo $this->vars['__text'] ?? '';
            return;
        }
        (static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require $__file;
        })(ROOT . '/src/views/' . $this->template . '.php', $this->vars);
    }
}
