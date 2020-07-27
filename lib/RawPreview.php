<?php

namespace OCA\CameraRawPreviews;

require __DIR__ . '/../vendor/autoload.php';

use Intervention\Image\ImageManagerStatic as Image;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\ILogger;
use OCP\Image as OCP_Image;
use OCP\Preview\IProvider;
use OCP\Preview\IProvider2;

class RawPreviewBase
{
    protected $converter;
    protected $driver = 'gd';
    protected $logger;
    protected $appName;
    protected $perlFound = false;

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
        } catch (\Exception $e) {
            $this->logger->logException($e, ['app' => $this->appName]);
        }
    }

    public function getMimeType()
    {
        return '/^((image\/x-dcraw)|(image\/x-indesign))(;+.*)*$/';
    }

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
                throw new \Exception('Needs imagick to extract TIFF previews');
            }
            return $tag;
        }

        throw new \Exception('Unable to find preview data');
    }

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

        throw new \Exception('No perl executable found. Camera Raw Previews app will not work.');
    }

    protected function getResizedPreview($tmpPath, $maxX, $maxY)
    {
        $previewTag = $this->getBestPreviewTag($tmpPath);

        //tmp
        $previewImageTmpPath = dirname($tmpPath) . '/' . md5($tmpPath . uniqid()) . '.jpg';

        if ($previewTag === 'SourceTIFF') {
            // load the original file as fallback when TIFF has no preview embedded
            $previewImageTmpPath = $tmpPath;
        } else {
            //extract preview image using exiftool to file
            shell_exec($this->converter . " -b -" . $previewTag . " " . escapeshellarg($tmpPath) . ' > ' . escapeshellarg($previewImageTmpPath));
            if (filesize($previewImageTmpPath) < 100) {
                unlink($previewImageTmpPath);
                throw new \Exception('Unable to extract valid preview data');
            }

            //update previewImageTmpPath with orientation data
            shell_exec($this->converter . ' -TagsFromFile ' . escapeshellarg($tmpPath) . ' -orientation -overwrite_original ' . escapeshellarg($previewImageTmpPath));
        }

        $im = Image::make($previewImageTmpPath);
        $im->orientate();
        $im->resize($maxX, $maxY, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        if ($previewTag !== 'SourceTIFF') {
            unlink($previewImageTmpPath);
        }
        return $im->encode('jpg', 90);
    }

    public function isAvailable(FileInfo $file)
    {
        return $this->perlFound && $file->getSize() > 0;
    }
}

if (interface_exists('\OCP\Preview\IProvider2')) {
    class RawPreview extends RawPreviewBase implements IProvider2
    {
        /**
         * {@inheritDoc}
         */
        public function getThumbnail(File $file, $maxX, $maxY, $scalingUp)
        {
            $file_resource = $file->fopen('r');
            if (!is_resource($file_resource)) {
                return false;
            }
            $tmp_resource = tmpfile();
            if (!is_resource($tmp_resource)) {
                return false;
            }
            stream_copy_to_stream($file_resource, $tmp_resource);
            fclose($file_resource);
            $tmpPath = stream_get_meta_data($tmp_resource)['uri'];

            try {
                $im = $this::getResizedPreview($tmpPath, $maxX, $maxY);
            } catch (\Exception $e) {
                return false;
            } finally {
                fclose($tmp_resource);
            }
            $image = new OCP_Image();
            $image->loadFromData($im);

            // //check if image object is valid
            return $image->valid() ? $image : false;
        }
    }
} else {
    class RawPreview extends RawPreviewBase implements IProvider
    {
        /**
         * {@inheritDoc}
         */
        public function getThumbnail($path, $maxX, $maxY, $scalingup, $fileview)
        {
            $tmpPath = $fileview->toTmpFile($path);
            if (!$tmpPath) {
                return false;
            }

            try {
                $im = $this->getResizedPreview($tmpPath, $maxX, $maxY);
            } catch (\Exception $e) {
                return false;
            } finally {
                unlink($tmpPath);
            }
            $image = new OCP_Image();
            $image->loadFromData($im);

            // //check if image object is valid
            return $image->valid() ? $image : false;
        }
    }
}
