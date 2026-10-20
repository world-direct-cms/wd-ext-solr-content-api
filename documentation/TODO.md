# TODO

## Solr
- Solr muss viel genauer und besser funktionieren, wenn der Export für BIG-GPT darauf aufbaut.
- Alle Solr Instanzen von allen Projekten müssen **"ordentlich"** funktionieren. --> Ist eh eine gute Chance sich das erneut anzusehen.
- Dokument welches erklärt wie das in TYPO3/Solr/BIG-GPT funktioniert. Wie alles ineinander greift.

- Bei allen PROJEKTEN: 
  - Checken welche Seiten ausgenommen werden sollen vom Solr Index!

- Solr checken: Wie wird mit `fe_users` umgegangen, welche ein `widthdraw_date` gesetzt haben? Wird der Eintrag dann in die Index Queue gepackt, und dann in Solr gelöscht? Oder wie läuft das? Vorallem wenn das `withdraw_date` nicht im Backend sondern per Import gesetzt wird?