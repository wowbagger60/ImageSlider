# Image Slider

[Deutsch](README.de.md) | English

Image Slider provides an IP-Symcon tile for displaying image media objects.
Prefix: `IMGS`.

## Contents

1. [Features](#1-features)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Configuration](#4-configuration)
5. [Using the tile](#5-using-the-tile)
6. [Public methods](#6-public-methods)
7. [Updating from version 2](#7-updating-from-version-2)
8. [Support](#8-support)
9. [License](#9-license)
10. [Changes](#10-changes)

## 1. Features

- Image list with title and description per image, sortable by drag and drop
- Each client navigates, zooms and toggles captions independently of other clients
- Images are loaded through a WebHook, cached by the browser, and the next and
  previous images are preloaded
- Changed media objects (e.g. camera snapshots) are updated automatically
- Transitions: fade, slide or none, with configurable duration
- Navigation with arrows, swipe gestures and arrow keys
- Zoom with pinch, zoom buttons, Ctrl+mouse wheel or the keys `+`, `-`, `0`
- Temporary addition and removal of images and temporary captions by script

## 2. Requirements

- IP-Symcon 8.1 or later
- From 9.1, the tile can also be shown in fullscreen mode.

## 3. Installation

Via the Module Store, or in Module Control with the URL
`https://github.com/wowbagger60/ImageSlider`.

## 4. Configuration

| Setting | Description |
|---|---|
| Images | Image media objects with optional title and description |
| Transition / Transition duration | Fade, slide or none; 0 to 5000 ms |
| Show image captions by default | Initial caption state when the tile opens |
| Show navigation arrows | Arrows on the left and right |
| Show image counter | "1 / 5" at the bottom right |
| Show zoom buttons in the tile | `+` and `−` buttons; never shown in fullscreen |

Each image may only occur once. An empty entry, a deleted media object or one that
is not an image sets the instance to "Invalid configuration"; the cause is shown in
the configuration form. Valid images are still displayed.

## 5. Using the tile

- **Navigate:** arrows, swipe left or right, arrow keys. Vertical swipes scroll the
  Symcon page.
- **Zoom:** two-finger pinch, zoom buttons, Ctrl+mouse wheel (including trackpad
  pinch) or the keys `+`, `-` and `0`. In fullscreen, the mouse wheel zooms without Ctrl.
- **Pan:** when zoomed, with one or two fingers or the mouse. Swiping does not
  navigate then; use the arrows.
- **Captions:** the icon at the bottom left shows and hides title and description.

Position, zoom and captions belong to each client. Image and zoom are kept when the
tile reloads in the same browser tab.

> **Pinch in the Symcon app:** on scrollable pages, iOS takes over two-finger gestures
> in the Symcon app before the tile receives them. Use the zoom buttons there. Pinch
> works in browsers and in fullscreen.

## 6. Public methods

```php
IMGS_AddImage(int $InstanceID, int $MediaID): bool;
IMGS_RemoveImage(int $InstanceID, int $MediaID): bool;
IMGS_ClearImages(int $InstanceID): bool;
IMGS_SetImageText(int $InstanceID, int $MediaID, string $Title, string $Description): bool;
```

- `AddImage()` temporarily appends an image to the configured images.
- `RemoveImage()` and `ClearImages()` only remove temporary images, never configured ones.
- `SetImageText()` temporarily changes title and description of a configured or
  temporary image.

Temporary changes are lost when IP-Symcon restarts.

## 7. Updating from version 2

Version 3 supports one image list per instance. Use several instances for several
albums. The conversion runs automatically on the first start after the update:

1. The first album, previously the default album, becomes the instance's image list.
   The tile shows the same images as before.
2. If the instance contained further albums, the configuration form shows the section
   "Albums from version 2" and a warning appears in the message log. The albums are
   kept until you handle them:
   - **Create as new instance:** creates an Image Slider instance named after the
     album next to the existing instance, with its images, captions and display
     settings. Place the new instance in the visualization afterwards.
   - **Discard old albums:** permanently deletes the remaining albums.

**Adapting scripts:** the methods no longer take an `$AlbumName` parameter, and
`IMGS_SetAlbum()` has been removed. Scripts using these methods report an error after
the update and must be adapted:

| Version 2 | Version 3 |
|---|---|
| `IMGS_SetAlbum($id, 'Holidays')` | removed; create the album as an instance of its own and show it |
| `IMGS_AddImage($id, 'Holidays', $media)` | `IMGS_AddImage($id, $media)` on the album's instance |
| `IMGS_RemoveImage($id, 'Holidays', $media)` | `IMGS_RemoveImage($id, $media)` |
| `IMGS_ClearImages($id, 'Holidays')` | `IMGS_ClearImages($id)` |
| `IMGS_SetImageText($id, 'Holidays', $media, $t, $d)` | `IMGS_SetImageText($id, $media, $t, $d)` |

## 8. Support

This module is free of charge. Donations to support the author are gratefully
accepted: PayPal to ueli.schweizer@cassanis.fr

## 9. License

MIT

## 10. Changes

**3.0:**

- One image list per instance instead of several albums, with automatic migration
  (see [Updating from version 2](#7-updating-from-version-2)).
- Each client navigates independently; previously the image changed on all clients.
- Images are loaded through a WebHook, cached and preloaded. This makes access via
  Connect and VPN considerably faster.
- Changed and deleted media objects are applied immediately.
- Zoom with pinch, zoom buttons, Ctrl+mouse wheel and keyboard; pan with one or two
  fingers. The Symcon page stays scrollable with vertical swipes.
- Options for navigation arrows, image counter and zoom buttons.
- Fullscreen display from IP-Symcon 9.1.
- Images that cannot be displayed no longer block navigation.
- During transitions, the previous image no longer shows through transparent images.
- The script API no longer takes an album name; `IMGS_SetAlbum()` has been removed.

**2.1:**

- Swipe left or right on touch devices to navigate between images.
- Toggle image captions using the icon at the bottom of the tile.
- Configurable initial caption state.
