# Woo Wholesale Pro

Rollenbasierte Großhandelspreise für WooCommerce. Ein eigenständiges Premium-Plugin ohne Lizenz-Server, ohne Fremdabhängigkeiten.

**Kurzfassung:** Du legst Großhandelsrollen an (z. B. „B2B Kunde“, „Anbauverein“), weist sie Benutzern zu und pflegst Preise je Produkt, je Kategorie oder shopweit. Jede Rolle sieht ausschließlich ihren Preis. Gäste sehen den Standardpreis. Vorhandene Daten aus **WooCommerce Wholesale Prices** (Wholesale Suite) werden per Knopfdruck übernommen.

> **Zwei Plugins, eine Version.** Seit 1.1.0 besteht das Projekt aus dem Lieferanten-Plugin `woo-wholesale` (dieser Ordner) und dem Partner-Plugin `woo-wholesale-partner` (Ordner `partner-plugin/`). Der Lieferantenshop pflegt Produkte und Preise, Partnershops holen sie ab. Beide Plugins tragen immer dieselbe Versionsnummer und werden gemeinsam aktualisiert – die CI bricht ab, wenn die Nummern auseinanderlaufen.

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

1. Zips bauen (`bin/build-zip.sh` erzeugt `dist/woo-wholesale.zip` **und** `dist/woo-wholesale-partner.zip`) oder die Artefakte „woo-wholesale“ bzw. „woo-wholesale-partner“ aus der GitHub-Action „CI“ herunterladen – das sind bereits die installierbaren Zip-Dateien.
2. Unter *Plugins → Installieren → Plugin hochladen* hochladen und aktivieren.
3. *WooCommerce → Großhandel*: Rollen prüfen (zwei Beispielrollen werden angelegt), Einstellungen setzen.
4. Falls Wholesale Suite im Einsatz ist: Tab **Import** öffnen, Zuordnung wählen, „Import starten“. Danach Wholesale Suite deaktivieren.
5. Benutzern die Rolle zuweisen und im Frontend testen (als Kunde eingeloggt).

Voraussetzungen: WordPress 6.2+, WooCommerce 7.0+, PHP 7.4+. HPOS und Block-Checkout werden unterstützt.

## Partnershops

Ein Partnershop verkauft deine Produkte weiter. Er erhält die Preise **genau einer** Großhandelsrolle, und du bestimmst, was er bei sich nicht verändern darf.

Anlegen unter *WooCommerce → Großhandel → Partnershops*. Je Partner legst du fest:

| Einstellung | Wirkung |
| --- | --- |
| Shopsystem | `WooCommerce` (Partner holt ab) oder `Shopify` (du überträgst hin) |
| Preise dieser Rolle | welche Großhandelsrolle der Partner zahlt – Kategorie- und shopweite Rabatte der Rolle sind enthalten |
| Produktauswahl | optional auf Kategorien beschränken (Unterkategorien immer inklusive), nur Lagerware, Bestände mitsenden |
| Der Partner darf nicht ändern | Name, Beschreibung, Bilder, Kategoriezuordnung, Artikelnummer, Attribute/Varianten, Löschen |
| Preisaufschlag des Partners | optionaler **Höchstaufschlag** (wird erzwungen) und eine optionale **Empfehlung** (füllt im Partnershop nur das Feld vor) |

### Keine Mindestpreise – und warum

Einen Mindest- oder Festpreis für den Partner gibt es in diesem Plugin bewusst **nicht**, und das ist keine Nachlässigkeit: Ein Partnershop ist ein selbstständiger Händler. Ihm einen Mindestpreis vorzuschreiben ist **Preisbindung der zweiten Hand** und nach Art. 101 AEUV und § 1 GWB unzulässig; die Vertikal-GVO (VO 2022/720) führt sie in Art. 4 lit. a als Kernbeschränkung auf. Dasselbe gilt für „der Partner darf gar keinen eigenen Aufschlag setzen“ – das ist ein Festpreis.

Zulässig und deshalb umgesetzt:

