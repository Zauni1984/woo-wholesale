# Woo Wholesale Pro

Rollenbasierte Großhandelspreise für WooCommerce. Ein eigenständiges Premium-Plugin ohne Lizenz-Server, ohne Fremdabhängigkeiten.

**Kurzfassung:** Du legst Großhandelsrollen an (z. B. „B2B Kunde“, „Anbauverein“), weist sie Benutzern zu und pflegst Preise je Produkt, je Kategorie oder shopweit. Jede Rolle sieht ausschließlich ihren Preis. Gäste sehen den Standardpreis. Vorhandene Daten aus **WooCommerce Wholesale Prices** (Wholesale Suite) werden per Knopfdruck übernommen.

---

## Funktionen

### Rollen
- Beliebig viele Großhandelsrollen. Jede Rolle ist eine echte WordPress-Benutzerrolle (nur `read`-Berechtigung) und wird wie gewohnt unter *Benutzer → Bearbeiten* zugewiesen.
- Pro Rolle: shopweiter Rabatt, Zweitpreis (Netto oder Brutto daneben), Steueranzeige (inkl./exkl.), Mindestbestellwert, Gutscheine sperren, Sichtbarkeit der Felder im Produkt-Editor, Staffeltabelle ein/aus.
- Bestehende Rollen (z. B. `wholesale_customer` von Wholesale Suite) können mit demselben Schlüssel übernommen werden, die Benutzer behalten ihre Zuordnung.

### Preise
Reihenfolge der Auswertung, die erste Regel gewinnt:

1. Festpreis an der Variante bzw. am Produkt
2. Prozentrabatt an der Variante bzw. am Produkt
3. Festpreis oder Prozentrabatt am übergeordneten variablen Produkt (Fallback für Varianten)
4. Prozentrabatt der Produktkategorie (Unterkategorien erben)
5. Shopweiter Rabatt der Rolle

Einstellbar: Rabattbasis (regulärer oder aktueller Preis), Verhalten bei mehreren Kategorien (höchster/niedrigster Rabatt), Preisdeckel („nie teurer als der normale Kunde“).

### Staffelrabatte (aktivierbar)
- Je Rolle am Produkt oder an der Kategorie: *ab Menge X → Y % Rabatt* oder *fester Stückpreis*.
- Staffeln am Produkt ersetzen die Kategorie-Staffeln. Der Prozentsatz wird vom Großhandels-Stückpreis der Rolle abgezogen.
- Mengenbasis wahlweise je Warenkorbzeile oder summiert über alle Varianten eines Produkts.
- Staffeltabelle auf der Produktseite (auch per Shortcode `[wwpro_tiers]`).

### Darstellung
- Großhandelskunden sehen **genau einen Preis**: kein durchgestrichener UVP, kein „Angebot!“-Badge.
- B2B-Kunden können zusätzlich den **Nettopreis daneben** sehen (Shop, Warenkorb, Kasse). Beschriftung frei einstellbar.
- Greift überall, wo WooCommerce Preise abfragt: Shop, Produkt, Varianten, Warenkorb, Kasse, Blocks, Store API, Mini-Cart.

### Produkt-Editor
- Für jede freigeschaltete Rolle erscheinen im Reiter „Allgemein“ zwei Felder (Festpreis, Rabatt %) plus die Staffel-Tabelle.
- Varianten haben eigene Preis-/Rabattfelder; Massenaktionen („Großhandelspreis setzen“, „Rabatt setzen“, „Löschen“) im Varianten-Panel.
- **Produktliste:** je Großhandelsrolle eine eigene Preisspalte (z. B. „Großhandel: B2B Kunde“). Ein- und ausblendbar über *Ansichtsoptionen* oben rechts. Angezeigt wird der Preis, den die Rolle tatsächlich zahlt – auch wenn er aus einem Kategorie- oder shopweiten Rabatt stammt – mit Hinweis auf die Herkunft. Benutzerliste zeigt eine Spalte „Großhandel“, die Bestellung die Rolle des Käufers.

### Import aus WooCommerce Wholesale Prices
- Erkennt automatisch alle Rollen anhand der Meta-Keys `*_wholesale_price` in der Datenbank (auch Premium-Custom-Rollen).
- Importiert Produkt-/Variantenpreise, Produkt-Prozentrabatte (Premium, best effort), Kategorierabatte (Premium) und shopweite Rabatte (Premium).
- Zuordnung je Rolle: gleicher Schlüssel übernehmen, in vorhandene Rolle importieren (optional inkl. Umzug der Benutzer) oder überspringen.
- Läuft in Batches per AJAX mit Fortschrittsbalken, daher auch bei tausenden Produkten ohne Timeout. Originaldaten bleiben unangetastet.
- Nicht importiert: Mindestbestellmengen je Produkt und Sichtbarkeitsregeln (Funktionen gibt es hier nicht).

### Sicherheit
- Alle Admin-Aktionen: Berechtigung `manage_woocommerce` + Nonce; AJAX-Import mit `check_ajax_referer`.
- Produkt-/Kategorie-Speicherung hängt an den WooCommerce-/WordPress-Hooks, die erst nach deren Nonce- und Rechteprüfung feuern; zusätzlich eigene `current_user_can`-Prüfungen.
- Alle Eingaben werden sanitisiert (Schlüssel per Regex, Zahlen per `wc_format_decimal`, Prozent 0–100), alle Ausgaben escaped, alle SQL-Abfragen prepared.
- Rollenschlüssel von WordPress/WooCommerce (`administrator`, `shop_manager`, …) sind gesperrt.
- Preise werden ausschließlich serverseitig für den angemeldeten Benutzer berechnet. Gäste erhalten nie Großhandelsdaten.
- Deinstallation entfernt Daten nur, wenn das in den Einstellungen aktiviert wurde; Benutzer werden vorher in die Rolle „Kunde“ verschoben.

