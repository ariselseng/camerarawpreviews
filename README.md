# Camera RAW Previews
[![Github All Releases](https://img.shields.io/github/downloads/cowai/camerarawpreviews/total.svg)](https://github.com/cowai/camerarawpreviews/releases) [![paypal](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.me/AriSelseng/2EUR)

A Nextcloud/ownCloud app that provides previews for camera RAW images like .CR2, .CRW, .DNG, .MRW, .NEF, .NRW, .RW2, .SRW, .SRW, etc.
This app also gives you preview of Adobe Indesign files (.INDD).


## Requirements
* Probably **memory_limit** quite high.
* **imagick** or **gd** module. If imagick is available, it will use that for performance.
* For files with a TIFF preview (at least some DNG files), **imagick** is required

## Installation
Install in Nextcloud App store.
https://apps.nextcloud.com/apps/camerarawpreviews

Install in ownCloud Marketplace
https://marketplace.owncloud.com/apps/camerarawpreviews

## Building locally
- Run "make"
- Place this app in **./apps/**
