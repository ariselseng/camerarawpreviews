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

    /**
     * One-entry cache so the extraction done by {@see isAvailable()} can be
     * reused by {@see getThumbnailInternal()} for the same local file, instead
     * of decoding/rendering the preview twice. Keyed by local path.
     */
    private ?string $cachedPath = null;
    private ?string $cachedJpeg = null;

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
     * Report whether this provider can actually produce a preview for $file.
     *
     * Beyond the cheap guards (non-empty, and Imagick present for plain TIFFs),
     * we genuinely try to extract a preview so Nextcloud is not told a preview
     * exists only for getThumbnail() to later return null. The extraction is
     * cached and reused by getThumbnailInternal(), so this costs nothing extra.
     *
     * The deep check only runs when the bytes are reachable cheaply (local,
     * unencrypted storage). For external/encrypted storage we avoid downloading
     * the file just to probe it and optimistically report available, letting
     * getThumbnail() do the real work.
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
            $jpegData = $this->extractPreviewCached($localPath);
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
     * Extract a preview JPEG, reusing the last result if the same local path is
     * requested again (e.g. isAvailable() then getThumbnail() on one file).
     *
     * @param string $localPath
     * @return string|null JPEG bytes, or null if no preview could be produced.
     */
    private function extractPreviewCached(string $localPath): ?string
    {
        if ($this->cachedPath !== $localPath) {
            $this->cachedPath = $localPath;
            $this->cachedJpeg = PreviewExtractor::extractPreview($localPath);
        }
        return $this->cachedJpeg;
    }

    /**
     * Return a readable local path for $file when it can be obtained without
     * copying — i.e. on local, unencrypted storage. Returns null otherwise (no
     * temp file is created and no state is mutated), so isAvailable() can probe
     * cheaply and skip the deep check for external/encrypted storage.
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
