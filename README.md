# Camera RAW Previews
A Nextcloud app that provides previews for camera RAW images like .NEF, .CR2, etc.

## Requirements
* Probably **memory_limit** quite high.
* **imagick** or **gd** module. If imagick is available, it will use that for performance.


## Installation
Place this app in **nextcloud/apps/**


To activate the feature you probably need to have this in your config:
```
'enabledPreviewProviders' => array(
  'OCA\\CameraRawPreviews\\RawPreview',
  'OCA\\CameraRawPreviews\\IndesignPreview' // for indesign files (.indd)
),
```
## Donation
If this app helps you in your business, it would be nice to give me a cup of coffee :)

[![paypal](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.me/AriSelseng/2EUR)
