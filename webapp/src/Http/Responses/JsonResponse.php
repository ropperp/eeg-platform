<?php

declare(strict_types=1);

namespace App\Http\Responses;

final class JsonResponse implements Response
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly array $data, public readonly int $status = 200)
    {
    }

    /** @return self Fehlerantwort im Format der App-API: {"error": "..."} */
    public static function error(string $message, int $status): self
    {
        return new self(['error' => $message], $status);
    }

    public function send(): void
    {
        if ($this->status !== 200) {
            http_response_code($this->status);
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($this->data);
    }
}
