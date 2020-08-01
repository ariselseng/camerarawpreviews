#!/bin/bash
docker_build_mount="$(realpath "$(dirname "$0")"/build)"
app_dir="$(realpath "$docker_build_mount"/../../../..)"
exiftool_dir="$app_dir"/vendor/exiftool/exiftool

docker run -ti  --workdir /build --rm -v "$docker_build_mount":/build i386/alpine:3.9 ./build-perl.sh

if [ -e "$docker_build_mount"/exiftool.bin ];then
  sudo chown $(id -u):$(id -g) "$docker_build_mount"/exiftool.bin

  chmod +x "$docker_build_mount"/exiftool.bin
  if [ -e "$exiftool_dir"/exiftool.bin ];then
    rm "$exiftool_dir"/exiftool.bin
  fi

  mv "$docker_build_mount"/exiftool.bin "$exiftool_dir"/
fi
