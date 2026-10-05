<?php

namespace OCA\CameraRawPreviews;


use Exception;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IImage;
use OCP\Image;
use OCP\ITempManager;
use OCP\Lock\LockedException;
use OCP\Server;
use Psr\Log\LoggerInterface;

class RawPreviewBase
{
    protected LoggerInterface $logger;
    protected string $appName;
    protected array $tmpFiles = [];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->appName = 'camerarawpreviews';
    }

    /**
     * @return string
     */
    public function getMimeType(): string
    {
        return '/^((image\/x-dcraw)|(image\/x-indesign))(;+.*)*$/';
    }

    /**
     * Report whether this provider may be able to produce a preview for $file.
     *
     * Only cheap guards run here (non-empty, and Imagick present for plain
     * TIFFs); the file is never read. getThumbnail() does the real work and
     * returns null if no preview can be extracted.
     *
     * @param FileInfo $file
     * @return bool
     */
    public function isAvailable(FileInfo $file): bool
    {
        if ($file->getSize() <= 0) {
            return false;
        }

        if (strtolower($file->getExtension()) === 'tiff' && !$this->isTiffCompatible()) {
            return false;
        }

        return true;
    }

    protected function getThumbnailInternal(File $file, int $maxX, int $maxY): ?IImage
    {
        try {
            $localPath = $this->getLocalFile($file);
        } catch (Exception $e) {
            $this->logger->warning($e->getMessage(), ['app' => $this->appName, 'exception' => $e]);
            return null;
        }

        try {
            // PreviewExtractor handles every supported format (embedded-JPEG RAW,
            // InDesign, Minolta MRW, and the Imagick TIFF fallback) and returns
            // an upright JPEG — orientation is already baked in for us — so the
            // provider only has to write, load and scale a single image.
            $jpegData = PreviewExtractor::extractPreview($localPath);
            if ($jpegData === null) {
                throw new Exception('Unable to extract valid preview data from RAW file');
            }

            // Save extracted JPEG to a temporary file
            $previewImageTmpPath = sys_get_temp_dir() . '/' . md5($localPath . uniqid()) . '.jpg';
            $this->tmpFiles[] = $previewImageTmpPath;
            file_put_contents($previewImageTmpPath, $jpegData);

            $image = new Image;
            $image->loadFromFile($previewImageTmpPath);
            $image->scaleDownToFit($maxX, $maxY);
            $this->cleanTmpFiles();

            //check if image object is valid
            if (!$image->valid()) {
                return null;
            }
            return $image;
        } catch (Exception $e) {
            $this->logger->warning($e->getMessage(), ['app' => $this->appName, 'exception' => $e]);

            $this->cleanTmpFiles();
            return null;
        }
    }

    /**
     * Return a readable local path for $file when it can be obtained without
     * copying — i.e. on local, unencrypted storage. Returns null otherwise, in
     * which case the caller has to copy the contents to a temp file.
     *
     * @param FileInfo $file
     * @return string|null
     */
    private function getLocalPathIfCheap(FileInfo $file): ?string
    {
        if ($file->isEncrypted() || !$file->getStorage()->isLocal()) {
            return null;
        }
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        return (is_string($path) && $path !== '' && is_file($path)) ? $path : null;
    }

    /**
     * Get a path to either the local file or temporary file
     *
     * @param File $file
     * @return string
     * @throws LockedException
     * @throws NotFoundException
     * @throws NotPermittedException
     */
    private function getLocalFile(File $file): string
    {
        $localPath = $this->getLocalPathIfCheap($file);
        if ($localPath !== null) {
            return $localPath;
        }

        // Encrypted or non-local storage: copy the contents to a temp file.
        $absPath = Server::get(ITempManager::class)->getTemporaryFile();
        $content = $file->fopen('r');
        file_put_contents($absPath, $content);
        $this->tmpFiles[] = $absPath;
        return $absPath;
    }

    /**
     * @return bool
     */
    private function isTiffCompatible(): bool
    {
        return extension_loaded('imagick') && count(\Imagick::queryformats('TIFF')) > 0;
    }

    /**
     * Clean any generated temporary files
     */
    private function cleanTmpFiles()
    {
        foreach ($this->tmpFiles as $tmpFile) {
            unlink($tmpFile);
        }

        $this->tmpFiles = [];
    }
}
