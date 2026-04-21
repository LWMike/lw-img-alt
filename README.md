# Project Name

> One-line description of what this project does.

## Quick Start

```bash
# Clone and install
git clone <repo-url>
cd <project-name>
npm install

# Start development
npm run dev
```

## AI Workflow

This project is set up for an AI-assisted development workflow using three tools:

| Tool | Best For | How to Use |
|------|----------|------------|
| **Claude Code** | Multi-file changes, refactoring, debugging, feature builds | Run `claude` in the integrated terminal |
| **GitHub Copilot** | Inline autocomplete, quick edits, test generation | Tab to accept suggestions as you type |
| **ChatGPT** | Architecture decisions, research, PRDs, explanations | Use the web/app for strategic questions |

### Typical Session

1. **Plan** — Discuss approach in ChatGPT or Claude Code
2. **Build** — Use Claude Code for multi-file feature work
3. **Refine** — Use Copilot inline for polish, boilerplate, tests
4. **Review** — Use Claude Code to review changes before committing

## Project Structure

```
├── .vscode/           # VSCode settings, extensions, tasks
│   ├── settings.json
│   ├── extensions.json
│   ├── tasks.json
│   └── launch.json
├── .github/
│   └── workflows/     # CI/CD
├── src/               # Source code
├── tests/             # Test files
├── docs/              # Documentation
├── scripts/           # Build/deploy scripts
├── .env.example       # Environment variable template
├── .gitignore
├── CLAUDE.md          # Claude Code project context
└── package.json
```

## Scripts

```bash
npm run dev        # Start dev server
npm run build      # Production build
npm run test       # Run tests
npm run lint       # Lint + format check
npm run lint:fix   # Auto-fix lint issues
```

## Environment Variables

Copy `.env.example` to `.env` and fill in your values:

```bash
cp .env.example .env
```

## Deployment

_Describe your deployment process here._

## License

_Choose your license._
