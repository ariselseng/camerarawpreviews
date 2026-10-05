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

        $extension = strtolower($file->getExtension());
        if (($extension === 'tif' || $extension === 'tiff') && !$this->isTiffCompatible()) {
            return false;
        }

        return true;
    }

    protected function getThumbnailInternal(File $file, int $maxX, int $maxY): ?IImage
    {
        try {
            $localPath = $this->getLocalFile($file);

            // PreviewExtractor handles every supported format (embedded-JPEG RAW,
            // InDesign, Minolta MRW, and the Imagick TIFF fallback) and returns
            // an upright JPEG — orientation is already baked in for us — so the
            // provider only has to load and scale a single image.
            $jpegData = PreviewExtractor::extractPreview($localPath);
            if ($jpegData === null) {
                throw new Exception('Unable to extract valid preview data from RAW file');
            }

            $image = new Image;
            $image->loadFromData($jpegData);
            if (!$image->valid()) {
                return null;
            }
            $image->scaleDownToFit($maxX, $maxY);
            return $image;
        } catch (\Throwable $e) {
            // Also catches PHP errors (TypeError, ValueError, …) that a corrupt
            // file can trigger, so the request still gets a plain "no preview".
            $this->logger->warning($e->getMessage(), ['app' => $this->appName, 'exception' => $e]);
            return null;
        } finally {
            $this->cleanTmpFiles();
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
        if ($absPath === false) {
            throw new Exception('Unable to create a temporary file');
        }
        $this->tmpFiles[] = $absPath;

        $stream = $file->fopen('r');
        if ($stream === false) {
            throw new Exception('Unable to open ' . $file->getName());
        }
        try {
            $written = file_put_contents($absPath, $stream);
        } finally {
            fclose($stream);
        }
        if ($written === false || $written < $file->getSize()) {
            throw new Exception('Unable to copy ' . $file->getName() . ' to a temporary file');
        }
        return $absPath;
    }

    /**
     * @return bool
     */
    protected function isTiffCompatible(): bool
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
