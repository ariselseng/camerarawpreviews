# Third-party notices

Camera RAW Previews itself is licensed under the GNU Affero General Public
License, version 3 or later. See `COPYING`.

The prebuilt `bin/rs-fallback-linux-*` binaries that ship with the app bundle
third-party code. That code stays under its own licence; the AGPL does not
replace or override it. This file records the components, the licences this
project relies on, and where to get the corresponding source.

## LibRaw — LGPL-2.1 (statically linked)

The `rs-fallback` binary statically links LibRaw.

- Copyright (C) 2008-2025 LibRaw LLC and contributors.
- LibRaw is dual-licensed under LGPL-2.1 and CDDL-1.0. **This project uses the
  LGPL-2.1 option**, not the CDDL option. The full licence text is in
  `LICENSES/LGPL-2.1.txt`.
- The LibRaw version is pinned in `rs-fallback/Dockerfile.build`; release
  binaries currently use **0.22.3**. The corresponding unmodified source is
  the upstream tag:
  https://github.com/LibRaw/LibRaw/archive/refs/tags/0.22.3.tar.gz
  SHA-256: `7edf96c5b70cff88980a02ab0fc8af125cb6a4a377985573c654439d9d02355c`
- LibRaw is statically linked, so LGPL-2.1 section 6 applies. The complete
  source for the app and the build scripts (`rs-fallback/build.sh` and
  `rs-fallback/Dockerfile.build`) are published at
  https://github.com/ariselseng/camerarawpreviews, so you can modify LibRaw
  and relink the binary.

**Written offer.** For at least three years from the date you received this
release, the maintainers of Camera RAW Previews will give you the complete
machine-readable source code for LibRaw 0.22.3 and the object code and/or
source and build scripts needed to relink a modified LibRaw into the
`rs-fallback` binary, for no more than the cost of performing the
distribution. Contact the project at
https://github.com/ariselseng/camerarawpreviews/issues.

Older releases may pin another LibRaw version; substitute that tag in the URL
above. `rs-fallback/Dockerfile.build` in the corresponding commit is the
authoritative record of the version that was built.

## raw_preview_rs — GPL-3.0

- `rs-fallback` depends on `raw_preview_rs` 0.1.2, licensed GPL-3.0. The full
  licence text is in `LICENSES/GPL-3.0.txt`.
- Source: https://crates.io/crates/raw_preview_rs/0.1.2 (upstream repository:
  https://github.com/mlgldl/raw_preview_rs).
- The build applies a small patch to the crate before compiling it: it sets
  `no_auto_bright = 0` in `libraw_wrapper.cpp`, and it changes the LibRaw
  version in the crate's `build.rs` to the version pinned by this project.
  The exact `sed` commands are in `rs-fallback/Dockerfile.build`, and the
  complete corresponding source can be reconstructed from the upstream crate
  plus those commands. The patched crate is not distributed separately.
- GPL-3.0 and AGPL-3.0 are compatible; the combined binary is conveyed under
  AGPL-3.0, with GPL-3.0 applying to the `raw_preview_rs` portion.

## Other statically linked native libraries

These permissively licensed libraries are also built into the `rs-fallback`
binary by `raw_preview_rs`'s build script:

| Component | Version | Licence | Licence text |
| --- | --- | --- | --- |
| zlib | 1.3 | Zlib | `LICENSES/Zlib.txt` |
| libjpeg-turbo | 2.1.5 | IJG, BSD-3-Clause and Zlib | `LICENSES/IJG.txt`, `LICENSES/libjpeg-turbo.txt` |
| TinyEXIF | 1.0.3 | BSD-2-Clause | `LICENSES/BSD-2-Clause.txt` |
| tinyxml2 | 11.0.0 | Zlib | `LICENSES/tinyxml2.txt` |
| stb_image | `master` (unpinned) | Public domain or MIT | `LICENSES/MIT.txt` |

This software is based in part on the work of the Independent JPEG Group.

## Rust crates

The Rust dependency graph is pinned in `rs-fallback/Cargo.lock`; each crate is
used under the licence declared in its own package metadata. Direct
dependencies:

| Crate | Version | Licence |
| --- | --- | --- |
| `jxl-oxide` | 0.12.6 | MIT |
| `jpeg-encoder` | 0.7.1 | MIT |
| `tempfile` | 3.27.0 | MIT OR Apache-2.0 |
| `libc` | 0.2.186 | MIT OR Apache-2.0 |
| `raw_preview_rs` | 0.1.2 | GPL-3.0 (see above) |

MIT and Apache-2.0 licence texts are in `LICENSES/MIT.txt` and
`LICENSES/Apache-2.0.txt`. Copyright notices for the individual crates remain
in their upstream source distributions.

## Reporting a licensing problem

If you think a component is missing from this file, or you need source for a
specific release, open an issue at
https://github.com/ariselseng/camerarawpreviews/issues.
