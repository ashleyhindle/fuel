#!/bin/bash
set -e

# Build PHAR with version from git tags or 'dev'
echo "Building PHAR..."
php fuel app:build fuel.phar --build-version=$(git describe --tags 2>/dev/null || echo dev)

# Build binaries for mac and linux using PHPacker
echo "Building binaries..."
./vendor/bin/phpacker build --src=./builds/fuel.phar --dest=./builds --php=8.4 mac
./vendor/bin/phpacker build --src=./builds/fuel.phar --dest=./builds --php=8.4 linux

# Rename to expected format (mac->darwin, nested->flat)
echo "Renaming binaries..."
cd builds/build
mv mac/mac-arm ../fuel-darwin-arm64
mv mac/mac-x64 ../fuel-darwin-x64
mv linux/linux-arm ../fuel-linux-arm64
mv linux/linux-x64 ../fuel-linux-x64
cd ..
rm -rf build

echo "Build complete!"
ls -lh fuel-*
