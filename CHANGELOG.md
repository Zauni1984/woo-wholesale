# Changelog

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
