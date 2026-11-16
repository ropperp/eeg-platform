<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Support\SupportTicketService;
use App\Http\Controllers\Api\SupportApiController;
use App\Http\Controllers\SupportController;
use App\Infrastructure\Database\PdoSupportTicketRepository;
use App\Infrastructure\Mail\MailSupportNotifier;

/**
 * Baut Controller und Services zusammen (einmal pro Anfrage). Bewusst ohne DI-Container: wenige,
 * explizite Fabrikmethoden reichen und bleiben nachvollziehbar.
 */
final class Services
{
    /** @var array<string, object> */
    private static array $instances = [];

    public static function supportController(): SupportController
    {
        return self::$instances[__FUNCTION__] ??= new SupportController(self::supportTickets(), self::supportService());
    }

    public static function supportApiController(): SupportApiController
    {
        return self::$instances[__FUNCTION__] ??= new SupportApiController(self::supportTickets(), self::supportService());
    }

    public static function supportService(): SupportTicketService
    {
        return self::$instances[__FUNCTION__] ??= new SupportTicketService(self::supportTickets(), new MailSupportNotifier());
    }

    public static function supportTickets(): PdoSupportTicketRepository
    {
        return self::$instances[__FUNCTION__] ??= new PdoSupportTicketRepository();
    }
}
