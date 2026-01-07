#!/bin/bash
set -e

# Build PHAR with version from git tags or 'dev'
echo "Building PHAR..."
php fuel app:build fuel.phar --build-version=$(git describe --tags 2>/dev/null || echo dev)

# Build binaries for all platforms using PHPacker
echo "Building binaries for all platforms..."
./vendor/bin/phpacker build --src=./builds/fuel.phar --php=8.4 all

echo "Build complete!"
