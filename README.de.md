# Image Slider

Deutsch | [English](README.md)

Image Slider stellt eine IP-Symcon-Kachel zur Anzeige von Bild-Medienobjekten bereit.
Präfix: `IMGS`.

## Inhaltsverzeichnis

1. [Funktionen](#1-funktionen)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Bedienung in der Kachel](#5-bedienung-in-der-kachel)
6. [Öffentliche Methoden](#6-öffentliche-methoden)
7. [Update von Version 2](#7-update-von-version-2)
8. [Unterstützung](#8-unterstützung)
9. [Lizenz](#9-lizenz)
10. [Änderungen](#10-änderungen)

## 1. Funktionen

- Bildliste mit Titel und Beschreibung je Bild, sortierbar per Drag-and-Drop
- Jeder Client blättert, zoomt und blendet Bildtexte unabhängig von anderen Clients
- Bilder werden über einen WebHook geladen, vom Browser zwischengespeichert und
  das nächste und vorherige Bild im Voraus geladen
- Geänderte Medienobjekte (z. B. Kamerabilder) werden automatisch aktualisiert
- Übergänge: Überblenden, Schieben oder kein Übergang, mit einstellbarer Dauer
- Navigation mit Pfeilen, Wischgesten und Pfeiltasten
- Zoom mit Pinch-Geste, Zoom-Buttons, Ctrl+Mausrad oder Tasten `+`, `-`, `0`
- Temporäres Hinzufügen und Entfernen von Bildern sowie temporäre Bildtexte per Script

## 2. Voraussetzungen

- IP-Symcon ab Version 8.1
- Ab Version 9.1 kann die Kachel zusätzlich im Vollbild-Modus angezeigt werden.

## 3. Installation

Über den Module Store oder im Module Control mit der URL
`https://github.com/wowbagger60/ImageSlider`.

## 4. Konfiguration

| Einstellung | Beschreibung |
|---|---|
| Bilder | Bild-Medienobjekte mit optionalem Titel und Beschreibung |
| Übergang / Übergangsdauer | Überblenden, Schieben oder kein Übergang; 0 bis 5000 ms |
| Bildtexte standardmässig anzeigen | Anfangszustand der Bildtexte beim Öffnen der Kachel |
| Navigationspfeile anzeigen | Pfeile links und rechts |
| Bildzähler anzeigen | Anzeige «1 / 5» unten rechts |
| Zoom-Buttons in der Kachel anzeigen | Buttons `+` und `−`; im Vollbild werden sie nie angezeigt |

Jedes Bild darf nur einmal vorkommen. Ein leerer Eintrag, ein gelöschtes oder ein
anderes Medienobjekt als ein Bild setzt die Instanz in den Status «Konfiguration
ungültig»; die Ursache steht im Konfigurationsformular. Gültige Bilder werden
weiterhin angezeigt.

## 5. Bedienung in der Kachel

- **Blättern:** Pfeile, Wischen nach links oder rechts, Pfeiltasten. Beim Wischen
  in vertikaler Richtung scrollt die Symcon-Seite.
- **Zoomen:** Pinch mit zwei Fingern, Zoom-Buttons, Ctrl+Mausrad (auch Pinch auf
  dem Trackpad) oder Tasten `+`, `-` und `0`. Im Vollbild zoomt das Mausrad ohne Ctrl.
- **Verschieben:** Im gezoomten Zustand mit einem oder zwei Fingern bzw. mit der Maus.
  Gewischt wird dann nicht, Blättern ist über die Pfeile möglich.
- **Bildtexte:** Das Symbol unten links blendet Titel und Beschreibung ein und aus.

Position, Zoom und Bildtexte gehören dem jeweiligen Client. Bild und Zoom bleiben
erhalten, wenn die Kachel im selben Browser-Tab neu geladen wird.

> **Pinch in der Symcon-App:** Auf scrollbaren Seiten übernimmt iOS in der
> Symcon-App Zwei-Finger-Gesten selbst, bevor die Kachel sie erhält. Dort sind die
> Zoom-Buttons die Alternative. In Browsern und im Vollbild funktioniert Pinch.

## 6. Öffentliche Methoden

```php
IMGS_AddImage(int $InstanceID, int $MediaID): bool;
IMGS_RemoveImage(int $InstanceID, int $MediaID): bool;
IMGS_ClearImages(int $InstanceID): bool;
IMGS_SetImageText(int $InstanceID, int $MediaID, string $Title, string $Description): bool;
```

- `AddImage()` hängt ein Bild temporär an die konfigurierten Bilder an.
- `RemoveImage()` und `ClearImages()` entfernen nur temporäre Bilder, nie konfigurierte.
- `SetImageText()` ändert Titel und Beschreibung eines konfigurierten oder temporären
  Bildes temporär.

Temporäre Änderungen gehen bei einem Neustart von IP-Symcon verloren.

## 7. Update von Version 2

Version 3 unterstützt nur noch eine Bildliste pro Instanz. Für mehrere Alben werden
mehrere Instanzen angelegt. Die Umstellung geschieht beim ersten Start nach dem
Update automatisch:

1. Das erste Album, bisher das Default-Album, wird zur Bildliste der Instanz. Die
   Kachel zeigt danach dieselben Bilder wie vorher.
2. Enthielt die Instanz weitere Alben, erscheint im Konfigurationsformular der Bereich
   «Alben aus Version 2» und im Meldungsfenster eine Warnung. Die Alben bleiben
   erhalten, bis sie bearbeitet werden:
   - **Als neue Instanz anlegen:** legt eine Image-Slider-Instanz mit dem Albumnamen
     neben der bisherigen Instanz an und übernimmt Bilder, Bildtexte und
     Darstellungseinstellungen. Die neue Instanz muss anschliessend in der
     Visualisierung platziert werden.
   - **Alte Alben verwerfen:** löscht die verbleibenden Alben endgültig.

**Scripts anpassen:** Die Methoden haben keinen Parameter `$AlbumName` mehr, und
`IMGS_SetAlbum()` entfällt. Scripts, die diese Methoden verwenden, melden nach dem
Update einen Fehler und müssen angepasst werden:

| Version 2 | Version 3 |
|---|---|
| `IMGS_SetAlbum($id, 'Ferien')` | entfällt; das Album als eigene Instanz anlegen und diese anzeigen |
| `IMGS_AddImage($id, 'Ferien', $media)` | `IMGS_AddImage($id, $media)` an der Instanz des Albums |
| `IMGS_RemoveImage($id, 'Ferien', $media)` | `IMGS_RemoveImage($id, $media)` |
| `IMGS_ClearImages($id, 'Ferien')` | `IMGS_ClearImages($id)` |
| `IMGS_SetImageText($id, 'Ferien', $media, $t, $d)` | `IMGS_SetImageText($id, $media, $t, $d)` |

## 8. Unterstützung

Dieses Modul ist kostenlos. Schenkungen als Unterstützung für den Autor werden
gerne angenommen: PayPal an ueli.schweizer@cassanis.fr

## 9. Lizenz

MIT

## 10. Änderungen

**3.0:**

- Eine Bildliste pro Instanz statt mehrerer Alben, mit automatischer Migration
  (siehe [Update von Version 2](#7-update-von-version-2)).
- Jeder Client blättert unabhängig; bisher wechselte das Bild auf allen Clients.
- Bilder werden über einen WebHook geladen, zwischengespeichert und vorgeladen.
  Das beschleunigt vor allem den Zugriff über Connect und VPN deutlich.
- Geänderte und gelöschte Medienobjekte werden sofort übernommen.
- Zoom mit Pinch, Zoom-Buttons, Ctrl+Mausrad und Tastatur; Verschieben mit einem
  oder zwei Fingern. Die Symcon-Seite bleibt beim vertikalen Wischen scrollbar.
- Optionen für Navigationspfeile, Bildzähler und Zoom-Buttons.
- Vollbild-Anzeige ab IP-Symcon 9.1.
- Nicht darstellbare Bilder blockieren die Navigation nicht mehr.
- Bei Übergängen scheint das vorherige Bild nicht mehr durch transparente Bilder.
- Die Skript-API hat keinen Albumnamen mehr; `IMGS_SetAlbum()` entfällt.

**2.1:**

- Auf Touchgeräten mit Wischgesten zwischen Bildern wechseln.
- Bildtexte über das Symbol am unteren Kachelrand ein- und ausblenden.
- Konfigurierbarer Anfangszustand der Bildtexte.
