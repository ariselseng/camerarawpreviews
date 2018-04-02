# Camera RAW Previews
Place this app in **nextcloud/apps/**

## Requirements
* imagick module for php
* exiftool command needs to be available
* Probably memory_limit quite high.

To activate the feature you need to have this in your config:
```
'enabledPreviewProviders' => array(
  'OCA\\CameraRawPreviews\\RawPreview',
  'OCA\\CameraRawPreviews\\IndesignPreview' // for indesign files (.indd)
),