- ein **Höchstaufschlag** (= Höchstpreis), optional, standardmäßig keine Obergrenze,
- eine **unverbindliche Preisempfehlung**, die im Partnershop nur ein leeres Feld vorbelegt.

Der Weg nach unten bleibt im Partnershop immer offen, bis unter den Einkaufspreis. Die einzige Untergrenze ist technisch (`WWPart_Settings::MIN_MARKUP`, −90 %), damit ein Preis nicht auf null fällt. Ein negativer Höchstaufschlag wird beim Speichern verworfen und greift nie, denn er würde einen Verkauf unter dem Lieferantenpreis erzwingen.

Das beschreibt die Bauweise des Plugins und ist keine Rechtsberatung – deine Verträge prüfst du bitte mit deinem Anwalt.

### WooCommerce-Partner

1. Im Partnershop `woo-wholesale-partner` installieren und aktivieren.
2. Dort unter *WooCommerce → Lieferant* die Adresse deines Shops und den Partnerschlüssel eintragen.
3. „Verbindung testen“, Aufschlag setzen, ersten Sync starten.

Der Schlüssel wird hier **nur als HMAC** gespeichert – er lässt sich jederzeit neu ausgeben, aber nie wieder anzeigen. Die Partner-API ist lesend (plus Heartbeat); ein Partner kann in deinem Shop nichts schreiben. Fehlgeschlagene Anmeldeversuche sind pro IP begrenzt.

Routen unter `wwpro/v1/partner/`, Authentifizierung per `Authorization: Bearer <Schlüssel>`:

| Route | Zweck |
| --- | --- |
| `GET manifest` | Verbindungstest, Policy, Anzahl Produkte und Kategorien |
| `GET categories` | Kategoriebaum (Eltern zuerst) |
| `GET products` | paginierte Produkt-Payloads inkl. Preis der Rolle, Bild-URLs, Varianten, Checksumme |
| `POST heartbeat` | Partner meldet Produktzahl, fehlende Bilder und Fehler zurück |

### Shopify-Partner

Shopify-Shops können kein WordPress-Plugin installieren, deshalb überträgst du dorthin. Nötig ist eine eigene App im Shopify-Adminbereich mit `write_products` und `read_products`; das Admin-API-Token wird verschlüsselt gespeichert (AES-256-CBC mit einem aus den Site-Salts abgeleiteten Schlüssel) und im Backend nur als Fragment angezeigt.

Übertragen werden Titel, Beschreibung, Tags, Varianten mit Artikelnummer und Preis (der Verkaufspreis deiner Rolle; dein normaler Shoppreis landet als Vergleichspreis) sowie Bilder. Bilder gibt Shopify sich selbst per URL ab – sie müssen also öffentlich erreichbar sein. Die Zuordnung Produkt ↔ Shopify-ID wird am Produkt gespeichert, ein zweiter Lauf aktualisiert also statt zu duplizieren; fehlt die Zuordnung, wird über die Artikelnummer gesucht. Verwendet wird die GraphQL Admin API, weil Shopify die REST-Produktendpunkte abgekündigt hat.

---

## Preise prozentual ändern

*WooCommerce → Großhandel → Preisänderung* verschiebt die Großhandelspreise einer Rolle nach oben oder unten – genau der Schritt nach dem ersten Import, wenn auf die eingespielten Preise noch eine Marge drauf oder ein Rabatt runter soll.

- **Umfang:** alle Produkte oder eine einzelne Kategorie (Unterkategorien optional).
- **Basis:** der aktuelle Großhandelspreis der Rolle (Produkte ohne werden übersprungen) oder der normale Shoppreis.
- **Rundung:** Shop-Dezimalstellen, 0,05, 0,10, ganze Einheiten, `,99` oder `,95` (die Charm-Rundung springt auf den nächstgelegenen Wert, nach oben oder unten).
- Läuft in Stapeln von 25 Produkten per AJAX, mit Fortschrittsbalken und Protokoll.

Das Ergebnis wird als **Festpreis** der Rolle gespeichert; ein prozentualer Rabatt derselben Rolle wird bei diesen Produkten entfernt, damit genau eine Regel den Preis beschreibt. Es gibt kein Undo – vor großen Läufen ein Datenbank-Backup anlegen.