---

## Installation

1. Zip bauen (`bin/build-zip.sh` erzeugt `dist/woo-wholesale.zip`) oder das Artefakt „woo-wholesale“ aus der GitHub-Action „CI“ herunterladen – das ist bereits die installierbare Zip-Datei.
2. Unter *Plugins → Installieren → Plugin hochladen* hochladen und aktivieren.
3. *WooCommerce → Großhandel*: Rollen prüfen (zwei Beispielrollen werden angelegt), Einstellungen setzen.
4. Falls Wholesale Suite im Einsatz ist: Tab **Import** öffnen, Zuordnung wählen, „Import starten“. Danach Wholesale Suite deaktivieren.
5. Benutzern die Rolle zuweisen und im Frontend testen (als Kunde eingeloggt).

Voraussetzungen: WordPress 6.2+, WooCommerce 7.0+, PHP 7.4+. HPOS und Block-Checkout werden unterstützt.

## Hinweise

- **Caching:** Großhandelspreise gelten nur für eingeloggte Nutzer. WP Rocket und LiteSpeed Cache liefern eingeloggten Nutzern standardmäßig keine gecachten Seiten aus. „Eingeloggte Nutzer cachen“ nicht ohne Cache-Variation nach Rolle aktivieren.
- **Preisfilter/Sortierung im Shop** nutzen die WooCommerce-Lookup-Tabelle mit Standardpreisen. Das ist bei allen Rollenpreis-Plugins so.
- **Germanized:** Grundpreise (`_unit_price`) werden von Germanized in aktuellen Versionen aus dem angezeigten Preis neu berechnet. Bitte im Frontend einmal prüfen.

## KI-Assistenten (Easy MCP AI, MCP, REST)

Die Großhandelspreise sind für die REST-API registriert, damit Connectoren wie **Easy MCP AI** sie lesen und befüllen können. Ohne diese Registrierung sind `_wwpro_*`-Meta-Felder für die REST-API unsichtbar, weil WordPress Meta mit führendem Unterstrich schützt.

**Weg 1 – Produkt-Meta (funktioniert mit den generischen Tools wie `wp_get_post_meta`, `wp_update_post_meta`, `wp_wc_update_product`):**

| Objekt | Feld | Bedeutung |
| --- | --- | --- |
| Produkt, Variante | `_wwpro_price_{rolle}` | Festpreis der Rolle, leer = keiner |
| Produkt, Variante | `_wwpro_discount_{rolle}` | Rabatt in Prozent (0–100) |
| Produktkategorie | `_wwpro_discount_{rolle}` | Kategorierabatt in Prozent |

Zusätzlich liefert die Produktantwort das schreibgeschützte Feld `wwpro_wholesale_prices` mit dem tatsächlich gültigen Preis je Rolle, seiner Herkunft (Produkt, Kategorie, shopweit) und den am Produkt gespeicherten Rohwerten. Damit kann ein Assistent prüfen, was am Ende wirklich greift.

**Weg 2 – Abilities (WordPress Abilities API, erscheinen bei Easy MCP AI als eigene Tools):**

| Ability | Zweck |
| --- | --- |
| `woo-wholesale/list-roles` | Rollen samt Schlüssel und shopweitem Rabatt auflisten |
| `woo-wholesale/get-product-prices` | Preise eines Produkts je Rolle lesen (per ID oder SKU) |
| `woo-wholesale/set-product-price` | Festpreis und/oder Rabatt einer Rolle setzen |
| `woo-wholesale/set-category-discount` | Kategorierabatt einer Rolle setzen |

Ohne Abilities-API passiert nichts – die Registrierung ist abgesichert, Weg 1 funktioniert unabhängig davon.

**Absicherung:** Lesen und Schreiben erfordert einen angemeldeten Benutzer mit `manage_woocommerce` (bzw. Bearbeitungsrecht am Produkt). Alle Werte werden serverseitig geprüft: Preise ≤ 0 und Prozentsätze außerhalb 0–100 werden verworfen, Rollenschlüssel müssen existieren. Nach jedem Schreibvorgang werden die Preis-Caches automatisch geleert, auch wenn die Änderung nicht aus dem Backend kam.

## Entwickler-Hooks

| Hook | Zweck |
| --- | --- |
| `wwpro_user_wholesale_role` | Großhandelsrolle eines Benutzers überschreiben |
| `wwpro_current_role` | aktive Rolle im Request überschreiben |
| `wwpro_resolve_price` | ermitteltes Preis-Ergebnis anpassen |
| `wwpro_tier_sets`, `wwpro_tier_price`, `wwpro_tier_quantity` | Staffelrabatte |
| `wwpro_secondary_price_html` | Markup des Zweitpreises |
| `wwpro_round_price` | Rundung |
| `wwpro_role_saved`, `wwpro_role_deleted`, `wwpro_loaded` | Aktionen |

Datenablage: Produktmeta `_wwpro_price_{rolle}`, `_wwpro_discount_{rolle}`, `_wwpro_tiers_{rolle}`; Kategorie-Termmeta gleichnamig; Optionen `wwpro_roles`, `wwpro_settings`; Bestellmeta `_wwpro_role`.

## Lizenz

MIT – siehe `LICENSE`.
