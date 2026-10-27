#!/bin/sh
# Zip instalable desde el último commit (lo marcado export-ignore en .gitattributes queda fuera).
set -e
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: *//p' siteferry.php)
mkdir -p dist
git archive --format=zip --prefix=siteferry/ -o "dist/siteferry-$version.zip" HEAD
echo "dist/siteferry-$version.zip"
