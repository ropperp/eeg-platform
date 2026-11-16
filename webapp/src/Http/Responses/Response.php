<?php

declare(strict_types=1);

namespace App\Http\Responses;

/** Antwort eines Controllers; die Route ruft send() auf. */
interface Response
{
    public function send(): void;
}
