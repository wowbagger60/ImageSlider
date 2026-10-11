# Image Slider

Image Slider provides an IP-Symcon tile for displaying image media objects in configurable albums.

## Features

- Multiple configurable albums
- Drag-and-drop sorting of albums and images
- Title and description for each image
- Runtime album selection
- Temporary runtime addition and removal of images
- Temporary runtime text overrides
- Fade, slide or no transition
- Configurable transition duration
- Previous/next navigation and keyboard arrow navigation
- Validation of album names and image media objects
- References for all configured media objects

The first configured album is used as the default album. Runtime changes are temporary and are not persisted across an IP-Symcon restart.

## Public methods

```php
IMGS_SetAlbum(int $InstanceID, string $AlbumName);
IMGS_AddImage(int $InstanceID, string $AlbumName, int $MediaID);
IMGS_RemoveImage(int $InstanceID, string $AlbumName, int $MediaID);
IMGS_ClearImages(int $InstanceID, string $AlbumName);
IMGS_SetImageText(int $InstanceID, string $AlbumName, int $MediaID, string $Title, string $Description);
```

An empty album name in `SetAlbum()` selects the default album.

`AddImage()` only adds temporary images. `RemoveImage()` and `ClearImages()` only remove temporary images and never modify the configured album contents.

## License

MIT

## Version 2.1

- Swipe left or right on touch devices to navigate between images.
- Toggle image captions using the icon at the bottom of the tile.
- Configure whether captions are shown by default. The choice made in the tile is retained while navigating and resets when the tile reloads.
