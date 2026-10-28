# platform-inbox — aufgelöst (Grabstein)

Dieses Modul existiert nicht mehr als eigenständiges Paket.

- **Engine → Core:** Die komplette Inbox-Engine (InboxItem + Satelliten-Models,
  Ingestion/Enrichment/Query/Handoff/Linking-Services, Kanal-Routing, Commands +
  Scheduler, MCP-Tools, Plaud-Quelle, Zeit-Kontext) lebt jetzt unter
  `Platform\Core\Inbox` und wird vom `Platform\Core\Inbox\InboxEngineServiceProvider`
  (registriert durch den `CoreServiceProvider`) verdrahtet.
- **Gesicht → home:** Der Eingang (UI) lebt im home-Modul und koppelt nur an die
  Core-Inbox-Contracts.
- **API:** `/api/inbox/meetings/current` läuft jetzt über `core/routes/api.php`.
- **Migrations:** nach `core/database/migrations` verschoben.

Dieses Paket ist ein leerer Grabstein — kein ServiceProvider, keine Klassen,
kein Autoload. Aus der Instanz-`composer.json` (`require` + `repositories`)
entfernen und das Repo archivieren.
