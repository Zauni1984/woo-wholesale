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
- Produktliste zeigt eine Spalte „Großhandel“, Benutzerliste eine Spalte „Großhandel“, Bestellung zeigt die Rolle des Käufers.

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
