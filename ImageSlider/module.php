<?php

declare(strict_types=1);

class ImageSlider extends IPSModuleStrict
{
    private const MODULE_ID = '{78DAB19A-3A86-2817-5E7C-F4A8647D7D64}';

    // Settings copied to instances created from legacy albums.
    private const SHARED_PROPERTIES = [
        'Transition',
        'TransitionDuration',
        'ShowCaptionsByDefault',
        'ShowNavigationArrows',
        'ShowImageCounter',
        'ShowZoomButtons'
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Images', '[]');
        $this->RegisterPropertyString('Transition', 'Fade');
        $this->RegisterPropertyInteger('TransitionDuration', 300);
        $this->RegisterPropertyBoolean('ShowCaptionsByDefault', true);
        $this->RegisterPropertyBoolean('ShowNavigationArrows', true);
        $this->RegisterPropertyBoolean('ShowImageCounter', true);
        $this->RegisterPropertyBoolean('ShowZoomButtons', true);

        // Up to 2.x only. After migration it holds the albums that still wait for
        // "Create as new instance" or "Discard".
        $this->RegisterPropertyString('Albums', '[]');
        $this->RegisterAttributeBoolean('AlbumsMigrated', false);

        $this->RegisterAttributeString('HookToken', '');

        $this->SetBuffer('TemporaryImages', '[]');
        $this->SetBuffer('TextOverrides', '{}');

        $this->SetVisualizationType($this->VisualizationType());
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType($this->VisualizationType());

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
        } elseif ($this->MigrateAlbums()) {
            return;
        }

        if ($this->ReadAttributeString('HookToken') === '') {
            $this->WriteAttributeString('HookToken', bin2hex(random_bytes(16)));
        }

        $hookRegistered = $this->RegisterHook($this->GetHookAddress());
        $this->SendDebug('Hook', sprintf('RegisterHook(%s): %s', $this->GetHookAddress(), $hookRegistered ? 'true' : 'false'), 0);

        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        foreach ($this->GetConfiguredImageEntries() as $image) {
            if ($image['MediaID'] > 0) {
                $this->RegisterReference($image['MediaID']);
            }
        }

        $this->RefreshState();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;

            case MM_UPDATE:
            case MM_CHANGEFILE:
                $this->SendDebug('MessageSink', sprintf('Media %d changed', $SenderID), 0);
                $this->SendMeta();
                break;