---

## Hinweise

- **Caching:** Großhandelspreise werden serverseitig gerendert, ein Seiten-Cache würde also eine Kopie an alle ausliefern. Das Plugin hält Seiten mit Großhandelspreisen deshalb aus dem öffentlichen Seiten-Cache heraus (*WooCommerce → Großhandel → Einstellungen → Caching*), gibt LiteSpeed über `litespeed_vary` je Rolle einen eigenen Cache-Eintrag und leert den Seiten-Cache nach jeder Preisänderung. Details unter *LiteSpeed Cache* weiter unten.
- **Preisfilter/Sortierung im Shop** nutzen die WooCommerce-Lookup-Tabelle mit Standardpreisen. Das ist bei allen Rollenpreis-Plugins so.
- **Germanized:** Grundpreise (`_unit_price`) werden von Germanized in aktuellen Versionen aus dem angezeigten Preis neu berechnet. Bitte im Frontend einmal prüfen.

### LiteSpeed Cache

Symptom: Man ruft ein Produkt als Gast auf, meldet sich danach als B2B-Kunde an – und sieht weiter den Endkundenpreis. Erst das Leeren des Caches bringt den richtigen Preis. Ursache: LiteSpeed beantwortet die Anfrage auf Server-Ebene aus dem Cache, PHP läuft dabei gar nicht mehr. Welche Kopie ausgeliefert wird, entscheidet allein das Cookie `_lscache_vary`.

Was das Plugin dagegen tut:

- **`litespeed_vary`:** Die aktive Großhandelsrolle wird Teil der Vary. Jede Rolle bekommt damit ihren eigenen Cache-Eintrag, und eine als Gast gecachte Seite wird nicht mehr an einen B2B-Kunden ausgeliefert. Für Gast-Aufrufe wird nichts hinzugefügt – die öffentliche Kopie bleibt cachebar.
- **`DONOTCACHEPAGE` + `litespeed_control_set_nocache`:** Solange eine Großhandelsrolle aktiv ist, wird die Seite gar nicht erst abgelegt (abschaltbar unter *Einstellungen → Caching*).
- **Purge:** Preis-, Rabatt- oder Regeländerungen lösen `litespeed_purge_all` aus (ebenso WP Rocket, W3 Total Cache, WP Super Cache).

Zusätzlich in LiteSpeed prüfen:

| Einstellung | Empfehlung |
| --- | --- |
| Cache → *Eingeloggte Benutzer cachen* | Aus, solange nicht sicher ist, dass die Vary greift |
| Cache → *Guest Mode* / *Guest Optimization* | Aus – liefert unbekannten Besuchern eine vorgenerierte Gastseite |
| Cache → *Rolle nicht cachen* | Großhandelsrollen eintragen, wenn du ganz sichergehen willst |
| Nach der Umstellung | Einmal *Alles leeren* |

Ob die Vary greift, sieht man im Browser am Cookie `_lscache_vary`: Es muss sich unterscheiden, je nachdem ob man als Gast oder als Großhandelskunde eingeloggt ist.

## KI-Assistenten (Easy MCP AI, MCP, REST)

Claude kann die Großhandelspreise über einen MCP-Connector (z. B. **Easy MCP AI**) lesen und befüllen. Möglich wird das, weil das Plugin seine Meta-Felder ausdrücklich für die REST-API registriert: WordPress schützt Meta mit führendem Unterstrich (`_wwpro_*`) und blendet sie sonst komplett aus.

### Voraussetzungen

- Woo Wholesale Pro und WooCommerce sind aktiv.
- Der MCP-Connector arbeitet als Benutzer mit `manage_woocommerce` (Administrator oder Shop-Manager). Ohne dieses Recht sind die Felder weder les- noch schreibbar.
- Für Weg 2 zusätzlich: ein Plugin mit WordPress-Abilities-API (Easy MCP AI bringt sie mit). Fehlt sie, passiert nichts – Weg 1 funktioniert unabhängig davon.

