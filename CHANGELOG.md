# Changelog

## 1.0.2 – 2026-09-07

- **Fix:** Im Produkt-Editor überlagerten sich die Rollenblöcke – die Staffel-Checkbox rutschte in die nächste Rolle. Die Felder nutzen jetzt das Standard-Markup von WooCommerce und räumen die Floats sauber ab; die doppelte Beschriftung „Staffelrabatte“ ist weg.

## 1.0.1 – 2026-09-07

- **Fix:** Neu angelegte und importierte Rollen hatten intern „Felder im Produkt-Editor anzeigen“ und „Staffeltabelle anzeigen“ auf *nein*, dadurch fehlten im Produkt die Preisfelder. Bestehende Installationen werden beim Update automatisch repariert.
- Produktliste: je Großhandelsrolle eine eigene Preisspalte, ein-/ausblendbar über die Ansichtsoptionen. Zeigt den tatsächlich gültigen Preis inklusive Herkunft (Produkt, Kategorie, shopweit).
- Build/CI: Das Artefakt enthält jetzt den Plugin-Ordner statt eines ZIPs im ZIP, der Download ist damit direkt installierbar.

## 1.0.0 – 2026-09-06

- Erste Version: Großhandelsrollen, Produkt-/Varianten-/Kategorie-/Shop-Preise, Staffelrabatte, Zweitpreis (netto/brutto), Steueranzeige je Rolle, Mindestbestellwert, Gutscheinsperre, Importer für WooCommerce Wholesale Prices, deutsche Übersetzung.
