#!/bin/bash
set -e

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${GREEN}Deploying Fuel to Cloudflare Pages...${NC}"
echo ""

# Check if public directory exists
if [ ! -d "public" ]; then
    echo -e "${RED}Error: public/ directory not found${NC}"
    exit 1
fi

# Check if user is logged in (basic check - wrangler will error if not)
echo -e "${YELLOW}Checking authentication...${NC}"
if ! npx wrangler whoami &> /dev/null; then
    echo -e "${YELLOW}Not logged in. Running wrangler login...${NC}"
    npx wrangler login
fi

# Deploy
echo ""
echo -e "${GREEN}Deploying to Cloudflare Pages...${NC}"
npx wrangler pages deploy --project-name=addfuel-dev --commit-dirty=true

echo ""
echo -e "${GREEN}✓ Deployment complete!${NC}"
echo ""
echo "Your site should be available at: https://addfuel.dev"
