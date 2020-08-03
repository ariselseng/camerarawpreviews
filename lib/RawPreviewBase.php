<?php

namespace OCA\CameraRawPreviews;

require __DIR__ . '/../vendor/autoload.php';

use Exception;
use Intervention\Image\ImageManagerStatic as Image;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IImage;
use OCP\ILogger;
use OCP\Image as OCP_Image;
use OCP\Lock\LockedException;

class RawPreviewBase
{
    const DRIVER_IMAGICK = 'imagick';
    const DRIVER_GD = 'gd';
    protected $converter;
    protected $driver;
    protected $logger;
    protected $appName;
    protected $perlFound = false;
    protected $tmpFiles = [];

    public function __construct(ILogger $logger, string $appName)
    {
        $this->logger = $logger;
        $this->appName = $appName;

        try {
            $perlBin = $this->getPerlExecutable();
            if (strpos($perlBin, 'exiftool/exiftool.bin') !== false) {
                $this->converter = $perlBin;
            } else {
                $this->converter = $perlBin . ' ' . realpath(__DIR__ . '/../vendor/exiftool/exiftool/exiftool');
            }
            $this->perlFound = true;
        } catch (Exception $e) {
            $this->logger->logException($e, ['app' => $this->appName]);
        }
    }

    /**
     * @return string
     */
    public function getMimeType()
    {
        return '/^((image\/x-dcraw)|(image\/x-indesign))(;+.*)*$/';
    }

    /**
     * @param $tmpPath
     * @return array
     * @throws Exception
     */
    protected function getBestPreviewTag($tmpPath)
    {
        $cmd = $this->converter . " -json -preview:all -FileType " . escapeshellarg($tmpPath);
        $json = shell_exec($cmd);
        // get all available previews and the file type
        $previewData = json_decode($json, true);
        $fileType = $previewData[0]['FileType'] ?? 'n/a';

        // potential tags in priority
        $tagsToCheck = [
            'JpgFromRaw',
            'PageImage',
            'PreviewImage',
            'OtherImage',
            'ThumbnailImage',
        ];

        // tiff tags that need extra checks
        $tiffTagsToCheck = [
            'PreviewTIFF',
            'ThumbnailTIFF'
        ];

        // return at first found tag
        foreach ($tagsToCheck as $tag) {
            if (!isset($previewData[0][$tag])) {
                continue;
            }
            return ['tag' => $tag, 'ext' => 'jpg'];
        }

        // we know we can handle TIFF files directly
        if ($fileType === 'TIFF' && $this->getDriver() === self::DRIVER_IMAGICK && count(\Imagick::queryFormats($fileType)) > 0) {
            return ['tag' => 'SourceTIFF', 'ext' => 'tiff'];
        }

        // extra logic for tiff previews
        $tiffTag = null;
        foreach ($tiffTagsToCheck as $tag) {
            if (!isset($previewData[0][$tag])) {
                continue;
            }
            if ($this->getDriver() !== self::DRIVER_IMAGICK || count(\Imagick::queryFormats('TIFF')) === 0) {
                throw new Exception('Needs imagick to extract TIFF previews');
            }
            return ['tag' => $tag, 'ext' => 'tiff'];
        }
        throw new Exception('Unable to find preview data: ' . $json);
    }

    /**
     * @return string
     */
    private function getDriver(): string
    {
        if (!is_null($this->driver)) {
            return $this->driver;
        }

        if (extension_loaded(self::DRIVER_IMAGICK) && count(\Imagick::queryformats('JPEG')) > 0) {
            $this->driver = self::DRIVER_IMAGICK;
        } else {
            $this->driver = self::DRIVER_GD;
        }
        return $this->driver;
    }

