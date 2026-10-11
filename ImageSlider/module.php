<?php

declare(strict_types=1);

class ImageSlider extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Albums', '[]');
        $this->RegisterPropertyString('Transition', 'Fade');
        $this->RegisterPropertyInteger('TransitionDuration', 300);
        $this->RegisterPropertyBoolean('ShowCaptionsByDefault', true);

        $this->SetBuffer('ActiveAlbum', '');
        $this->SetBuffer('CurrentMediaID', '0');
        $this->SetBuffer('TemporaryImages', '{}');
        $this->SetBuffer('TextOverrides', '{}');
        $this->SetBuffer('ConfigurationError', '');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $albums = $this->GetAlbumsRaw();
        $this->SendDebug('ApplyChanges', sprintf('Validating configuration: %d album(s)', count($albums)), 0);
        $error = $this->ValidateConfiguration($albums);

        if ($error !== null) {
            $this->SetBuffer('ConfigurationError', $error);
            $this->SetStatus(201);
            $this->SendDebug('Configuration', $error, 0);
            $this->ReloadForm();
            return;
        }

        $this->SetBuffer('ConfigurationError', '');
        $this->SetStatus(102);
        $this->ReloadForm();

        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        $references = [];
        foreach ($albums as $album) {
            foreach ($this->NormalizeImagesValue($album['Images'] ?? []) as $image) {
                $mediaID = (int) ($image['MediaID'] ?? 0);
                $references[$mediaID] = true;
            }
        }

        foreach (array_keys($references) as $mediaID) {
            $this->RegisterReference((int) $mediaID);
        }

        $imageCount = 0;
        foreach ($albums as $album) {
            $imageCount += count($this->NormalizeImagesValue($album['Images'] ?? []));
        }

        $this->SendDebug(
            'ApplyChanges',
            sprintf(
                'Configuration valid: %d album(s), %d image(s), %d reference(s), default album: %s',
                count($albums),
                $imageCount,
                count($references),
                $this->GetDefaultAlbumName() !== '' ? $this->GetDefaultAlbumName() : '<none>'
            ),
            0
        );

        $this->SetBuffer('ActiveAlbum', '');
        $this->SetBuffer('CurrentMediaID', '0');

        $this->UpdateVisualizationValue(
            $this->GetFullUpdateMessageByIndex(0)
        );
    }

    public function GetConfigurationForm(): string
    {
        $formContent = file_get_contents(__DIR__ . '/form.json');

        if ($formContent === false) {
            return '{}';
        }

        $form = json_decode($formContent, true);

        if (!is_array($form)) {
            return '{}';
        }

        $error = $this->GetBuffer('ConfigurationError');

        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') !== 'ConfigurationError') {
                continue;
            }

            $element['caption'] = $error;
            $element['visible'] = ($error !== '');
            break;
        }
        unset($element);

        $json = json_encode($form, JSON_UNESCAPED_UNICODE);

        return $json !== false ? $json : '{}';
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/assets/index.html');

        if ($html === false) {
            return '';
        }

        return $html .
            '<script>handleMessage(' .
            json_encode($this->GetFullUpdateMessageByIndex(0)) .
            ');</script>';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'ShowImage':
                $this->UpdateVisualizationValue(
                    $this->GetFullUpdateMessageByIndex((int) $Value)
                );
                break;

            default:
                throw new Exception($this->Translate('Invalid Ident: ') . $Ident);
        }
    }

    public function SetAlbum(string $AlbumName): bool
    {
        if ($AlbumName === '') {
            $this->SetBuffer('ActiveAlbum', '');
        } else {
            if (!$this->AlbumExists($AlbumName)) {
                $this->SendDebug('SetAlbum', sprintf('Album not found: %s', $AlbumName), 0);
                return false;
            }

            $this->SetBuffer('ActiveAlbum', $AlbumName);
        }

        $this->SendDebug('SetAlbum', sprintf('Selected album: %s', $this->GetActiveAlbumName() !== '' ? $this->GetActiveAlbumName() : '<none>'), 0);
        $this->SetBuffer('CurrentMediaID', '0');

        $this->UpdateVisualizationValue(
            $this->GetFullUpdateMessageByIndex(0)
        );

        return true;
    }

    public function AddImage(string $AlbumName, int $MediaID): bool
    {
        if (!$this->AlbumExists($AlbumName)) {
            $this->SendDebug('AddImage', sprintf('Album not found: %s', $AlbumName), 0);
            return false;
        }

        if (!$this->IsImageMedia($MediaID)) {
            $this->SendDebug('AddImage', sprintf('Invalid image MediaID: %d', $MediaID), 0);
            return false;
        }

        if ($this->ImageExistsInAlbum($AlbumName, $MediaID)) {
            $this->SendDebug('AddImage', sprintf('Image %d already exists in album %s', $MediaID, $AlbumName), 0);
            return false;
        }

        $temporaryImages = $this->ReadJsonBuffer('TemporaryImages');

        if (!isset($temporaryImages[$AlbumName])) {
            $temporaryImages[$AlbumName] = [];
        }

        $temporaryImages[$AlbumName][] = [
            'MediaID' => $MediaID,
            'Title' => '',
            'Description' => ''
        ];

        $this->WriteJsonBuffer('TemporaryImages', $temporaryImages);
        $this->SendDebug('AddImage', sprintf('Added temporary image %d to album %s', $MediaID, $AlbumName), 0);
        $this->RefreshActiveAlbumPreservingImage($AlbumName);

        return true;
    }

    public function RemoveImage(string $AlbumName, int $MediaID): bool
    {
        if (!$this->AlbumExists($AlbumName)) {
            $this->SendDebug('RemoveImage', sprintf('Album not found: %s', $AlbumName), 0);
            return false;
        }

        $temporaryImages = $this->ReadJsonBuffer('TemporaryImages');

        if (!isset($temporaryImages[$AlbumName]) || !is_array($temporaryImages[$AlbumName])) {
            $this->SendDebug('RemoveImage', sprintf('No temporary images in album %s', $AlbumName), 0);
            return false;
        }

        $found = false;
        $newImages = [];

        foreach ($temporaryImages[$AlbumName] as $image) {
            if ((int) ($image['MediaID'] ?? 0) === $MediaID) {
                $found = true;
                continue;
            }

            $newImages[] = $image;
        }

        if (!$found) {
            $this->SendDebug('RemoveImage', sprintf('Temporary image %d not found in album %s', $MediaID, $AlbumName), 0);
            return false;
        }

        $temporaryImages[$AlbumName] = $newImages;
        $this->WriteJsonBuffer('TemporaryImages', $temporaryImages);
        $this->RemoveTextOverride($AlbumName, $MediaID);
        $this->SendDebug('RemoveImage', sprintf('Removed temporary image %d from album %s', $MediaID, $AlbumName), 0);

        $this->RefreshActiveAlbumPreservingImage($AlbumName);

        return true;
    }

    public function ClearImages(string $AlbumName): bool
    {
        if (!$this->AlbumExists($AlbumName)) {
            $this->SendDebug('ClearImages', sprintf('Album not found: %s', $AlbumName), 0);
            return false;
        }

        $temporaryImages = $this->ReadJsonBuffer('TemporaryImages');

        if (!isset($temporaryImages[$AlbumName])) {
            $this->SendDebug('ClearImages', sprintf('No temporary images to clear in album %s', $AlbumName), 0);
            return true;
        }

        $removedMediaIDs = [];
        foreach ($temporaryImages[$AlbumName] as $image) {
            $removedMediaIDs[] = (int) ($image['MediaID'] ?? 0);
        }

        unset($temporaryImages[$AlbumName]);
        $this->WriteJsonBuffer('TemporaryImages', $temporaryImages);

        foreach ($removedMediaIDs as $mediaID) {
            if ($mediaID > 0) {
                $this->RemoveTextOverride($AlbumName, $mediaID);
            }
        }

        $this->SendDebug('ClearImages', sprintf('Cleared %d temporary image(s) from album %s', count($removedMediaIDs), $AlbumName), 0);
        $this->RefreshActiveAlbumPreservingImage($AlbumName);

        return true;
    }

    public function SetImageText(
        string $AlbumName,
        int $MediaID,
        string $Title,
        string $Description
    ): bool {
        if (!$this->AlbumExists($AlbumName) || !$this->ImageExistsInAlbum($AlbumName, $MediaID)) {
            $this->SendDebug('SetImageText', sprintf('Album/image not found: %s / %d', $AlbumName, $MediaID), 0);
            return false;
        }

        $overrides = $this->ReadJsonBuffer('TextOverrides');

        if (!isset($overrides[$AlbumName])) {
            $overrides[$AlbumName] = [];
        }

        $overrides[$AlbumName][(string) $MediaID] = [
            'Title' => $Title,
            'Description' => $Description
        ];

        $this->WriteJsonBuffer('TextOverrides', $overrides);
        $this->SendDebug('SetImageText', sprintf('Updated temporary text for image %d in album %s', $MediaID, $AlbumName), 0);
        $this->RefreshActiveAlbumPreservingImage($AlbumName);

        return true;
    }

    private function ValidateConfiguration(array $albums): ?string
    {
        $albumNames = [];

        foreach ($albums as $albumIndex => $album) {
            if (!is_array($album)) {
                return sprintf($this->Translate('Album %d has an invalid configuration.'), $albumIndex + 1);
            }

            $albumName = trim((string) ($album['Name'] ?? ''));

            if ($albumName === '') {
                return sprintf(
                    $this->Translate('Album %d has no name. Please enter an album name or remove the album.'),
                    $albumIndex + 1
                );
            }

            if (isset($albumNames[$albumName])) {
                return sprintf(
                    $this->Translate('The album name "%s" is used more than once. Album names must be unique.'),
                    $albumName
                );
            }

            $albumNames[$albumName] = true;

            $seenMediaIDs = [];
            $images = $this->NormalizeImagesValue($album['Images'] ?? []);

            foreach ($images as $imageIndex => $image) {
                $mediaID = (int) ($image['MediaID'] ?? 0);

                if ($mediaID === 0) {
                    return sprintf(
                        $this->Translate('Album "%s", image %d: No media object is selected. Please select an image or remove the empty entry.'),
                        $albumName,
                        $imageIndex + 1
                    );
                }

                if (isset($seenMediaIDs[$mediaID])) {
                    return sprintf(
                        $this->Translate('Album "%s": MediaID %d is used more than once. Each image may only occur once within an album.'),
                        $albumName,
                        $mediaID
                    );
                }

                $seenMediaIDs[$mediaID] = true;

                if (!IPS_MediaExists($mediaID)) {
                    return sprintf(
                        $this->Translate('Album "%s": MediaID %d does not exist. Please select an existing image media object.'),
                        $albumName,
                        $mediaID
                    );
                }

                $media = IPS_GetMedia($mediaID);

                if ($media['MediaType'] !== MEDIATYPE_IMAGE) {
                    return sprintf(
                        $this->Translate('Album "%s": MediaID %d is not an image media object.'),
                        $albumName,
                        $mediaID
                    );
                }
            }
        }

        return null;
    }

    private function GetFullUpdateMessageByIndex(int $requestedIndex): string
    {
        $albumName = $this->GetActiveAlbumName();
        $images = $this->GetAlbumImages($albumName);
        $count = count($images);

        if ($count === 0) {
            $this->SetBuffer('CurrentMediaID', '0');
            return $this->EncodeMessage($albumName, 0, 0, null);
        }

        $index = max(0, min($requestedIndex, $count - 1));

        return $this->BuildImageMessage($albumName, $images, $index);
    }

    private function GetFullUpdateMessageByMediaID(int $mediaID): string
    {
        $albumName = $this->GetActiveAlbumName();
        $images = $this->GetAlbumImages($albumName);

        if (count($images) === 0) {
            $this->SetBuffer('CurrentMediaID', '0');
            return $this->EncodeMessage($albumName, 0, 0, null);
        }

        foreach ($images as $index => $image) {
            if ((int) $image['MediaID'] === $mediaID) {
                return $this->BuildImageMessage($albumName, $images, $index);
            }
        }

        return $this->BuildImageMessage($albumName, $images, 0);
    }

    private function BuildImageMessage(string $albumName, array $images, int $index): string
    {
        $image = $images[$index];

        $this->SetBuffer('CurrentMediaID', (string) ((int) $image['MediaID']));

        return $this->EncodeMessage(
            $albumName,
            count($images),
            $index,
            $image
        );
    }

    private function EncodeMessage(
        string $albumName,
        int $count,
        int $index,
        ?array $image
    ): string {
        $transition = $this->ReadPropertyString('Transition');

        if (!in_array($transition, ['None', 'Fade', 'Slide'], true)) {
            $transition = 'Fade';
        }

        $duration = max(0, min(5000, $this->ReadPropertyInteger('TransitionDuration')));

        $json = json_encode([
            'album' => $albumName,
            'count' => $count,
            'index' => $index,
            'image' => $image === null ? '' : $this->GetImageDataURL((int) $image['MediaID']),
            'title' => $image === null ? '' : (string) $image['Title'],
            'description' => $image === null ? '' : (string) $image['Description'],
            'transition' => $transition,
            'transitionDuration' => $duration,
            'showCaptionsByDefault' => $this->ReadPropertyBoolean('ShowCaptionsByDefault'),
            'showCaptionsLabel' => $this->Translate('Show image captions'),
            'hideCaptionsLabel' => $this->Translate('Hide image captions'),
            'emptyText' => $this->Translate('No images available'),
            'ariaLabel' => $this->Translate('Image gallery')
        ], JSON_UNESCAPED_UNICODE);

        return $json !== false ? $json : '{}';
    }

    private function GetAlbumsRaw(): array
    {
        $albums = json_decode($this->ReadPropertyString('Albums'), true);

        return is_array($albums) ? $albums : [];
    }

    private function GetAlbums(): array
    {
        $result = [];

        foreach ($this->GetAlbumsRaw() as $album) {
            if (!is_array($album)) {
                continue;
            }

            $name = trim((string) ($album['Name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $result[] = [
                'Name' => $name,
                'Images' => $this->NormalizeImagesValue($album['Images'] ?? [])
            ];
        }

        return $result;
    }

    private function NormalizeImagesValue(mixed $images): array
    {
        if (is_string($images)) {
            if ($images === '') {
                return [];
            }

            $decoded = json_decode($images, true);
            $images = is_array($decoded) ? $decoded : [];
        }

        return is_array($images) ? $images : [];
    }

    private function GetDefaultAlbumName(): string
    {
        $albums = $this->GetAlbums();

        return count($albums) > 0 ? (string) $albums[0]['Name'] : '';
    }

    private function GetActiveAlbumName(): string
    {
        $activeAlbum = $this->GetBuffer('ActiveAlbum');

        if ($activeAlbum !== '' && $this->AlbumExists($activeAlbum)) {
            return $activeAlbum;
        }

        return $this->GetDefaultAlbumName();
    }

    private function AlbumExists(string $AlbumName): bool
    {
        foreach ($this->GetAlbums() as $album) {
            if ($album['Name'] === $AlbumName) {
                return true;
            }
        }

        return false;
    }

    private function GetConfiguredImages(string $AlbumName): array
    {
        foreach ($this->GetAlbums() as $album) {
            if ($album['Name'] !== $AlbumName) {
                continue;
            }

            $result = [];

            foreach ($album['Images'] as $image) {
                if (!is_array($image)) {
                    continue;
                }

                $mediaID = (int) ($image['MediaID'] ?? 0);

                if (!$this->IsImageMedia($mediaID)) {
                    continue;
                }

                $result[] = [
                    'MediaID' => $mediaID,
                    'Title' => (string) ($image['Title'] ?? ''),
                    'Description' => (string) ($image['Description'] ?? '')
                ];
            }

            return $result;
        }

        return [];
    }

    private function GetAlbumImages(string $AlbumName): array
    {
        if ($AlbumName === '') {
            return [];
        }

        $images = $this->GetConfiguredImages($AlbumName);
        $temporaryImages = $this->ReadJsonBuffer('TemporaryImages');

        if (isset($temporaryImages[$AlbumName]) && is_array($temporaryImages[$AlbumName])) {
            foreach ($temporaryImages[$AlbumName] as $image) {
                if (!is_array($image)) {
                    continue;
                }

                $mediaID = (int) ($image['MediaID'] ?? 0);

                if (!$this->IsImageMedia($mediaID)) {
                    continue;
                }

                $alreadyPresent = false;

                foreach ($images as $existingImage) {
                    if ((int) $existingImage['MediaID'] === $mediaID) {
                        $alreadyPresent = true;
                        break;
                    }
                }

                if (!$alreadyPresent) {
                    $images[] = [
                        'MediaID' => $mediaID,
                        'Title' => (string) ($image['Title'] ?? ''),
                        'Description' => (string) ($image['Description'] ?? '')
                    ];
                }
            }
        }

        $overrides = $this->ReadJsonBuffer('TextOverrides');

        foreach ($images as &$image) {
            $key = (string) $image['MediaID'];

            if (isset($overrides[$AlbumName][$key]) && is_array($overrides[$AlbumName][$key])) {
                $image['Title'] = (string) ($overrides[$AlbumName][$key]['Title'] ?? '');
                $image['Description'] = (string) ($overrides[$AlbumName][$key]['Description'] ?? '');
            }
        }

        unset($image);

        return $images;
    }

    private function ImageExistsInAlbum(string $AlbumName, int $MediaID): bool
    {
        foreach ($this->GetAlbumImages($AlbumName) as $image) {
            if ((int) $image['MediaID'] === $MediaID) {
                return true;
            }
        }

        return false;
    }

    private function IsImageMedia(int $MediaID): bool
    {
        if ($MediaID <= 0 || !IPS_MediaExists($MediaID)) {
            return false;
        }

        $media = IPS_GetMedia($MediaID);

        return $media['MediaType'] === MEDIATYPE_IMAGE;
    }

    private function GetImageDataURL(int $MediaID): string
    {
        if (!$this->IsImageMedia($MediaID)) {
            return '';
        }

        $base64 = IPS_GetMediaContent($MediaID);
        $binary = base64_decode($base64, true);

        if ($binary === false) {
            return '';
        }

        $imageInfo = @getimagesizefromstring($binary);

        if ($imageInfo === false || !isset($imageInfo['mime'])) {
            return '';
        }

        $mime = (string) $imageInfo['mime'];
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($mime, $allowedMimeTypes, true)) {
            return '';
        }

        return 'data:' . $mime . ';base64,' . $base64;
    }

    private function RefreshActiveAlbumPreservingImage(string $AlbumName): void
    {
        if ($this->GetActiveAlbumName() !== $AlbumName) {
            return;
        }

        $currentMediaID = (int) $this->GetBuffer('CurrentMediaID');

        if ($currentMediaID > 0) {
            $message = $this->GetFullUpdateMessageByMediaID($currentMediaID);
        } else {
            $message = $this->GetFullUpdateMessageByIndex(0);
        }

        $this->UpdateVisualizationValue($message);
    }

    private function ReadJsonBuffer(string $Name): array
    {
        $json = $this->GetBuffer($Name);

        if ($json === '') {
            return [];
        }

        $value = json_decode($json, true);

        return is_array($value) ? $value : [];
    }

    private function WriteJsonBuffer(string $Name, array $Value): void
    {
        $this->SetBuffer(
            $Name,
            json_encode($Value, JSON_UNESCAPED_UNICODE)
        );
    }

    private function RemoveTextOverride(string $AlbumName, int $MediaID): void
    {
        $overrides = $this->ReadJsonBuffer('TextOverrides');
        $key = (string) $MediaID;

        if (!isset($overrides[$AlbumName][$key])) {
            return;
        }

        unset($overrides[$AlbumName][$key]);

        if (isset($overrides[$AlbumName]) && count($overrides[$AlbumName]) === 0) {
            unset($overrides[$AlbumName]);
        }

        $this->WriteJsonBuffer('TextOverrides', $overrides);
    }
}
