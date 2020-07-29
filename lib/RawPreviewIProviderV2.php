<?php

namespace OCA\CameraRawPreviews;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Preview\IProviderV2;
use OCP\Preview\IProvider2;

class RawPreviewIProviderV2 extends RawPreviewBase implements IProviderV2
{
    public function getMimeType(): string
    {
        return parent::getMimeType();
    }

    public function isAvailable(FileInfo $file): bool
    {
        return parent::isAvailable($file);
    }

    public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage
    {
        return $this->getThumbnailInternal($file, $maxX, $maxY);
    }
}