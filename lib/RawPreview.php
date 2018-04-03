<?php
namespace OCA\CameraRawPreviews;
require __DIR__ . '/../vendor/autoload.php';

use OCP\Preview\IProvider;
use Intervention\Image\ImageManagerStatic as Image;

class RawPreview implements IProvider {
    private $converter;

    public function __construct() {
        Image::configure(array('driver' => extension_loaded('imagick') && count(\Imagick::queryformats('JPEG')) > 0 ? 'imagick' : 'gd'));

        $perl_bin = \OC_Helper::findBinaryPath('perl');
        //fallback to static vendored perl
        if (empty($perl_bin) && substr(php_uname("m"), 0, 3) === 'x86') {
            $perl_bin = realpath(__DIR__ . '/../bin/staticperl');
            if (!is_executable($perl_bin) && is_writable($perl_bin)) {
                chmod($perl_bin, 0744);
            }
        }

        $this->converter = $perl_bin . ' ' . realpath(__DIR__ . '/../vendor/jmoati/exiftool-bin/exiftool');
    }
    
    /**
     * {@inheritDoc}
     */
    public function getMimeType() {
        return '/image\/x-dcraw/';
    }

    /**
     * {@inheritDoc}
     */
    public function getThumbnail($path, $maxX, $maxY, $scalingup, $fileview) {
        $tmpPath = $fileview->toTmpFile($path);
        if (!$tmpPath) {
            return false;
        }

        try {
            $im = $this->getResizedPreview($tmpPath, $maxX, $maxY);
        } catch (\Exception $e) {
            \OCP\Util::writeLog('core', 'Camera Raw Previews: ' . $e->getmessage(), \OCP\Util::ERROR);
            return false;
        }
        finally {
            unlink($tmpPath);
        }

        $image = new \OC_Image($im);
        //check if image object is valid
        return $image->valid() ? $image : false;
    }
    private function getBestPreviewTag($tmpPath) {
        //get all available previews
        $previewData = json_decode(shell_exec($this->converter . " -json -preview:all " . escapeshellarg($tmpPath)), true);

        if (isset($previewData[0]['JpgFromRaw'])) {
            return 'JpgFromRaw';
        } else if (isset($previewData[0]['PageImage'])) {
            return 'PageImage';
        } else if (isset($previewData[0]['PreviewImage'])) {
            return 'PreviewImage';
        } else {
            throw new \Exception('Unable to find preview data');
        }
    }

    private function getResizedPreview($tmpPath, $maxX, $maxY) {
        $previewTag = $this->getBestPreviewTag($tmpPath);
        
        //tmp
        $previewImageTmpPath = dirname($tmpPath) . '/' . md5($tmpPath . uniqid()) . '.jpg';

        //extract preview image using exiftool to file
        shell_exec($this->converter . " -b -" . $previewTag . " " .  escapeshellarg($tmpPath) . ' > ' . escapeshellarg($previewImageTmpPath));
        if (filesize($previewImageTmpPath) < 100) {
            throw new \Exception('Unable to extract valid preview data');   
        }
        //update previewImageTmpPath with orientation data
        shell_exec($this->converter . ' -TagsFromFile '.  escapeshellarg($tmpPath) . ' -orientation -overwrite_original ' . escapeshellarg($previewImageTmpPath));

        $im = Image::make($previewImageTmpPath);
        $im->orientate();
        $im->resize($maxX, $maxY, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        unlink($previewImageTmpPath);
        return $im->encode('jpg', 90);
    }

    public function isAvailable(\OCP\Files\FileInfo $file) {
        return $file->getSize() > 0;
    }
}
