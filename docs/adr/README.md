# Architecture Decision Records (ADR)

Kurze Notizen zu Entscheidungen, die man später leicht wieder in Frage stellt. Format: Kontext,
Entscheidung, Folgen. Eine überholte Entscheidung wird nicht gelöscht, sondern als „ersetzt durch
ADR xxxx“ markiert.

| Nr. | Entscheidung | Status |
|---|---|---|
| [0001](0001-php-bleibt-backend.md) | PHP bleibt Backend, serverseitige Views, kein SPA | angenommen |
| [0002](0002-composer-psr4-ohne-framework.md) | Composer/PSR-4 und schrittweise Extraktion statt Framework | angenommen |
| [0003](0003-rls-laufzeitrolle.md) | Eingeschränkte DB-Rollen, RLS in Produktion Pflicht | angenommen |
| [0004](0004-eigener-auth-stack.md) | Eigener Auth-Stack statt externem Identity Provider | angenommen |
| [0005](0005-eda-import-als-worker.md) | EDA-Import als eigener Worker mit Jobtabelle | angenommen |
