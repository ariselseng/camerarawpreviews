# Camera RAW Previews
[![Github All Releases](https://img.shields.io/github/downloads/ariselseng/camerarawpreviews/total.svg)](https://github.com/ariselseng/camerarawpreviews/releases) [![paypal](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.me/AriSelseng/2EUR)

A Nextcloud app that extracts embedded previews for camera **RAW** images like .CR2, .CRW, .DNG, .MRW, .NEF, .NRW, .RW2, .SRW, .SRW, etc.

This app also gives you preview of Adobe **Indesign** files (.INDD) photos.


## Requirements
* Probably **memory_limit** quite high.
* **imagick** or **gd** module. If imagick is available, it will use that for performance.
* For files with a TIFF preview (at least some DNG files), **imagick** is required

## Installation
Install in Nextcloud App store.
https://apps.nextcloud.com/apps/camerarawpreviews

Install in ownCloud Marketplace (older version that is not supported anymore, due to too much difference between owncloud and nextcloud now)
https://marketplace.owncloud.com/apps/camerarawpreviews

## Building locally
- Run "make"
- Place this app in **./apps/**

## Information about the perl binary
- To avoid lots of issues and problems for users I am bundling a static build of perl for x86_64
- The binary is built using an isolated docker container with this: http://software.schmorp.de/pkg/App-Staticperl.html

## Troubleshooting
- If you get no preview, make sure your raw files has an embedded preview. If it looks like this, it does not have an embedded preview:
 ```shell
$ exiftool -json -preview:all rawfile.dng
 [{
  "SourceFile": "rawfile.dng"
}]
