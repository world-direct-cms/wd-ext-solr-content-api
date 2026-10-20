<!-- omit in toc -->
# Handbuch für Anwender

<!-- omit in toc -->
## Inhaltsverzeichnis
- [Veränderungen zum bisherigen Plan](#veränderungen-zum-bisherigen-plan)
- [Idee](#idee)
- [Ablauf](#ablauf)
  - [Inhalte](#inhalte)
  - [Dateien](#dateien)
  - [Benutzer](#benutzer)
  - [News](#news)
- [API-Methoden](#api-methoden)
  - [file-export](#file-export)
  - [changes](#changes)
  - [detail](#detail)
  - [index](#index)
- [Tests](#tests)

## Veränderungen zum bisherigen Plan
- Daten werden von Solr geholt, und nicht aus TYPO3
- Dies hat den Vorteil, dass keine Datenbank Abfragen durchgeführt werden müssen --> Wird viel schneller!
- Vorallem im Bezug auf die Inhalte von Seiten ist dies wesentlich schneller. Um die gerenderten Inhalte von Seiten zu bestimmen, macht Solr einen Request auf das Frontend, und holt sich dann die entsprechend markierten Inhalte. Diese werden dann HTML bereinigt und in Solr gespeichert. Dies "erneut" für die `solr-content-api` zu machen, würde viel Rechenleistung und vor allem Zeit benötigen. Da das Ergebnis schon in Solr vorliegt, können wir uns dieses Ergebnisses bedienen.

## Idee
- Daten werden vom Solr Server gelesen
- Dort ist bereits alles indiziert und so aufbereitet wie es für eine KI notwendig ist.
- Die Extension `solr-content-api` bildet einen Wrapper um die Solr Schnittstelle und ermöglicht die Abfrage für eine externe KI.
- D.h. Solr muss funktionieren und laufen, damit die `solr-content-api` Schnittstelle zur Verfügung steht.

## Ablauf
- Es werden Inhalte in TYPO3 verändert
- Handelt es sich dabei um Inhalte die in Solr indiziert werden (Seiten, Inhalte, Benutzer, News, Dateien, ...) dann...
- ... generiert TYPO3 einen Index Queue Eintrag (Warteschlangen Eintrag zur Indizierung im Solr Server)
- TYPO3 arbeitet alle **3 Minuten** (aktuell so konfiguriert) **50 Einträge** dieser Index Queue ab, und indiziert deren Inhalte in Solr.

### Inhalte
- Bei Inhalten handelt es sich um Seiten und deren Inhaltselemente
- Es werden nur die Seiten indiziert, welche in den Seiteneigenschaften unter "Verhalten" die Option "In Indexsuche einbeziehen" gesetzt haben. Durch ein Entfernen dieser Option, kann eine Seite aus dem Index entfernt werden, und somit nicht mehr an die KI übermittelt werden.

### Dateien
- Es ist beschränkt auf die Dateitypen `pdf`, `doc`, `docx` und `xlsx` (siehe Konfiguration Extension `solr-file-indexer`).
- Dateien aus dem Ordner `user_upload`, die in einer für Solr freigegebenen Dateisammlung enthalten sind, werden unabhängig davon indiziert, ob sie in einem Inhaltselement, einer News oder an anderer Stelle referenziert werden.
- Soll eine solche Datei nicht mehr im Solr-Index erscheinen, muss sie aus allen für Solr freigegebenen Dateisammlungen entfernt werden. Beim nächsten Lauf des Datei-Indexer-Workers wird das Solr-Dokument entfernt.
- Wird eine Datei unter demselben Namen erneut hochgeladen, dann wird sie auch in Solr neu indiziert.

### Benutzer
- Wird ein Benutzer im TYPO3 Backend "deaktiviert", dann wird dieser im Solr Index gelöscht (TYPO3 kommuniziert mit dem Solr Server beim Löschen/Ausblenden des Eintrags).

### News

## API-Methoden

### file-export

### changes
- Verarbeitet einen Plfichtparameter `since`
- Liefert geänderte Solr Documents mit `action: "upsert"` und TYPO3-Soft-Deletes mit `action: "delete"`
- Upserts enthalten den Dokumentinhalt; Delete-Einträge enthalten mindestens `type` und `uid`
- Das Löschen einer FAL-Datei wird ebenfalls als `delete` für den zugehörigen `sys_file_metadata`-Datensatz gemeldet.
- Soft-Deletes werden über die in der TCA konfigurierten Delete- und Zeitstempelfelder erkannt (üblicherweise `deleted` und `tstamp`). Hard-Deletes und Ausblendungen ohne Soft-Delete werden nicht erfasst.

### detail

### index

## Tests
- Datei: Datei mit demselben Namen erneut hochgeladen, wurde indiziert! --> OK
- Seiteninhalt: Text wurde angepasst, wurde indiziert! --> OK