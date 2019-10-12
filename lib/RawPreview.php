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
            $perlBin = $this->getPerlExecuteable();
            $this->converter = $perlBin . ' ' . realpath(__DIR__ . '/../vendor/jmoati/exiftool-bin/exiftool');
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
        //get all available previews
        $previewData = json_decode(shell_exec($this->converter . " -json -preview:all " . escapeshellarg($tmpPath)), true);

        if (isset($previewData[0]['JpgFromRaw'])) {
            return 'JpgFromRaw';
        } else if (isset($previewData[0]['PageImage'])) {
            return 'PageImage';
        } else if (isset($previewData[0]['PreviewImage'])) {
            return 'PreviewImage';
        } else if (isset($previewData[0]['OtherImage'])) {
            return 'OtherImage';
        } else if (isset($previewData[0]['ThumbnailImage'])) {
            return 'ThumbnailImage';
        } else if (isset($previewData[0]['PreviewTIFF'])) {
            if ($this->driver === 'imagick') {
                return 'PreviewTIFF';
            } else {
                throw new \Exception('Needs imagick to extract TIFF previews');
            }
        } else if (isset($previewData[0]['ThumbnailTIFF'])) {
            if ($this->driver === 'imagick') {
                return 'ThumbnailTIFF';
            } else {
                throw new \Exception('Needs imagick to extract TIFF previews');
            }
        } else {
            throw new \Exception('Unable to find preview data');
        }
    }

    private function getPerlExecuteable()
    {
        $perlBin = \OC_Helper::findBinaryPath('perl');
        if (!is_null($perlBin)) {
            return $perlBin;
        }

        $perlBin = exec("command -v perl");
        if (!empty($perlBin)) {
            return $perlBin;
        }

        //fallback to static vendored perl
        if (php_uname("s") === "Linux" && substr(php_uname("m"), 0, 3) === 'x86') {
            $perlBin = realpath(__DIR__ . '/../bin/staticperl');
            $fallback_is_executable = is_executable($perlBin);

            if (!$fallback_is_executable && is_writable($perlBin)) {
                $fallback_is_executable = chmod($perlBin, 0744);
            }

            if ($fallback_is_executable) {
                $this->logger->warning('You do not have perl globally installed. Using a deprecated built in perl.', ['app' => $this->appName]);
            } else {
                $perlBin = null;
            }
        }

        if (!empty($perlBin)) {
            return $perlBin;
        }

        throw new \Exception('No perl executeable found. Camera Raw Previews app will not work.');
    }

    protected function getResizedPreview($tmpPath, $maxX, $maxY)
    {
        $previewTag = $this->getBestPreviewTag($tmpPath);

        //tmp
        $previewImageTmpPath = dirname($tmpPath) . '/' . md5($tmpPath . uniqid()) . '.jpg';

        //extract preview image using exiftool to file
        shell_exec($this->converter . " -b -" . $previewTag . " " . escapeshellarg($tmpPath) . ' > ' . escapeshellarg($previewImageTmpPath));
        if (filesize($previewImageTmpPath) < 100) {
            throw new \Exception('Unable to extract valid preview data');
        }
        //update previewImageTmpPath with orientation data
        shell_exec($this->converter . ' -TagsFromFile ' . escapeshellarg($tmpPath) . ' -orientation -overwrite_original ' . escapeshellarg($previewImageTmpPath));

        $im = Image::make($previewImageTmpPath);
        $im->orientate();
        $im->resize($maxX, $maxY, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        unlink($previewImageTmpPath);
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
