# Image Slider

Image Slider stellt eine IP-Symcon-Kachel zur Anzeige von Bild-Medienobjekten in konfigurierbaren Alben bereit.

## Funktionen

- Mehrere konfigurierbare Alben
- Sortierung von Alben und Bildern per Drag-and-Drop
- Titel und Beschreibung je Bild
- Auswahl des Albums zur Laufzeit
- Temporäres Hinzufügen und Entfernen von Bildern zur Laufzeit
- Temporäre Änderung von Titel und Beschreibung
- Übergänge: Überblenden, Schieben oder kein Übergang
- Konfigurierbare Übergangsdauer
- Vor-/Zurück-Navigation und Navigation mit den Pfeiltasten
- Prüfung von Albumnamen und Bild-Medienobjekten
- Referenzen für alle konfigurierten Medienobjekte

Das erste konfigurierte Album wird als Default-Album verwendet. Änderungen zur Laufzeit sind temporär und bleiben nach einem Neustart von IP-Symcon nicht erhalten.

## Öffentliche Methoden

```php
IMGS_SetAlbum(int $InstanceID, string $AlbumName);
IMGS_AddImage(int $InstanceID, string $AlbumName, int $MediaID);
IMGS_RemoveImage(int $InstanceID, string $AlbumName, int $MediaID);
IMGS_ClearImages(int $InstanceID, string $AlbumName);
IMGS_SetImageText(int $InstanceID, string $AlbumName, int $MediaID, string $Title, string $Description);
```

Ein leerer Albumname bei `SetAlbum()` wählt das Default-Album.

`AddImage()` fügt ausschliesslich temporäre Bilder hinzu. `RemoveImage()` und `ClearImages()` entfernen nur temporäre Bilder und verändern niemals den konfigurierten Albuminhalt.

## Lizenz

MIT

## Version 2.1

- Auf Touchgeräten mit Wischgesten zwischen Bildern wechseln.
- Bildtexte über das Symbol am unteren Kachelrand ein- und ausblenden.
- Konfigurierbarer Anfangszustand der Bildtexte. Die Auswahl bleibt beim Blättern erhalten und wird beim erneuten Laden der Kachel zurückgesetzt.
