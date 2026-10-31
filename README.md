# Camera RAW Previews
[![Github All Releases](https://img.shields.io/github/downloads/ariselseng/camerarawpreviews/total.svg)](https://github.com/ariselseng/camerarawpreviews/releases) [![paypal](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://www.paypal.me/AriSelseng/2EUR)

A Nextcloud app that generates previews for camera **RAW** images like .CR2, .CR3, .CRW, .DNG, .MRW, .NEF, .NRW, .RW2, .SRW, .ARW, etc.

This app also gives you previews of Adobe **InDesign** files (.INDD).

## How it works

The app tries several strategies in order, stopping at the first that succeeds:

1. **Embedded JPEG** — most RAW files carry a full-resolution JPEG preview baked in by the camera. This is fast and lossless.
2. **Embedded JPEG XL** — some DNG/ProRAW files store a larger preview as JPEG XL. It is used only when it is larger than the best embedded JPEG, and is converted to JPEG with the bundled fallback binary.
3. **MRW** — Minolta RAW files get their own dedicated extractor.
4. **InDesign** — Adobe InDesign documents embed a page preview in XMP.
5. **Imagick TIFF** — for TIFF-based files with no embedded JPEG (e.g. plain TIFFs, some DNGs), ImageMagick is used if available.
6. **rs-fallback** — a bundled native binary (built with [libraw](https://www.libraw.org/)) develops the RAW data directly. This is the last resort for files that have no extractable preview.

## Requirements

- The **gd** PHP module (required by Nextcloud itself).
- The bundled **rs-fallback** binary must be present and executable for JPEG XL preview conversion. Without it, files that only have a larger JPEG XL preview fall back to their embedded JPEG.
- The **imagick** PHP module is optional. It is only needed for TIFF-based files with no embedded JPEG preview.
- A reasonably high **memory_limit** in PHP (e.g. 512M or more for large RAW files).

## Installation

Install from the Nextcloud App Store:
https://apps.nextcloud.com/apps/camerarawpreviews


## Building the bundled binary

`rs-fallback` is built inside a Debian bullseye container (for an old glibc)
and copied to `bin/`:

```shell
$ bash rs-fallback/build.sh x86_64
$ bash rs-fallback/build.sh aarch64
```

`make rs-fallback` builds both. On an x86_64 host the aarch64 build needs QEMU
binfmt (`qemu-user-static`) or an aarch64 host. `make appstore` ships every
`bin/rs-fallback-linux-*` that exists, or require both explicitly:

```shell
$ make appstore rs_fallback_archs="x86_64 aarch64"
```

## Development

Dependency and release checks:

- `make audit` runs `cargo audit` and `composer audit`.
- `make verify-libraw` asserts that the built binaries contain the expected
  LibRaw release; `make appstore` runs it before packaging.

The bundled binary also statically links C libraries that are not listed in
`Cargo.lock` or `composer.lock` (LibRaw, zlib, libjpeg-turbo, TinyEXIF,
tinyxml2, stb_image). Package audits cannot see them, so check their upstream
releases before a release; versions and licences are in
`THIRD_PARTY_NOTICES.md`. The build downloads them without checksum
verification, and `stb_image` is fetched from `master` rather than a pinned
commit.

## Troubleshooting

If you get no preview, the RAW file may have no extractable embedded preview and the rs-fallback binary may not support that format. You can diagnose this with the bundled debug tool, which runs the exact same extraction pipeline the preview provider uses:

```shell
$ bin/extract-preview rawfile.dng
```

On success it reports which extractor handled the file, the JPEG size and whether the result decodes, and writes the preview next to the input. On failure it reports that no preview could be extracted.

## Licensing

The app is licensed under the GNU Affero General Public License, version 3 or
later; see `COPYING`.

The bundled `rs-fallback` binary includes third-party components under their
own licences, including LibRaw (LGPL-2.1) and `raw_preview_rs` (GPL-3.0). See
`THIRD_PARTY_NOTICES.md` and `LICENSES/` for details, source links and the
written offer for the LibRaw source.