    /**
     * @return string
     * @throws Exception
     */
    private function getPerlExecutable()
    {
        if (strpos(php_uname("m"), 'x86') === 0 && php_uname("s") === "Linux") {
            $perlBin = realpath(__DIR__ . '/../vendor/exiftool/exiftool/exiftool.bin');
            $perlBinIsExecutable = is_executable($perlBin);

            if (!$perlBinIsExecutable && is_writable($perlBin)) {
                $perlBinIsExecutable = chmod($perlBin, 0744);
            }
            if ($perlBinIsExecutable) {
                return $perlBin;
            }
        }

        $perlBin = \OC_Helper::findBinaryPath('perl');
        if (!is_null($perlBin)) {
            return $perlBin;
        }

        $perlBin = exec("command -v perl");
        if (!empty($perlBin)) {
            return $perlBin;
        }

        if (!empty($perlBin)) {
            return $perlBin;
        }

        throw new Exception('No perl executable found. Camera Raw Previews app will not work.');
    }

    /**
     * @param $localPath
     * @param $maxX
     * @param $maxY
     * @return \Intervention\Image\Image
     * @throws Exception
     */
    protected function getResizedPreview($localPath, $maxX, $maxY)
    {
        $tagData = $this->getBestPreviewTag($localPath);
        $previewTag = $tagData['tag'];
        $previewImageTmpPath = sys_get_temp_dir() . '/' . md5($localPath . uniqid()) . '.' . $tagData['ext'];


        if ($previewTag === 'SourceTIFF') {
            // load the original file as fallback when TIFF has no preview embedded
            $previewImageTmpPath = $localPath;
        } else {
            $this->tmpFiles[] = $previewImageTmpPath;

            //extract preview image using exiftool to file
            shell_exec($this->converter . " -b -" . $previewTag . " " . escapeshellarg($localPath) . ' > ' . escapeshellarg($previewImageTmpPath));
            if (filesize($previewImageTmpPath) < 100) {
                throw new Exception('Unable to extract valid preview data');
            }

            //update previewImageTmpPath with orientation data
            shell_exec($this->converter . ' -TagsFromFile ' . escapeshellarg($localPath) . ' -orientation -overwrite_original ' . escapeshellarg($previewImageTmpPath));
        }

        Image::configure(['driver' => $this->getDriver()]);
        $im = Image::make($previewImageTmpPath);
        $im->orientate();
        $im->resize($maxX, $maxY, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });

        return $im->encode('jpg', 90);
    }

    /**
     * @param FileInfo $file
     * @return bool
     */
    public function isAvailable(FileInfo $file)
    {
        return $this->perlFound && $file->getSize() > 0;
    }

    protected function getThumbnailInternal(File $file, int $maxX, int $maxY): ?IImage
    {
        try {
            $tmpPath = $this->getLocalFile($file);
        } catch (Exception $e) {
            $this->logger->logException($e, ['app' => $this->appName]);
            return null;
        }

        try {
            $preview = $this->getResizedPreview($tmpPath, $maxX, $maxY);
            $image = new OCP_Image();
            $image->loadFromData($preview);
            $this->cleanTmpFiles();

            //check if image object is valid
            if (!$image->valid()) {
                return null;
            }
            return $image;
        } catch (Exception $e) {
            $this->logger->logException($e, ['app' => $this->appName]);

            $this->cleanTmpFiles();
            return null;
        }
    }

    /**
     * Get a path to either the local file or temporary file
     *
     * @param File $file
     * @param int $maxSize maximum size for temporary files
     * @return string
     * @throws LockedException
     * @throws NotPermittedException
     * @throws NotFoundException
     */
    protected function getLocalFile(File $file, int $maxSize = null): string
    {
        $useTempFile = $file->isEncrypted() || !$file->getStorage()->isLocal();
        if ($useTempFile) {
            $absPath = \OC::$server->getTempManager()->getTemporaryFile();

            $content = $file->fopen('r');

            if ($maxSize) {
                $content = stream_get_contents($content, $maxSize);
            }

            file_put_contents($absPath, $content);
            $this->tmpFiles[] = $absPath;
            return $absPath;
        } else {
            return $file->getStorage()->getLocalFile($file->getInternalPath());
        }
    }

    /**
     * Clean any generated temporary files
     */
    protected function cleanTmpFiles()
    {
        foreach ($this->tmpFiles as $tmpFile) {
            unlink($tmpFile);
        }

        $this->tmpFiles = [];
    }
}