### Weg 1 – Meta-Felder über die generischen WordPress-Tools

Nutzbar mit den Standard-Tools des Connectors, z. B. `wp_get_post_meta`, `wp_update_post_meta`, `wp_get_term_meta`, `wp_update_term_meta`, `wp_wc_get_product`, `wp_wc_update_product`.

`{rolle}` ist immer der Rollenschlüssel aus der Option `wwpro_roles`, also z. B. `_wwpro_price_b2b_customer` oder `_wwpro_discount_anbauverein`.

| Objekt | Feld | Typ | Erlaubte Werte |
| --- | --- | --- | --- |
| Produkt, Variante | `_wwpro_price_{rolle}` | String | Dezimalzahl > 0 (Punkt als Trennzeichen) oder `''` = kein Festpreis |
| Produkt, Variante | `_wwpro_discount_{rolle}` | String | `0`–`100` (Prozent) oder `''` = kein Rabatt |
| Produktkategorie (`product_cat`) | `_wwpro_discount_{rolle}` | String | `0`–`100` (Prozent) oder `''` = kein Rabatt |

Serverseitige Prüfung beim Schreiben: Komma wird zu Punkt normalisiert, Preise ≤ 0 und leere/ungültige Werte werden zu `''`, Prozentsätze werden auf 0–100 begrenzt. Ein Festpreis hat immer Vorrang vor dem Prozentrabatt derselben Rolle.

**Nicht über REST erreichbar:** Staffelrabatte (`_wwpro_tiers_{rolle}`) sind bewusst nicht als REST-Meta registriert, weil sie eine verschachtelte Struktur haben. Sie werden im Produkt-Editor bzw. per Import gepflegt:

```
_wwpro_tiers_{rolle} = array(
    'enabled' => 'yes' | 'no',
    'rows'    => array(
        array( 'qty' => 10, 'discount' => '5',  'price' => ''     ),
        array( 'qty' => 50, 'discount' => '',   'price' => '8.90' ),
    ),
)
```

### Kontrollfeld `wwpro_wholesale_prices` (nur lesen)

Jede Produkt- und Variantenantwort der REST-API enthält zusätzlich ein Objekt mit dem tatsächlich gültigen Preis je Rolle. Damit lässt sich prüfen, was am Ende wirklich greift – auch wenn der Preis aus einer Kategorie oder dem shopweiten Rabatt kommt. Schlüssel des Objekts ist der Rollenschlüssel:

| Feld | Bedeutung |
| --- | --- |
| `role` | Rollenschlüssel |
| `role_name` | Anzeigename der Rolle |
| `price` | gültiger Großhandelspreis, formatiert; `''` wenn die Rolle keinen bekommt |
| `source` | Herkunft: `product`, `variation`, `parent`, `category`, `global` oder `none` |
| `own_price` | am Produkt gespeicherter Festpreis (Rohwert) |
| `own_discount` | am Produkt gespeicherter Rabatt in Prozent (Rohwert) |
| `regular_price` | regulärer Preis des Produkts |
| `tiers_enabled` | `true`, wenn für diese Rolle aktive Staffeln hinterlegt sind |

### Weg 2 – Abilities (erscheinen als eigene MCP-Tools)

Bei Easy MCP AI tauchen sie als `wp_ability_woo-wholesale_*` auf. Alle vier prüfen `manage_woocommerce`.

| Ability | Eingabe | Ausgabe |
| --- | --- | --- |
| `woo-wholesale/list-roles` | – | `roles[]` mit `role`, `name`, `description`, `global_discount`, `secondary_price`, `users` |
| `woo-wholesale/get-product-prices` | `product_id` **oder** `sku` | `product_id`, `name`, `prices` (Felder wie oben) |
| `woo-wholesale/set-product-price` | `product_id` oder `sku`, `role` (Pflicht), `price` und/oder `discount` | `product_id`, `role`, `prices` nach dem Schreiben |
| `woo-wholesale/set-category-discount` | `category_id` oder `slug`, `role` (Pflicht), `discount` (Pflicht) | `category_id`, `role`, `discount` |

