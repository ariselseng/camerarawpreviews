# Camera RAW Previews
[![Github All Releases](https://img.shields.io/github/downloads/ariselseng/camerarawpreviews/total.svg)](https://github.com/ariselseng/camerarawpreviews/releases) [![paypal](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.me/AriSelseng/2EUR)

A Nextcloud app that generates previews for camera **RAW** images like .CR2, .CR3, .CRW, .DNG, .MRW, .NEF, .NRW, .RW2, .SRW, .ARW, etc.

This app also gives you previews of Adobe **InDesign** files (.INDD).

## How it works

The app tries several strategies in order, stopping at the first that succeeds:

1. **Embedded JPEG** — most RAW files carry a full-resolution JPEG preview baked in by the camera. This is fast and lossless.
2. **MRW** — Minolta RAW files get their own dedicated extractor.
3. **InDesign** — Adobe InDesign documents embed a page preview in XMP.
4. **Imagick TIFF** — for TIFF-based files with no embedded JPEG (e.g. plain TIFFs, some DNGs), ImageMagick is used if available.
5. **rs-fallback** — a bundled native binary (built with [libraw](https://www.libraw.org/)) develops the RAW data directly. This is the last resort for files that have no extractable preview.

## Requirements

- The **gd** PHP module (required by Nextcloud itself).
- The **imagick** PHP module is optional. It is only needed for TIFF-based files with no embedded JPEG preview.
- A reasonably high **memory_limit** in PHP (e.g. 512M or more for large RAW files).

## Installation

Install from the Nextcloud App Store:
https://apps.nextcloud.com/apps/camerarawpreviews


## Troubleshooting

If you get no preview, the RAW file may have no extractable embedded preview and the rs-fallback binary may not support that format. You can diagnose this with the bundled debug tool, which runs the exact same extraction pipeline the preview provider uses:

```shell
$ bin/extract-preview rawfile.dng
```

On success it reports which extractor handled the file, the JPEG size and whether the result decodes, and writes the preview next to the input. On failure it reports that no preview could be extracted.
