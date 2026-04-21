# CLAUDE.md — Project Context for Claude Code

## Project Overview

<!-- Describe what this project does, who it's for, and the key goals. -->
<!-- Claude Code reads this file automatically to understand your project. -->

**Project:** [Name]
**Type:** [Web app / API / CLI tool / WordPress plugin / etc.]
**Stack:** [e.g. Node.js, TypeScript, React, Next.js, Express, etc.]
**Hosting:** [e.g. Kinsta, Railway, Vercel, etc.]

## Architecture

<!-- Describe the high-level architecture: key directories, data flow, integrations. -->

## Key Commands

```bash
npm run dev        # Start dev server
npm run build      # Production build  
npm run test       # Run tests
npm run lint       # Lint check
```

## Conventions

- Use TypeScript strict mode
- Follow ESLint + Prettier config in this repo
- Write tests for new features in `/tests`
- Use conventional commits: `feat:`, `fix:`, `chore:`, `docs:`
- Keep functions small and well-named; avoid deep nesting

## Current Focus

<!-- Update this section as priorities change. Claude Code will use it for context. -->

- [ ] Current task or feature being worked on
- [ ] Known issues or blockers

## Environment

- Node.js 20+
- npm (not yarn/pnpm unless noted)
- `.env` file required — see `.env.example`

## Don'ts

- Don't commit `.env` files or secrets
- Don't modify files in `/vendor` or `/node_modules`
- Don't push directly to `main` — use feature branches
