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
    protected $converter;
    protected $driver = 'gd';
    protected $logger;
    protected $appName;
    protected $perlFound = false;
    protected $tmpFiles = [];

    public function __construct(ILogger $logger, string $appName)
    {
        $this->logger = $logger;
        $this->appName = $appName;

        if (extension_loaded('imagick') && count(\Imagick::queryformats('JPEG')) > 0) {
            $this->driver = 'imagick';
        }
        Image::configure(array('driver' => $this->driver));

        try {
            $perlBin = $this->getPerlExecutable();
            $this->converter = $perlBin . ' ' . realpath(__DIR__ . '/../vendor/exiftool/exiftool/exiftool');
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
     * @return string
     * @throws Exception
     */
    protected function getBestPreviewTag($tmpPath)
    {
        // get all available previews and the file type
        $previewData = json_decode(shell_exec($this->converter . " -json -preview:all -FileType " . escapeshellarg($tmpPath)), true);
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
            return $tag;
        }

        // we know we can handle TIFF files directly
        if ($fileType === 'TIFF' && $this->driver === 'imagick' && count(\Imagick::queryFormats($fileType)) > 0) {
            return 'SourceTIFF';
        }

        // extra logic for tiff previews
        $tiffTag = null;
        foreach ($tiffTagsToCheck as $tag) {
            if (!isset($previewData[0][$tag])) {
                continue;
            }
            if ($this->driver !== 'imagick' || count(\Imagick::queryFormats('TIFF')) === 0) {
                throw new Exception('Needs imagick to extract TIFF previews');
            }
            return $tag;
        }
        throw new Exception('Unable to find preview data: debug ' . json_encode($previewData));
    }

    /**
     * @return string
     * @throws Exception
     */
    private function getPerlExecutable()
    {
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
        $previewTag = $this->getBestPreviewTag($localPath);
        $previewImageTmpPath = sys_get_temp_dir() . '/' . md5($localPath . uniqid()) . '.jpg';

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
