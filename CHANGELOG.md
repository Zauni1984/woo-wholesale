# Changelog

## 1.1.0 – 2026-10-02

Ab dieser Version gehören **zwei Plugins** zusammen, immer in derselben Version:
`woo-wholesale` (Lieferantenshop) und `woo-wholesale-partner` (Partnershop).

### Neu: Partner-Plugin „Woo Wholesale Partner“
- Eigenes Plugin für Partnershops. Es holt Kategorien, Produkte, Einkaufspreise und Bilder vom Lieferantenshop ab und berechnet daraus den eigenen Verkaufspreis.
- **Schutz der Lieferantendaten:** Der Lieferant legt je Partner fest, welche Felder gesperrt sind (Name, Beschreibung, Bilder, Kategorien, Artikelnummer, Attribute/Varianten, Löschen). Gesperrte Felder werden serverseitig zurückgeschrieben – im Produkt-Editor, bei Sammelbearbeitung und über die WooCommerce-REST-API. Löschen ist als Capability gesperrt, damit auch die Links verschwinden.
- **Aufschlag je Kategorie oder für alles**, nach oben oder unten, mit Fortschrittsbalken. Der Aufschlag bleibt gespeichert, spätere Syncs rechnen damit weiter. Grenzen gibt der Lieferant vor (Minimum 0 % verhindert Unterbieten).
- **Bild-Prüfer:** Ein Sync scheitert nie an einem Bild. Was fehlt, bleibt in einer Warteschlange pro Produkt, wird per Cron mit wachsendem Abstand nachgeholt (5 min → 30 min → 2 h → 6 h → 12 h → täglich) und kann im Backend manuell angestoßen werden. Der Prüfer vergleicht zusätzlich laufend Soll- und Ist-Bilder, findet also auch Bilder, die später gelöscht wurden.
- Bilder werden nur vom Lieferanten-Host geladen, nur wenn es wirklich Bilder sind, und mit Größenlimit.

### Neu im Lieferantenshop
- **Tab „Partnershops“:** Partner anlegen, Rolle zuordnen, Kategorien einschränken, Lager/Bestand mitsenden, Sperrfelder und Aufschlagsgrenzen festlegen.
- **Partner-API** (`wwpro/v1/partner/...`): Manifest, Kategorien, paginierte Produkte, Heartbeat. Authentifizierung per Bearer-Token; vom Schlüssel wird nur ein HMAC gespeichert, fehlgeschlagene Versuche sind pro IP limitiert.
- **Shopify-Anbindung:** Partner ohne WordPress werden direkt über die Shopify GraphQL Admin API beschrieben – Produkte, Varianten, Preise (inkl. Vergleichspreis) und Bilder. Der Zugriffstoken wird verschlüsselt gespeichert.
- **Tab „Preisänderung“:** Großhandelspreise einer Rolle prozentual nach oben oder unten, für alle Produkte oder eine einzelne Kategorie, mit Rundungsoptionen (0,05 / 0,10 / ganze Einheiten / ,99 / ,95) und Fortschrittsbalken.

### Build und Übersetzungen
- `bin/build-zip.sh` baut beide Plugins (`dist/woo-wholesale.zip`, `dist/woo-wholesale-partner.zip`), CI lädt zwei Artefakte hoch.
- Die deutschen Sprachdateien beider Plugins werden aus `bin/make-translations.php` erzeugt; CI prüft Abdeckung, Aktualität der generierten Dateien und dass beide Plugins dieselbe Versionsnummer tragen.

## 1.0.4 – 2026-09-10

- **Fix:** Seiten-Caches (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache) konnten einem eingeloggten Großhandelskunden den Gastpreis ausliefern – der richtige Preis erschien erst nach dem Leeren des Caches.
  - Seiten mit Großhandelspreisen werden nicht mehr im öffentlichen Seiten-Cache abgelegt (neue Einstellung *Caching → Seiten-Cache*, standardmäßig an).
  - LiteSpeed bekommt über den Filter `litespeed_vary` je Rolle einen eigenen Cache-Eintrag, damit eine bereits gecachte Gastseite nicht an eine Großhandelsrolle ausgeliefert wird.
  - Nach jeder Preis- oder Regeländerung wird zusätzlich der Seiten-Cache geleert (`wwpro_cache_version_bumped`).

## 1.0.3 – 2026-09-07

- Kompatibilität mit **Easy MCP AI** und anderen REST-/KI-Connectoren: Die Preis- und Rabattfelder jeder Rolle sind jetzt für die REST-API registriert (Produkt, Variante und Kategorie), inklusive schreibgeschützter Übersicht `wwpro_wholesale_prices` am Produkt.
- Zusätzlich vier Abilities (WordPress Abilities API) in der Kategorie „Großhandelspreise“: Rollen auflisten, Preise eines Produkts lesen, Preis/Rabatt setzen, Kategorierabatt setzen. Erscheinen bei Easy MCP AI als eigene Tools.
- Schreibzugriffe erfordern die Berechtigung „WooCommerce verwalten“, werden serverseitig validiert und leeren die Preis-Caches automatisch – auch wenn sie nicht über das Backend kommen.

## 1.0.2 – 2026-09-07

- **Fix:** Im Produkt-Editor überlagerten sich die Rollenblöcke – die Staffel-Checkbox rutschte in die nächste Rolle. Die Felder nutzen jetzt das Standard-Markup von WooCommerce und räumen die Floats sauber ab; die doppelte Beschriftung „Staffelrabatte“ ist weg.

## 1.0.1 – 2026-09-07

- **Fix:** Neu angelegte und importierte Rollen hatten intern „Felder im Produkt-Editor anzeigen“ und „Staffeltabelle anzeigen“ auf *nein*, dadurch fehlten im Produkt die Preisfelder. Bestehende Installationen werden beim Update automatisch repariert.
- Produktliste: je Großhandelsrolle eine eigene Preisspalte, ein-/ausblendbar über die Ansichtsoptionen. Zeigt den tatsächlich gültigen Preis inklusive Herkunft (Produkt, Kategorie, shopweit).
- Build/CI: Das Artefakt enthält jetzt den Plugin-Ordner statt eines ZIPs im ZIP, der Download ist damit direkt installierbar.

## 1.0.0 – 2026-09-06

- Erste Version: Großhandelsrollen, Produkt-/Varianten-/Kategorie-/Shop-Preise, Staffelrabatte, Zweitpreis (netto/brutto), Steueranzeige je Rolle, Mindestbestellwert, Gutscheinsperre, Importer für WooCommerce Wholesale Prices, deutsche Übersetzung.
