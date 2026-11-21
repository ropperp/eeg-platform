<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Assembly\AssemblyService;
use App\Domain\EdaImport\EdaImportJobs;
use App\Domain\Statutes\StatuteInviteService;
use App\Domain\Support\SupportTicketService;
use App\Http\Controllers\Api\SupportApiController;
use App\Http\Controllers\AssemblyController;
use App\Http\Controllers\KeycloakAuthController;
use App\Http\Controllers\StatuteAdminController;
use App\Http\Controllers\StatuteController;
use App\Http\Controllers\SupportController;
use App\Infrastructure\Auth\KeycloakConfig;
use App\Infrastructure\Auth\KeycloakOidc;
use App\Infrastructure\Database\PdoAssemblyRepository;
use App\Infrastructure\Database\PdoEdaImportJobRepository;
use App\Infrastructure\Database\PdoStatuteInviteRepository;
use App\Infrastructure\Database\PdoSupportTicketRepository;
use App\Infrastructure\Mail\MailAssemblySignLinkMailer;
use App\Infrastructure\Mail\MailSupportNotifier;
use App\Infrastructure\Pdf\LatexAssemblyPdfRenderer;
use App\Infrastructure\Pdf\LatexStatutePdfRenderer;

/**
 * Baut Controller und Services zusammen (einmal pro Anfrage). Bewusst ohne DI-Container: wenige,
 * explizite Fabrikmethoden reichen und bleiben nachvollziehbar.
 */
final class Services
{
    /** @var array<string, object> */
    private static array $instances = [];

    public static function keycloakAuth(): KeycloakAuthController
    {
        if (!isset(self::$instances[__FUNCTION__])) {
            $cfg = KeycloakConfig::fromEnv();
            self::$instances[__FUNCTION__] = new KeycloakAuthController($cfg, new KeycloakOidc($cfg));
        }
        /** @var KeycloakAuthController */
        return self::$instances[__FUNCTION__];
    }

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

    public static function edaImportJobs(): EdaImportJobs
    {
        return self::$instances[__FUNCTION__] ??= new EdaImportJobs(new PdoEdaImportJobRepository());
    }

    public static function supportTickets(): PdoSupportTicketRepository
    {
        return self::$instances[__FUNCTION__] ??= new PdoSupportTicketRepository();
    }

    public static function assemblyController(): AssemblyController
    {
        return self::$instances[__FUNCTION__] ??= new AssemblyController(self::assemblies(), self::assemblyService());
    }

    public static function assemblyService(): AssemblyService
    {
        return self::$instances[__FUNCTION__] ??= new AssemblyService(
            self::assemblies(),
            new LatexAssemblyPdfRenderer(),
            new MailAssemblySignLinkMailer(),
            '/var/www/html/storage/assemblies'
        );
    }

    public static function assemblies(): PdoAssemblyRepository
    {
        return self::$instances[__FUNCTION__] ??= new PdoAssemblyRepository();
    }

    public static function statuteController(): StatuteController
    {
        return self::$instances[__FUNCTION__] ??= new StatuteController(self::statuteInvites(), self::statuteService());
    }

    public static function statuteAdminController(): StatuteAdminController
    {
        return self::$instances[__FUNCTION__] ??= new StatuteAdminController(self::statuteInvites(), self::statuteService());
    }

    public static function statuteService(): StatuteInviteService
    {
        return self::$instances[__FUNCTION__] ??= new StatuteInviteService(
            self::statuteInvites(),
            new LatexStatutePdfRenderer(),
            '/var/www/html/storage/statuten'
        );
    }

    public static function statuteInvites(): PdoStatuteInviteRepository
    {
        return self::$instances[__FUNCTION__] ??= new PdoStatuteInviteRepository();
    }
}
