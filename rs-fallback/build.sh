#!/usr/bin/env bash
#
# Build portable glibc CLI binaries inside Debian bullseye containers and copy
# them out to ../bin/. These binaries are bundled with the app release tarball.
#
# Why a container at all? Only to build against an OLD glibc (2.31), so the
# binary runs on practically every still-supported Linux distro. glibc is
# backwards compatible: a binary built against an old glibc runs on newer ones.
# libraw is statically linked in, so no distro libraw package is needed either.
#
# For native arch (x86_64 on x86_64, aarch64 on aarch64) we use plain
# `docker build` which runs on the host network — important because
# raw_preview_rs's build.rs downloads zlib at compile time and buildx's
# network layer can cause timeouts. For cross-arch we use buildx + QEMU.
#
# Usage:  ./build.sh [x86_64|aarch64|all]   (default: all)
# Output: ../bin/rs-fallback-linux-x86_64
#         ../bin/rs-fallback-linux-aarch64

set -euo pipefail

cd "$(dirname "$0")"

OUT_DIR="../bin"
BIN_IN_IMAGE="/src/target/release/camerarawpreviews"
HOST_ARCH=$(uname -m)
ARCHS=("x86_64" "aarch64")

if [[ "${1:-all}" != "all" ]]; then
    ARCHS=("$1")
fi

mkdir -p "$OUT_DIR"

for ARCH in "${ARCHS[@]}"; do
    IMAGE_TAG="camerarawpreviews-rs-fallback-build:bullseye-${ARCH}"
    OUT_BIN="$OUT_DIR/rs-fallback-linux-${ARCH}"

    echo ">> Building $ARCH binary..."
    if [[ "$ARCH" == "$HOST_ARCH" ]]; then
        # Native build — plain docker build uses host network, avoids buildx timeouts.
        docker build -f Dockerfile.build -t "$IMAGE_TAG" .
        cid="$(docker create "$IMAGE_TAG")"
    else
        # Cross build — need buildx + QEMU emulation.
        case "$ARCH" in
            x86_64)  PLATFORM="linux/amd64" ;;
            aarch64) PLATFORM="linux/arm64" ;;
            *) echo "Unknown arch: $ARCH"; exit 1 ;;
        esac
        docker buildx build --platform "$PLATFORM" --load -t "$IMAGE_TAG" -f Dockerfile.build .
        cid="$(docker create --platform "$PLATFORM" "$IMAGE_TAG")"
    fi

    trap "docker rm -f '$cid' >/dev/null 2>&1 || true" EXIT
    docker cp "$cid:$BIN_IN_IMAGE" "$OUT_BIN"
    docker rm -f "$cid" >/dev/null 2>&1 || true
    trap - EXIT
    chmod +x "$OUT_BIN"

    echo ">> $ARCH done: $OUT_BIN ($(du -sh "$OUT_BIN" | cut -f1))"
done

echo
echo ">> All done. Binaries in $OUT_DIR:"
ls -lh "$OUT_DIR"/rs-fallback-linux-* 2>/dev/null