`price` und `discount` akzeptieren Zahl oder String; ein leerer String löscht den Wert. Unbekannte Rollenschlüssel, fehlende Produkte und fehlende Kategorien werden mit einer klaren Fehlermeldung abgelehnt.

### Rollenfelder (Option `wwpro_roles`)

Die Rollenkonfiguration selbst wird nicht über MCP geschrieben, ist für das Verständnis der Werte aber relevant:

| Feld | Bedeutung |
| --- | --- |
| `key` | Rollenschlüssel, Teil aller Meta-Feldnamen |
| `name`, `description` | Anzeigename und Beschreibung |
| `global_discount` | shopweiter Rabatt in Prozent (`''` = keiner) |
| `tax_display` | Steueranzeige: `''` (Shop-Standard), `incl`, `excl` |
| `secondary_price` | Zweitpreis im Frontend: `none`, `net`, `gross` |
| `show_in_product` | Preisfelder dieser Rolle im Produkt-Editor anzeigen |
| `disable_coupons` | Gutscheine für diese Rolle sperren |
| `min_order_amount` | Mindestbestellwert (`''` = keiner) |
| `show_tiers` | Staffeltabelle auf der Produktseite anzeigen |

### Typischer Ablauf

1. `woo-wholesale/list-roles` aufrufen (oder die Option `wwpro_roles` lesen), um die Rollenschlüssel zu bekommen.
2. Produkte über die WooCommerce-Tools suchen (`wp_wc_list_products`, Suche per SKU).
3. Preis setzen – entweder `woo-wholesale/set-product-price` oder `_wwpro_price_{rolle}` per Meta-Update.
4. Ergebnis über `wwpro_wholesale_prices` bzw. `woo-wholesale/get-product-prices` gegenprüfen, insbesondere `source`.

**Absicherung:** Lesen und Schreiben erfordert einen angemeldeten Benutzer mit `manage_woocommerce` (bzw. Bearbeitungsrecht am Produkt; bei Varianten zählt das Recht am Elternprodukt). Alle Werte werden serverseitig geprüft: Preise ≤ 0 und Prozentsätze außerhalb 0–100 werden verworfen, Rollenschlüssel müssen existieren. Nach jedem Schreibvorgang werden die Preis-Caches automatisch geleert, auch wenn die Änderung nicht aus dem Backend kam – gesammelt am Ende des Requests, damit Massenimporte günstig bleiben.

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
| `wwpro_cache_version_bumped` | Preis-Caches wurden geleert – Anschluss für eigene Cache-Purges |
| `wwpro_partner_query_args`, `wwpro_partner_product_payload` | was ein Partnershop bekommt |
| `wwpro_partner_saved`, `wwpro_partner_deleted` | Partner angelegt/geändert bzw. gelöscht |
| `wwpro_bulk_adjusted_price` | einzelner Preis aus der Sammel-Preisänderung |

Datenablage: Produktmeta `_wwpro_price_{rolle}`, `_wwpro_discount_{rolle}`, `_wwpro_tiers_{rolle}`, `_wwpro_shopify_{partner}`; Kategorie-Termmeta gleichnamig; Optionen `wwpro_roles`, `wwpro_settings`, `wwpro_partners`; Bestellmeta `_wwpro_role`.

---

## Partner-Plugin (`partner-plugin/`)

Eigenes Plugin für den Partnershop, Textdomain `woo-wholesale-partner`, Präfix `wwpart_`. Backend unter *WooCommerce → Lieferant* mit den Tabs *Lieferant*, *Sync*, *Preise*, *Bilder*, *Hilfe*.

### Was ein Sync macht

`connect` → `categories` → `products` → `images` → `cleanup` → `report`, in Stapeln per AJAX mit Fortschrittsbalken.

