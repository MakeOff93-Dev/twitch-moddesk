# Twitch ModDesk – Changelog

## 1.4.0 – Module, GitHub-Updates und Mehrserver-Discord

- Datenbankmigrationen können Owner direkt im Panel prüfen und ausführen.
- Der aktive Twitch-Kanal lässt sich oben rechts über ein Dropdown wechseln.
- Neues News- und Ankündigungsmodul mit Entwürfen, angehefteten Beiträgen und Discord-Ereignis.
- Twitch, BanSync, Discord, News, Inhalte, Team, Design und Audit sind einzeln aktivierbare Panel-Module.
- Owner können vertrauenswürdige Zusatzmodule mit `module.json` als geprüftes ZIP installieren, konfigurieren, aktualisieren und deaktivieren.
- Discord unterstützt mehrere Server und beliebig viele Channels je Server sowie mehrere Ziel-Channels pro Ereignis.
- Text-, Ankündigungs- und Thread-Channels können über den Bot direkt vom Discord-Server übernommen werden.
- URL-Rewrites sind in den Einstellungen zwischen klassischen Query-URLs und sauberen Pfaden umschaltbar.
- GitHub Releases können automatisch geprüft und als Update-Hinweis im ModDesk angezeigt werden.
- Ein neuer Release lässt sich mit einem Klick herunterladen, prüfen, sichern, migrieren und installieren; `.env`, MySQL-Daten, Branding und Zusatzmodule bleiben erhalten.
- Ein Klick auf die Versionsanzeige öffnet die Änderungen der installierten und einer verfügbaren Version.
- Changelogs können aus dem Versionsdialog direkt in einen verwalteten Discord-Channel gepostet werden.

## 1.3.0 – Design und Discord Studio

- Design-Editor für Logo, Farben, Header, Footer, Navigation und Seiteninhalte.
- Discord Studio mit Embed-Vorschau, Icons, Bildern, Feldern, Footer und Vorlagen.
- Direkter Einstieg im Projektstamm ohne sichtbares `/public`.
- Owner-geschützter ZIP-Update-Importer mit Dateisicherung und automatischen Migrationen.

## 1.2.0 – Integrationen

- Discord-Bot und ereignisabhängige Channel-Routen.
- SMTP-Konfiguration und Testversand.
- Twitch BanSync über mehrere moderierte Kanäle mit kanalweisem Banlog.
