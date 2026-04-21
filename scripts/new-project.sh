#!/bin/bash
# ===========================================
# new-project.sh
# Clone this template and set up a new project
# Usage: ./scripts/new-project.sh my-project-name
# ===========================================

set -e

PROJECT_NAME=${1:?"Usage: $0 <project-name>"}

echo "🐺 Setting up new project: $PROJECT_NAME"

# Update package.json name
if command -v sed &> /dev/null; then
  sed -i.bak "s/\"project-name\"/\"$PROJECT_NAME\"/" package.json
  rm -f package.json.bak
fi

# Create .env from template
cp .env.example .env
echo "✅ Created .env from template"

# Initialise git
rm -rf .git
git init
git add .
git commit -m "feat: initial project setup from template"
echo "✅ Git repository initialised"

# Install dependencies
npm install
echo "✅ Dependencies installed"

echo ""
echo "🚀 Ready! Next steps:"
echo "   1. Update CLAUDE.md with your project details"
echo "   2. Update README.md"
echo "   3. Add your GitHub remote:"
echo "      git remote add origin git@github.com:leadwolf/$PROJECT_NAME.git"
echo "   4. Open in VSCode: code ."
echo "   5. Install recommended extensions when prompted"
echo "   6. Start building: npm run dev"
