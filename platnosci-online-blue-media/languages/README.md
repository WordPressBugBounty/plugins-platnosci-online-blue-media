## Translacje (i18n)

Proces generowania translacji wtyczki:

1. Dodaj nowy tekst w kodzie.

2. Przejdź do folderu `public`:

   ```bash
   cd ../../../public
   ```

3. Wygeneruj nowy szablon `.pot`:

   ```bash
   wp i18n make-pot wp-content/plugins/platnosci-online-blue-media wp-content/plugins/platnosci-online-blue-media/languages/platnosci-online-blue-media.pot --domain=platnosci-online-blue-media
   ```

4. Zaktualizuj `.po` na podstawie `.pot`:

   ```bash
   for locale in pl_PL de_DE es_ES it_IT; do wp i18n update-po wp-content/plugins/platnosci-online-blue-media/languages/platnosci-online-blue-media.pot wp-content/plugins/platnosci-online-blue-media/languages/platnosci-online-blue-media-${locale}.po; done
   ```

5. Uzupełnij brakujące translacje w plikach `.po`.

6. Wygeneruj pliki `.json` dla bloków Gutenberga, jeżeli dotyczy:

   ```bash
   wp i18n make-json wp-content/plugins/platnosci-online-blue-media/languages --no-purge
   ```

7. Wróć do folderu wtyczki:

   ```bash
   cd wp-content/plugins/platnosci-online-blue-media
   ```

8. Wygeneruj `.mo`, jeżeli nie zrobi tego Loco Translate:

   ```bash
   for locale in pl_PL de_DE es_ES it_IT; do msgfmt languages/platnosci-online-blue-media-${locale}.po -o languages/platnosci-online-blue-media-${locale}.mo; done
   ```
