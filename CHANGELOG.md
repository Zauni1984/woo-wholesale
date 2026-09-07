# Changelog

## 1.0.1 – 2026-09-07

- **Fix:** Neu angelegte und importierte Rollen hatten intern „Felder im Produkt-Editor anzeigen“ und „Staffeltabelle anzeigen“ auf *nein*, dadurch fehlten im Produkt die Preisfelder. Bestehende Installationen werden beim Update automatisch repariert.
- Produktliste: je Großhandelsrolle eine eigene Preisspalte, ein-/ausblendbar über die Ansichtsoptionen. Zeigt den tatsächlich gültigen Preis inklusive Herkunft (Produkt, Kategorie, shopweit).
- Build/CI: Das Artefakt enthält jetzt den Plugin-Ordner statt eines ZIPs im ZIP, der Download ist damit direkt installierbar.

## 1.0.0 – 2026-09-06

- Erste Version: Großhandelsrollen, Produkt-/Varianten-/Kategorie-/Shop-Preise, Staffelrabatte, Zweitpreis (netto/brutto), Steueranzeige je Rolle, Mindestbestellwert, Gutscheinsperre, Importer für WooCommerce Wholesale Prices, deutsche Übersetzung.
