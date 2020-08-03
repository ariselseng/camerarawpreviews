#!/bin/sh

[ -e ./exiftool.bin ] && rm ./exiftool.bin
[ -e ./staticperl ] && rm ./staticperl

apk -U add upx curl wget alpine-sdk &&
 curl http://cvs.schmorp.de/App-Staticperl/bin/staticperl -o ./staticperl && chmod +x ./staticperl && \
 ./staticperl mkapp exiftool.bin --boot exiftool_wrapper.pl perl.bundle && \

[ -e ./staticperl ] && rm ./staticperl
