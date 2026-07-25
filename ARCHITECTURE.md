# Inbox — die Intake- & Triage-Engine

> **Kurz:** Die Inbox ist **kein Mail-Client und keine UI**. Sie ist die Schicht, die
> Eingehendes aus allen Kanälen zu **einem** persönlichen, triagierbaren Strom
> zusammenführt, an die **Organisation** hängt und daraus **Arbeit** machbar macht.
> Das Gesicht (der „Eingang") lebt im **home**-Modul.

## Die drei Schichten

```
services/user-connectors     →     modules/inbox     →     home (Eingang)
      Die Pipes                       Das Hirn                Das Gesicht
```

| Schicht | Ort | Job | Hängt an |
|---|---|---|---|
| **user-connectors** | `services/` (Infra) | Per-User-OAuth zu M365 / RingCentral / Sipgate / Vodafone; zieht **rohe Sessions** rein (Mail/Call/Meeting/Message/Chat), Webhooks, Senden | (fast) nichts — Infra, dependency-leicht |
| **inbox** | `modules/` (Feature) | Vereinheitlicht die Sessions zu `InboxItem`; Triage-Lifecycle; **Org-Verlinkung**; KI-Anreicherung; Handoff; interne Zustellung | organization, planner, helpdesk (soft-coupled) |
| **home** | `modules/home` | Die UI: 3-Pane-Eingang (Kanäle · Liste · Reading-Pane), Aktionen | konsumiert `InboxItem` |

**Warum getrennt:** `user-connectors` redet mit der Außenwelt (Infra, hängt an
nichts). Die Inbox macht aus Eingehendem **Org-Arbeit** und hängt dafür an
organization/planner/helpdesk. Zwei verschiedene Jobs, zwei Schichten — die Inbox
gehört deshalb in `modules/`, nicht in den Infra-Service.

## Was die Inbox IST

- **`InboxItem`** — ein kanal-übergreifender Strom pro User (`user_id`, `team_id`,
  `channel`, `source_type/source_id`, `subject/preview/body`, `status`,
  `snoozed_until`, `importance_score`, `received_at`).
- **Triage-Lifecycle** — `new → snoozed → done/ignored/archived`, Snooze, Wichtigkeit.
- **Org-Verlinkung** — `InboxLinkRule` hängt Items regelbasiert an Org-Knoten
  (Person/Projekt/…) → der Kern: *Ding reinbekommen → an Knoten hängen → Kontext*.
- **KI-Anreicherung** — Templates pro Kanal (Claude/OpenAI): Zusammenfassung + extrahierte Aktionen.
- **Handoff** — aus einem Item wird eine **Aufgabe** (planner) oder ein **Ticket** (helpdesk).
- **Abos** — `InboxSenderSubscription`: welche Absender/Kanäle überhaupt reinkommen.

## Was die Inbox NICHT ist

- **Keine UI/kein Ziel.** Der tägliche „Eingang" ist **home**. Im Modul selbst bleibt
  nur **Settings** (Abos/Regeln/Templates). Kein zweites „Inbox" neben „Home".
- **Kein Connector.** Externe Verbindungen/Sessions/Senden macht `user-connectors`.
- **Kein Antworten-Fokus.** Kommunizieren überlassen wir den Connectors/Modulen —
  die Inbox triagiert und macht daraus Org-Arbeit.

## Kontrakte (so docken andere an)

| Kontrakt | Zweck | Aufruf |
|---|---|---|
| **`Contracts\InboxDelivery`** / `Inbox::deliver([...])` | **Push:** interne Ereignisse (Ticket zugewiesen, Erwähnung, …) als System-Item liefern | `\Platform\Inbox\Inbox::deliver([...])` (class_exists-guarded) |
| **`Contracts\InboxItemLinkContract`** | typisierte Relationen zwischen Items (z. B. Whisper-Aufnahme *supplements* Meeting) | `resolve(InboxItemLinkContract::class)->…` |
| **`Organization\InboxEntityLinkProvider`** | Items als Zeit-Kontext an Org-Knoten (Meeting-Zeit → PersonNode) | Registrierung im ServiceProvider |
| **Enrichment-Provider** | KI-Anreicherung (OpenAI/Claude) | `EnrichmentProviderRegistry` |

## Wie Items reinkommen

1. **Connector-Ingestion** (`InboxIngestionService`, alle 5 Min): zieht aus den
   `user-connectors`-Session-Tabellen, dedupliziert über `(source_type, source_id)`,
   respektiert Abos, wendet Rules an, dispatcht Enrichment.
2. **Audio-Ingestion** (`InboxAudioIngestionService`): Whisper/Plaud → Item + Segmente.
3. **Push** (`InboxDelivery`): interne Modul-Ereignisse (Kanal `system`).

## Warum es so bleibt (und nicht in user-connectors wandert)

Die Inbox hängt an **planner/helpdesk** (Handoff) und liefert einen **`system`-Kanal
für interne Ereignisse ohne jeden Connector** — beides gehört nicht in einen
„externe-Connectoren"-Service. `user-connectors` bleibt dependency-leicht (Infra),
die Inbox bleibt das Feature dazwischen. Die *Settings-UI* kann man kanalweise
verheiraten (Verbindung aus user-connectors + Triage-Config aus inbox in **einem**
Bild), ohne die Schichten zu vermischen.
