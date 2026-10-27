#!/bin/sh
# Installable zip from the latest commit (anything marked export-ignore in .gitattributes is left out).
set -e
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: *//p' siteferry.php)
mkdir -p dist
git archive --format=zip --prefix=siteferry/ -o "dist/siteferry-$version.zip" HEAD
echo "dist/siteferry-$version.zip"