            case MM_DELETE:
                $this->SendDebug('MessageSink', sprintf('Media %d deleted', $SenderID), 0);
                $this->RemoveTemporaryImage($SenderID);
                $this->RefreshState();
                break;
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);

        if (!is_array($form)) {
            return '{}';
        }

        $error = $this->ValidateConfiguration() ?? '';
        $legacyAlbums = $this->GetLegacyAlbums();

        foreach ($form['elements'] as &$element) {
            switch ($element['name'] ?? '') {
                case 'ConfigurationError':
                    $element['caption'] = $error;
                    $element['visible'] = ($error !== '');
                    break;

                case 'LegacyAlbums':
                    $element['visible'] = count($legacyAlbums) > 0;
                    $element['items'] = $this->GetLegacyAlbumFormItems($legacyAlbums);
                    break;
            }
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

        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        return str_replace(
            '</body>',
            '<script>window.imageSliderStorageKey = ' . json_encode('ImageSlider_' . $this->InstanceID, $flags) . ';handleMessage(' . json_encode($this->BuildMeta(), $flags) . ');</script></body>',
            $html
        );
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'CreateInstanceFromAlbum':
                $this->CreateInstanceFromAlbum((string) $Value);
                break;

            case 'DiscardLegacyAlbums':
                IPS_SetProperty($this->InstanceID, 'Albums', '[]');
                IPS_ApplyChanges($this->InstanceID);
                $this->ReloadForm();
                break;

            default:
                throw new Exception($this->Translate('Invalid Ident: ') . $Ident);
        }
    }

    public function AddImage(int $MediaID): bool
    {
        if (!$this->IsImageMedia($MediaID)) {
            $this->SendDebug('AddImage', sprintf('Invalid image MediaID: %d', $MediaID), 0);
            return false;
        }

        if ($this->ImageExists($MediaID)) {
            $this->SendDebug('AddImage', sprintf('Image %d already exists', $MediaID), 0);
            return false;
        }

        $temporaryImages = $this->ReadJsonBuffer('TemporaryImages');
        $temporaryImages[] = ['MediaID' => $MediaID, 'Title' => '', 'Description' => ''];
        $this->WriteJsonBuffer('TemporaryImages', $temporaryImages);

        $this->SendDebug('AddImage', sprintf('Added temporary image %d', $MediaID), 0);
        $this->RefreshState();

        return true;
    }

    public function RemoveImage(int $MediaID): bool
    {
        if (!$this->RemoveTemporaryImage($MediaID)) {
            $this->SendDebug('RemoveImage', sprintf('Temporary image %d not found', $MediaID), 0);
            return false;
        }

        $this->SendDebug('RemoveImage', sprintf('Removed temporary image %d', $MediaID), 0);
        $this->RefreshState();

        return true;
    }

    public function ClearImages(): bool
    {
        $temporaryImages = $this->ReadJsonBuffer('TemporaryImages');

        foreach ($temporaryImages as $image) {
            $this->RemoveTextOverride((int) ($image['MediaID'] ?? 0));
        }

        $this->WriteJsonBuffer('TemporaryImages', []);
        $this->SendDebug('ClearImages', sprintf('Cleared %d temporary image(s)', count($temporaryImages)), 0);
        $this->RefreshState();

        return true;
    }

    public function SetImageText(int $MediaID, string $Title, string $Description): bool
    {
        if (!$this->ImageExists($MediaID)) {
            $this->SendDebug('SetImageText', sprintf('Image not found: %d', $MediaID), 0);
            return false;
        }

        $overrides = $this->ReadJsonBuffer('TextOverrides');
        $overrides[(string) $MediaID] = ['Title' => $Title, 'Description' => $Description];
        $this->WriteJsonBuffer('TextOverrides', $overrides);

        $this->SendDebug('SetImageText', sprintf('Updated temporary text for image %d', $MediaID), 0);
        $this->SendMeta();

        return true;
    }

    protected function ProcessHookData(): void
    {
        $token = (string) ($_GET['t'] ?? '');
        $expectedToken = $this->ReadAttributeString('HookToken');

        if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
            $this->SendDebug('Hook', 'Rejected request with invalid token', 0);
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $mediaID = (int) ($_GET['media'] ?? 0);

        // Only images shown by this instance are served, never arbitrary media objects.
        if (!$this->ImageExists($mediaID)) {
            $this->SendDebug('Hook', sprintf('Rejected request for MediaID %d', $mediaID), 0);
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $binary = base64_decode(IPS_GetMediaContent($mediaID), true);
        $imageInfo = $binary === false ? false : @getimagesizefromstring($binary);

        if ($binary === false || $imageInfo === false || !isset($imageInfo['mime'])) {
            $this->SendDebug('Hook', sprintf('MediaID %d has no readable image content', $mediaID), 0);
            http_response_code(415);
            echo 'Unsupported media';
            return;
        }

        // The URL contains the media version, so a changed image gets a new URL.
        header('Content-Type: ' . $imageInfo['mime']);
        header('Content-Length: ' . strlen($binary));
        header('Cache-Control: private, max-age=31536000, immutable');
        echo $binary;
    }

    private function VisualizationType(): int
    {
        // Fullscreen HTML tiles (type 2) exist from 9.1; older kernels use the normal HTML SDK (type 1).
        return version_compare(IPS_GetKernelVersion(), '9.1', '>=') ? 2 : 1;
    }

    private function RefreshState(): void
    {
        $error = $this->ValidateConfiguration();

        if ($error !== null) {
            $this->SendDebug('Configuration', $error, 0);
        }

        $this->SetStatus($error === null ? 102 : 201);
        $this->UpdateFormField('ConfigurationError', 'caption', $error ?? '');
        $this->UpdateFormField('ConfigurationError', 'visible', $error !== null);

        foreach ($this->GetMessageList() as $senderID => $messages) {
            if ($senderID === 0) {
                continue;
            }

            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        foreach ($this->GetImages() as $image) {
            $this->RegisterMessage($image['MediaID'], MM_UPDATE);
            $this->RegisterMessage($image['MediaID'], MM_CHANGEFILE);
            $this->RegisterMessage($image['MediaID'], MM_DELETE);
        }

        $this->SendMeta();
    }

    private function SendMeta(): void
    {
        $json = json_encode($this->BuildMeta(), JSON_UNESCAPED_UNICODE);
        $this->UpdateVisualizationValue($json !== false ? $json : '{}');
    }

    private function BuildMeta(): array
    {
        $transition = $this->ReadPropertyString('Transition');

        if (!in_array($transition, ['None', 'Fade', 'Slide'], true)) {
            $transition = 'Fade';
        }

        $images = [];

        foreach ($this->GetImages() as $image) {
            $images[] = [
                'id' => $image['MediaID'],
                'url' => $this->GetImageURL($image['MediaID']),
                'title' => $image['Title'],
                'description' => $image['Description']
            ];
        }

        return [
            'images' => $images,
            'transition' => $transition,
            'transitionDuration' => max(0, min(5000, $this->ReadPropertyInteger('TransitionDuration'))),
            'showCaptionsByDefault' => $this->ReadPropertyBoolean('ShowCaptionsByDefault'),
            'showNavigationArrows' => $this->ReadPropertyBoolean('ShowNavigationArrows'),
            'showImageCounter' => $this->ReadPropertyBoolean('ShowImageCounter'),
            'showZoomButtons' => $this->ReadPropertyBoolean('ShowZoomButtons'),
            'labels' => [
                'showCaptions' => $this->Translate('Show image captions'),
                'hideCaptions' => $this->Translate('Hide image captions'),
                'empty' => $this->Translate('No images available'),
                'unavailable' => $this->Translate('Image cannot be displayed'),
                'gallery' => $this->Translate('Image gallery'),
                'previous' => $this->Translate('Previous image'),
                'next' => $this->Translate('Next image'),
                'zoomIn' => $this->Translate('Zoom in'),
                'zoomOut' => $this->Translate('Zoom out')
            ]
        ];
    }

    private function ValidateConfiguration(): ?string
    {
        $seenMediaIDs = [];

        foreach ($this->GetConfiguredImageEntries() as $index => $image) {
            $mediaID = $image['MediaID'];

            if ($mediaID <= 0) {
                return sprintf(
                    $this->Translate('Image %d: No media object is selected. Please select an image or remove the empty entry.'),
                    $index + 1
                );
            }

            if (isset($seenMediaIDs[$mediaID])) {
                return sprintf(
                    $this->Translate('MediaID %d is used more than once. Each image may only occur once.'),
                    $mediaID
                );
            }

            $seenMediaIDs[$mediaID] = true;

            if (!IPS_MediaExists($mediaID)) {
                return sprintf(
                    $this->Translate('MediaID %d does not exist. Please select an existing image media object.'),
                    $mediaID
                );
            }

            if (IPS_GetMedia($mediaID)['MediaType'] !== MEDIATYPE_IMAGE) {
                return sprintf(
                    $this->Translate('MediaID %d is not an image media object.'),
                    $mediaID
                );
            }
        }

        return null;
    }

    private function GetConfiguredImageEntries(): array
    {
        return $this->NormalizeImages(json_decode($this->ReadPropertyString('Images'), true));
    }

    private function NormalizeImages(mixed $images): array
    {
        // Lists nested in a list column may arrive as JSON string.
        if (is_string($images)) {
            $images = json_decode($images, true);
        }

        if (!is_array($images)) {
            return [];
        }

        $result = [];

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $result[] = [
                'MediaID' => (int) ($image['MediaID'] ?? 0),
                'Title' => (string) ($image['Title'] ?? ''),
                'Description' => (string) ($image['Description'] ?? '')
            ];
        }

        return $result;
    }

    // Configured images followed by temporary images, with temporary texts applied.
    private function GetImages(): array
    {
        $images = [];

        foreach (array_merge($this->GetConfiguredImageEntries(), $this->NormalizeImages($this->ReadJsonBuffer('TemporaryImages'))) as $image) {
            if (isset($images[$image['MediaID']]) || !$this->IsImageMedia($image['MediaID'])) {
                continue;
            }

            $images[$image['MediaID']] = $image;
        }

        $overrides = $this->ReadJsonBuffer('TextOverrides');

        foreach ($images as $mediaID => &$image) {
            if (isset($overrides[(string) $mediaID]) && is_array($overrides[(string) $mediaID])) {
                $image['Title'] = (string) ($overrides[(string) $mediaID]['Title'] ?? '');
                $image['Description'] = (string) ($overrides[(string) $mediaID]['Description'] ?? '');
            }
        }
        unset($image);

        return array_values($images);
    }

    private function ImageExists(int $MediaID): bool
    {
        foreach ($this->GetImages() as $image) {
            if ($image['MediaID'] === $MediaID) {
                return true;
            }
        }

        return false;
    }

    private function IsImageMedia(int $MediaID): bool
    {
        return $MediaID > 0
            && IPS_MediaExists($MediaID)
            && IPS_GetMedia($MediaID)['MediaType'] === MEDIATYPE_IMAGE;
    }

    private function RemoveTemporaryImage(int $MediaID): bool
    {
        $temporaryImages = $this->ReadJsonBuffer('TemporaryImages');
        $remaining = array_values(array_filter(
            $temporaryImages,
            fn ($image) => (int) ($image['MediaID'] ?? 0) !== $MediaID
        ));

        if (count($remaining) === count($temporaryImages)) {
            return false;
        }

        $this->WriteJsonBuffer('TemporaryImages', $remaining);
        $this->RemoveTextOverride($MediaID);

        return true;
    }

    private function RemoveTextOverride(int $MediaID): void
    {
        $overrides = $this->ReadJsonBuffer('TextOverrides');

        if (!isset($overrides[(string) $MediaID])) {
            return;
        }

        unset($overrides[(string) $MediaID]);
        $this->WriteJsonBuffer('TextOverrides', $overrides);
    }

    private function GetHookAddress(): string
    {
        // RegisterHook() expects the address without the /hook/ prefix.
        return 'imageslider-' . $this->InstanceID;
    }

    private function GetImageURL(int $MediaID): string
    {
        return sprintf(
            '/hook/%s?media=%d&v=%d&t=%s',
            $this->GetHookAddress(),
            $MediaID,
            (int) (IPS_GetMedia($MediaID)['MediaUpdated'] ?? 0),
            $this->ReadAttributeString('HookToken')
        );
    }

    // One-time migration from 2.x: the first album becomes the image list,
    // further albums remain in "Albums" until the user creates instances or discards them.
    private function MigrateAlbums(): bool
    {
        if ($this->ReadAttributeBoolean('AlbumsMigrated')) {
            return false;
        }

        $this->WriteAttributeBoolean('AlbumsMigrated', true);
        $albums = $this->GetLegacyAlbums();

        if (count($albums) === 0) {
            return false;
        }

        $this->SendDebug('Migration', sprintf('Migrating %d album(s) from version 2', count($albums)), 0);

        if (count($albums) > 1) {
            $this->LogMessage(
                sprintf(
                    $this->Translate('Image Slider %d: Multiple albums are no longer supported. Album "%s" was taken over. Further albums can be created as separate instances in the configuration form.'),
                    $this->InstanceID,
                    $albums[0]['Name']
                ),
                KL_WARNING
            );
        }

        if (count($this->GetConfiguredImageEntries()) === 0) {
            IPS_SetProperty($this->InstanceID, 'Images', (string) json_encode($albums[0]['Images']));
        }

        IPS_SetProperty($this->InstanceID, 'Albums', (string) json_encode(array_slice($albums, 1)));
        IPS_ApplyChanges($this->InstanceID);

        return true;
    }

    private function GetLegacyAlbums(): array
    {
        $albums = json_decode($this->ReadPropertyString('Albums'), true);

        if (!is_array($albums)) {
            return [];
        }

        $result = [];

        foreach ($albums as $album) {
            if (!is_array($album)) {
                continue;
            }

            $result[] = [
                'Name' => trim((string) ($album['Name'] ?? '')),
                'Images' => $this->NormalizeImages($album['Images'] ?? [])
            ];
        }

        return $result;
    }

    private function GetLegacyAlbumFormItems(array $albums): array
    {
        $items = [
            [
                'type' => 'Label',
                'caption' => $this->Translate('This instance contained further albums. Multiple albums are no longer supported. Each album can be created as a separate instance; it is placed next to this instance and takes over its display settings.')
            ]
        ];

        foreach ($albums as $index => $album) {
            $name = $album['Name'] !== '' ? $album['Name'] : sprintf($this->Translate('Album %d'), $index + 2);
            $items[] = [
                'type' => 'RowLayout',
                'items' => [
                    [
                        'type' => 'Label',
                        'width' => '400px',
                        'caption' => sprintf($this->Translate('%s (%d images)'), $name, count($album['Images']))
                    ],
                    [
                        'type' => 'Button',
                        'caption' => 'Create as new instance',
                        'onClick' => 'IPS_RequestAction($id, \'CreateInstanceFromAlbum\', ' . var_export((string) $index, true) . ');'
                    ]
                ]
            ];
        }

        $items[] = [
            'type' => 'Button',
            'caption' => 'Discard old albums',
            'confirm' => 'The remaining albums will be deleted permanently. Continue?',
            'onClick' => 'IPS_RequestAction($id, \'DiscardLegacyAlbums\', \'\');'
        ];

        return $items;
    }

    private function CreateInstanceFromAlbum(string $Index): void
    {
        $albums = $this->GetLegacyAlbums();
        $index = (int) $Index;

        // Output here would appear as an error popup in the form, so results go to the debug log.
        if (!ctype_digit($Index) || !isset($albums[$index])) {
            $this->SendDebug('Migration', sprintf('Album %s no longer exists', $Index), 0);
            $this->ReloadForm();
            return;
        }

        $album = $albums[$index];
        $instanceID = IPS_CreateInstance(self::MODULE_ID);
        IPS_SetName($instanceID, $album['Name'] !== '' ? $album['Name'] : IPS_GetName($this->InstanceID));
        IPS_SetParent($instanceID, IPS_GetParent($this->InstanceID));
        IPS_SetProperty($instanceID, 'Images', (string) json_encode($album['Images']));

        foreach (self::SHARED_PROPERTIES as $property) {
            IPS_SetProperty($instanceID, $property, IPS_GetProperty($this->InstanceID, $property));
        }

        IPS_ApplyChanges($instanceID);

        array_splice($albums, $index, 1);
        IPS_SetProperty($this->InstanceID, 'Albums', (string) json_encode($albums));
        IPS_ApplyChanges($this->InstanceID);
        $this->ReloadForm();

        $this->SendDebug('Migration', sprintf('Created instance %d "%s"', $instanceID, IPS_GetName($instanceID)), 0);
    }

    private function ReadJsonBuffer(string $Name): array
    {
        $value = json_decode($this->GetBuffer($Name), true);

        return is_array($value) ? $value : [];
    }

    private function WriteJsonBuffer(string $Name, array $Value): void
    {
        $this->SetBuffer($Name, (string) json_encode($Value, JSON_UNESCAPED_UNICODE));
    }
}
