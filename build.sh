#!/bin/bash
set -e

# Build PHAR with version from git tags or 'dev'
echo "Building PHAR..."
php fuel app:build fuel.phar --build-version=$(git describe --tags 2>/dev/null || echo dev)

# Build binaries for all platforms using PHPacker
echo "Building binaries for all platforms..."
./vendor/bin/phpacker build --src=./builds/fuel.phar --dest=./builds --php=8.4 all

# Rename to expected format (mac->darwin, nested->flat)
echo "Renaming binaries..."
cd builds/build
mv mac/mac-arm ../fuel-darwin-arm64
mv mac/mac-x64 ../fuel-darwin-x64
mv linux/linux-arm ../fuel-linux-arm64
mv linux/linux-x64 ../fuel-linux-x64
mv windows/windows-x64.exe ../fuel-windows-x64.exe
cd ..
rm -rf build

echo "Build complete!"
ls -lh fuel-*
