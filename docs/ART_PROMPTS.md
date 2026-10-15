# Bildbedarf Fall 001 (Prompts) – **keine dieser Bilder existiert bisher**

Das Spiel zeigt für jede fehlende Datei einen gekennzeichneten Platzhalter („PLATZHALTER · Bild fehlt: Pfad“). Dateien nach `public/assets/media/<pfad>` legen. Format JPG/WebP, sRGB.

**Stilrichtlinie:** fotorealistisch, dokumentarisch, kühle Farbtemperatur mit warmen Lichtakzenten, leichte Filmkörnung, keine Comicoptik, keine lesbaren echten Marken/Logos, keine erkennbaren realen Personen. Alle Personen fiktiv, **konsistente Gesichter**: pro Person einen Referenz-Seed/Charakter-Referenzbild nutzen und für alle Bilder derselben Person wiederverwenden.

| Datei | Mass | Prompt (Kern) |
|---|---|---|
| case001/cover.jpg | 1600×900 | Nachtaufnahme eines Schweizer Grandhotels am Vierwaldstättersee, beleuchtete Fassade, ein erleuchtetes Fenster im 4. Stock, Nebel über dem See, Polizeiabsperrband im Vordergrund unscharf |
| case001/suite412.jpg | 2400×1350 | Luxus-Hotelsuite, Wohnbereich, Schreibtisch am Fenster mit Papieren und Taschenkalender, Sideboard mit zwei Weingläsern, umgekippter Stuhl, bronzene Falkenfigur am Boden, kühles Kunstlicht, Tatortmarkierungen (nummerierte Aufsteller), keine Leiche sichtbar, Weitwinkel 24 mm |
| case001/corridor4.jpg | 2400×1350 | Langer Hotelflur 4. Stock, rotes Teppichmuster, Dome-Überwachungskamera an der Decke, Brandschutztür am Ende, Servierwagen-Tablett vor einer Tür, Morgenlicht |
| case001/reception.jpg | 2400×1350 | Marmorrezeption eines Grandhotels, Backoffice-Tür offen, Schwarzes Brett, Stempeluhr-Terminal, ruhiges Morgenlicht |
| case001/bar.jpg | 2400×1350 | Holzgetäfelte Hotelbar am Abend, Tresen mit Kassenterminal, gedimmte Messinglampen |
| case001/laundry.jpg | 2400×1350 | Wäscherei im Untergeschoss, Industriewaschmaschinen, Kleiderstangen mit Schutzhüllen, Neonlicht |
| case001/p_brandt.jpg | 800×1000 | Porträt Mann, 48, gepflegt, Anzug ohne Krawatte, freundlich-kontrolliert, Polizeiverhörraum-Licht (Hintergrund neutral) |
| case001/p_elena.jpg | 800×1000 | Porträt Frau, 29, Restauratorin, dunkle Haare, übermüdet, gereizter Blick |
| case001/p_mara.jpg | 800×1000 | Porträt Frau, 34, Hotel-Uniform, nervös, erschöpft |
| case001/p_tobias.jpg | 800×1000 | Porträt Mann, 52, Arbeitskleidung (Haustechnik), wortkarg, misstrauisch |
| case001/p_roth.jpg | 800×1000 | Porträt Frau, 47, Anwältin, Blazer, gefasst |
| case001/ev_scene_photos.jpg | 1600×1200 | Polizei-Tatortfoto-Collage-Stil (Detailaufnahmen mit Massstab und Nummernschildern) |
| case001/ev_weapon.jpg | 1200×1200 | Forensische Beweisaufnahme: Bronzefalke auf Marmorsockel mit Massstab, weisser Hintergrund, Blutspuren am Sockel |
| case001/ev_glasses.jpg | 1200×900 | Zwei Rotweingläser auf Sideboard, Beweisschildchen |
| case001/ev_calendar.jpg | 1200×900 | Aufgeschlagener Taschenkalender, handschriftlicher Eintrag „23:15 L.B. – letzte Aussprache, Suite“ (unterstrichen) – Text im Bild selbst setzen, nicht generieren lassen |
| case001/ev_briefcase.jpg | 1200×900 | Offener Aktenkoffer mit Expertisenordner und Briefentwurf |
| case001/ev_card_ledger.jpg | 1200×900 | Gebundenes Kartenjournal mit handschriftlicher Eintragung 19:30 (Text nachträglich einsetzen) |
| case001/ev_bar_receipt.jpg | 1200×900 | Kassenbeleg-Ausdruck Hotelbar 22:48, Zimmer 308 (Text nachträglich einsetzen) |
| case001/ev_jacket.jpg | 1200×1200 | Dunkelblaues Sakko in Schutzhülle, Fleck am rechten Ärmel, Beweisanhänger „308“ |

**Hinweis:** Lesbare Texte (Kalender, Journal, Beleg) nicht von der Bild-KI erzeugen lassen, sondern per Bildbearbeitung/HTML-Render einsetzen, damit sie exakt mit den Falldaten übereinstimmen.
