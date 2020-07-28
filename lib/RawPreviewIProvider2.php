<?php

namespace OCA\CameraRawPreviews;

use OCP\Files\File;
use OCP\Preview\IProvider2;

class RawPreviewIProvider2 extends RawPreviewBase implements IProvider2
{
    /**
     * {@inheritDoc}
     */
    public function getThumbnail(File $file, $maxX, $maxY, $scalingUp)
    {
        return $this->getThumbnailInternal($file, $maxX, $maxY) ?? false;
    }
}

