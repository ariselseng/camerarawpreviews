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
# A native build (x86_64 on x86_64, aarch64 on aarch64) uses a plain build,
# which runs on the host network — important because raw_preview_rs's build.rs
# downloads zlib at compile time. A foreign-arch build needs QEMU binfmt on
# Linux, or a native aarch64 host (e.g. a Raspberry Pi or an arm64 VM).
#
# Usage:  ./build.sh [x86_64|aarch64|all]   (default: all)
# Env:    CONTAINER_RUNTIME=docker|podman   (default: docker if present, else podman)
# Output: ../bin/rs-fallback-linux-x86_64
#         ../bin/rs-fallback-linux-aarch64

set -euo pipefail

cd "$(dirname "$0")"

OUT_DIR="../bin"
BIN_IN_IMAGE="/src/target/release/camerarawpreviews"
RUNTIME="${CONTAINER_RUNTIME:-$(command -v docker || command -v podman || true)}"
ARCHS=("x86_64" "aarch64")

if [[ -z "$RUNTIME" ]]; then
    echo "error: docker or podman is required" >&2
    exit 1
fi
ENGINE="$(basename "$RUNTIME")"

case "$(uname -m)" in
    x86_64|amd64)  HOST_ARCH="x86_64" ;;
    aarch64|arm64) HOST_ARCH="aarch64" ;;
    *)             HOST_ARCH="$(uname -m)" ;;
esac

if [[ "${1:-all}" != "all" ]]; then
    ARCHS=("$1")
fi

mkdir -p "$OUT_DIR"

for ARCH in "${ARCHS[@]}"; do
    IMAGE_TAG="camerarawpreviews-rs-fallback-build:bullseye-${ARCH}"
    OUT_BIN="$OUT_DIR/rs-fallback-linux-${ARCH}"

    echo ">> Building $ARCH binary with $ENGINE..."

    if [[ "$ARCH" == "$HOST_ARCH" ]]; then
        # Native build — plain build uses the host network, avoiding the
        # buildx layer that has caused timeouts in raw_preview_rs's build.rs.
        "$RUNTIME" build -f Dockerfile.build -t "$IMAGE_TAG" .
        cid="$("$RUNTIME" create "$IMAGE_TAG")"
    else
        case "$ARCH" in
            x86_64)  PLATFORM="linux/amd64" ;;
            aarch64) PLATFORM="linux/arm64" ;;
            *) echo "Unknown arch: $ARCH" >&2; exit 1 ;;
        esac

        # Rootless containers on Linux cannot register binfmt themselves, so
        # fail early with an actionable message instead of a confusing
        # "exec format error" halfway through the build.
        if [[ -d /proc/sys/fs/binfmt_misc ]] \
            && ! test -f "/proc/sys/fs/binfmt_misc/qemu-${ARCH}"; then
            echo "error: qemu-${ARCH} is not registered with binfmt_misc." >&2
            echo "       Install qemu-user-static (Fedora: sudo dnf install qemu-user-static)," >&2
            echo "       enable systemd-binfmt, or build on a native ${ARCH} host." >&2
            exit 1
        fi

        if [[ "$ENGINE" == "podman" ]]; then
            "$RUNTIME" build --platform "$PLATFORM" -f Dockerfile.build -t "$IMAGE_TAG" .
        else
            "$RUNTIME" buildx build --platform "$PLATFORM" --load \
                -t "$IMAGE_TAG" -f Dockerfile.build .
        fi
        cid="$("$RUNTIME" create --platform "$PLATFORM" "$IMAGE_TAG")"
    fi

    cleanup() {
        "$RUNTIME" rm -f "$cid" >/dev/null 2>&1 || true
    }
    trap cleanup EXIT

    "$RUNTIME" cp "$cid:$BIN_IN_IMAGE" "$OUT_BIN"
    cleanup
    trap - EXIT
    chmod +x "$OUT_BIN"

    echo ">> $ARCH done: $OUT_BIN ($(du -sh "$OUT_BIN" | cut -f1))"
done

echo
echo ">> All done. Binaries in $OUT_DIR:"
ls -lh "$OUT_DIR"/rs-fallback-linux-* 2>/dev/null