- Produkte werden über die Lieferanten-ID zugeordnet; beim ersten Lauf wird ein bereits vorhandener Artikel über die Artikelnummer übernommen statt doppelt angelegt.
- Unveränderte Produkte erkennt die Checksumme der Payload und werden übersprungen – der Verkaufspreis wird trotzdem nachgerechnet, falls sich der Aufschlag geändert hat.
- Attribute kommen als Produkt-Attribute an, nicht als globale Attribut-Taxonomien. Varianten werden angelegt, aktualisiert und entfernt, wenn der Lieferant sie nicht mehr liefert.
- Neue Produkte entstehen standardmäßig als **Entwurf**. Nicht mehr gelieferte Produkte werden auf Entwurf gesetzt, in den Papierkorb gelegt oder unberührt gelassen.
- Eigene Produkte des Partners bleiben immer unberührt.

### Preis des Partners

`Verkaufspreis = Einkaufspreis × (1 + Aufschlag ÷ 100)`, danach gerundet. Der Aufschlag der **speziellsten** Kategorie gewinnt, sonst gilt der Standardaufschlag. Erzwungen wird nur ein Höchstaufschlag des Lieferanten, falls er einen gesetzt hat; nach unten ist der Partner frei. Ein Angebotspreis unter dem berechneten Preis bleibt erhalten, ein höherer wird entfernt.

Unter *Preise* setzt der Partner den Aufschlag für alle Produkte oder eine Kategorie, nach oben oder unten, und wendet ihn mit Fortschrittsbalken an. Der Wert bleibt gespeichert, spätere Syncs rechnen damit weiter.

### Schutz der Lieferantendaten

Gesperrte Felder werden **serverseitig** zurückgeschrieben, nicht nur im Browser ausgegraut:

- `wp_insert_post_data` für Titel, Beschreibung und Kurzbeschreibung,
- `woocommerce_admin_process_product_object` und `woocommerce_rest_pre_insert_product_object` für Artikelnummer, Bilder und Attribute,
- `save_post_product` für die Kategoriezuordnung,
- `map_meta_cap` plus `pre_trash_post`/`pre_delete_post` gegen Löschen – damit verschwinden auch die Links in der Produktliste.

Quelle der Wahrheit ist die letzte Payload des Lieferanten (`_wwpart_payload`). Während des Syncs ist die Sperre ausgesetzt, damit sie nicht die eigenen Schreibvorgänge zurückdreht.

### Bild-Prüfer

Bilder sind der Teil, der am häufigsten schiefgeht – deshalb hängt kein Sync davon ab:

- Fehlende Bilder stehen in einer Warteschlange am Produkt (`_wwpart_image_queue`), erfolgreich geladene in einer Hash-Zuordnung (`_wwpart_image_map`).
- Der Cron-Lauf `wwpart_image_check` (stündlich) holt Offenes nach, mit wachsendem Abstand pro Versuch: 5 min → 30 min → 2 h → 6 h → 12 h → täglich, nach 8 Versuchen gilt ein Bild als fehlgeschlagen.
- Derselbe Lauf prüft rollierend die bereits vorhandenen Produkte: Fehlt ein Bild, das da sein müsste – weil es nie ankam oder später gelöscht wurde –, wird es erneut eingereiht.
- Unter *Bilder* lässt sich das manuell starten; „Alles erneut versuchen“ nimmt auch die aufgegebenen Bilder mit und ignoriert die Wartezeit.
- Heruntergeladen wird nur vom Host des Lieferanten (Filter `wwpart_allowed_image_hosts` für ein CDN), nur über HTTP/HTTPS, nur echte Bilder (`wp_check_filetype_and_ext`) und maximal 12 MB.

Datenablage: Produktmeta `_wwpart_master_id`, `_wwpart_base_price`, `_wwpart_retail_price`, `_wwpart_checksum`, `_wwpart_payload`, `_wwpart_image_queue`, `_wwpart_image_map`, `_wwpart_image_state`, `_wwpart_markup_used`; Kategorie-Termmeta `_wwpart_markup`; Option `wwpart_settings`.

Hooks: `wwpart_sales_price`, `wwpart_allowed_image_hosts`, `wwpart_product_synced`, `wwpart_loaded`.

## Lizenz

MIT – siehe `LICENSE`.
