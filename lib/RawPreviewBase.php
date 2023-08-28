<?php

namespace OCA\CameraRawPreviews;


use Exception;
use Imagick;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IImage;
use OCP\Image;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class RawPreviewBase
{
    protected $converter;
    protected $driver;
    protected $logger;
    protected $appName;
    protected $tmpFiles = [];

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
     * @param FileInfo $file
     * @return bool
     */
    public function isAvailable(FileInfo $file): bool
    {
        if (strtolower($file->getExtension()) === 'tiff' && !$this->isTiffCompatible()) {
            return false;
        }

        return $file->getSize() > 0;
    }

    protected function getThumbnailInternal(File $file, int $maxX, int $maxY): ?IImage
    {
        try {
            $localPath = $this->getLocalFile($file);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), ['app' => $this->appName, 'exception' => $e]);
            return null;
        }

        try {
            $tagData = $this->getBestPreviewTag($localPath);
            $previewTag = $tagData['tag'];


            if ($previewTag === 'SourceFile') {
                // load the original file as fallback when TIFF has no preview embedded
                $previewImageTmpPath = $localPath;
            } else {
                $previewImageTmpPath = sys_get_temp_dir() . '/' . md5($localPath . uniqid()) . '.' . $tagData['ext'];
                $this->tmpFiles[] = $previewImageTmpPath;

                //extract preview image using exiftool to file
                shell_exec($this->getConverter() . "  -ignoreMinorErrors -b -" . $previewTag . " " . $this->escapeShellArg($localPath) . ' > ' . $this->escapeShellArg($previewImageTmpPath));
                if (filesize($previewImageTmpPath) < 100) {
                    throw new Exception('Unable to extract valid preview data');
                }

                //update previewImageTmpPath  with orientation data
                shell_exec($this->getConverter() . ' -ignoreMinorErrors -TagsFromFile ' . $this->escapeShellArg($localPath) . ' -orientation -overwrite_original ' . $this->escapeShellArg($previewImageTmpPath));
            }

            $image = new Image;

            // we have checked for tiff support in getBestPreviewTag
            if ($tagData['ext'] === 'tiff') {
                $imagick = new Imagick($previewImageTmpPath);
                $imagick->autoOrient();
                $imagick->setImageFormat('jpg');
                $image->loadFromData($imagick->getImageBlob());
            } else {
                $image->loadFromFile($previewImageTmpPath);
            }

            $image->fixOrientation();
            $image->scaleDownToFit($maxX, $maxY);
            $this->cleanTmpFiles();

            //check if image object is valid
            if (!$image->valid()) {
                return null;
            }
            return $image;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), ['app' => $this->appName, 'exception' => $e]);

            $this->cleanTmpFiles();
            return null;
        }
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
        $useTempFile = $file->isEncrypted() || !$file->getStorage()->isLocal();
        if ($useTempFile) {
            $absPath = \OC::$server->getTempManager()->getTemporaryFile();
            $content = $file->fopen('r');
            file_put_contents($absPath, $content);
            $this->tmpFiles[] = $absPath;
            return $absPath;
        } else {
            return $file->getStorage()->getLocalFile($file->getInternalPath());
        }
    }

    /**
     * @param string $tmpPath
     * @return array
     * @throws Exception
     */
    private function getBestPreviewTag(string $tmpPath): array
    {

        $cmd = $this->getConverter() . " -json -preview:all -FileType " . $this->escapeShellArg($tmpPath);
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
        if ($fileType === 'TIFF' && $this->isTiffCompatible()) {
            return ['tag' => 'SourceFile', 'ext' => 'tiff'];
        }

        // extra logic for tiff previews
        foreach ($tiffTagsToCheck as $tag) {
            if (!isset($previewData[0][$tag])) {
                continue;
            }
            if (!$this->isTiffCompatible()) {
                throw new Exception('Needs imagick to extract TIFF previews');
            }
            return ['tag' => $tag, 'ext' => 'tiff'];
        }
        throw new Exception('Unable to find preview data: ' . $cmd . ' -> ' . $json);
    }

    /**
     * @return string
     * @throws Exception
     */
    private function getConverter()
    {
        if (!is_null($this->converter)) {
            return $this->converter;
        }

        $exifToolPath = realpath(__DIR__ . '/../vendor/exiftool/exiftool');

        if (strpos(php_uname("m"), 'x86') === 0 && php_uname("s") === "Linux") {
            // exiftool.bin is a static perl binary which looks up the exiftool script it self.
            $perlBin = $exifToolPath . '/exiftool.bin';
            $perlBinIsExecutable = is_executable($perlBin);

            if (!$perlBinIsExecutable && is_writable($perlBin)) {
                $perlBinIsExecutable = chmod($perlBin, 0744);
            }
            if ($perlBinIsExecutable) {
                $this->converter = $perlBin;
                return $this->converter;
            }
        }

        $exifToolScript = $exifToolPath . '/exiftool';

        $perlBin = \OC_Helper::findBinaryPath('perl');
        if (!is_null($perlBin)) {
            $this->converter = $perlBin . ' ' . $exifToolScript;
            return $this->converter;
        }

        $perlBin = exec("command -v perl");
        if (!empty($perlBin)) {
            $this->converter = $perlBin . ' ' . $exifToolScript;
            return $this->converter;
        }

        throw new Exception('No perl executable found. Camera Raw Previews app will not work.');
    }

    /**
     * @return bool
     */
    private function isTiffCompatible(): bool
    {
        return extension_loaded('imagick') && count(\Imagick::queryformats('TIFF')) > 0;
    }

    private function escapeShellArg($arg): string
    {
        return "'" . str_replace("'", "'\\''", $arg) . "'";
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
