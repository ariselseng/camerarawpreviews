# Camera RAW Previews
[![Github All Releases](https://img.shields.io/github/downloads/ariselseng/camerarawpreviews/total.svg)](https://github.com/ariselseng/camerarawpreviews/releases) [![paypal](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.me/AriSelseng/2EUR)

A Nextcloud app that extracts embedded previews for camera **RAW** images like .CR2, .CRW, .DNG, .MRW, .NEF, .NRW, .RW2, .SRW, .SRW, etc.

This app also gives you preview of Adobe **Indesign** files (.INDD) photos.


## Requirements
* Probably **memory_limit** quite high.
* The **gd** module (required by Nextcloud itself).
* The **imagick** module is optional. It is only needed to render TIFF-based files that carry no extractable embedded JPEG (e.g. plain TIFFs and some DNG/RAW variants).

## Installation
Install in Nextcloud App store.
https://apps.nextcloud.com/apps/camerarawpreviews

## Building locally
- Run "make"
- Place this app in **./apps/**

## Troubleshooting
- If you get no preview, the RAW file probably has no extractable embedded preview. You can check this with the bundled debug tool, which runs the exact same extraction pipeline the preview provider uses:
 ```shell
$ bin/extract-preview rawfile.dng
```
 On success it reports which extractor handled the file, the JPEG size and whether the result decodes, and writes the preview next to the input. On failure it reports that no preview could be extracted.
