---
name: ckm:design-system
description: Design tokens, component specifications, CSS variable systems, and brand-compliant presentation generation. Use for token architecture (primitive → semantic → component), Tailwind theme integration, design-to-code handoff, and slide generation with Chart.js.
argument-hint: "[create|tokens|slides] [args]"
license: MIT
metadata:
  author: claudekit
  version: "1.0.0"
---

# Design System

Comprehensive toolkit for creating brand-compliant design tokens, component specifications, and presentations.

## When to Use

- Creating or managing design token systems
- Building CSS variable architectures
- Tailwind theme configuration
- Component state specifications (default, hover, active, disabled)
- Generating brand-compliant slide decks
- Design-to-code handoffs

## Core Architecture

**Three-Layer Token Structure:**
1. **Primitive** — raw values (colors, spacing, typography)
2. **Semantic** — purpose-based aliases (`--color-primary`, `--color-error`)
3. **Component** — UI-specific variables (`--button-bg`, `--card-border`)

## Key Capabilities

**Token Management:**
- CSS variable generation and validation
- Spacing/typography scales
- Component state definitions
- Tailwind theme integration

**Slide Generation:**
- BM25 search for contextual slide discovery
- Decision CSVs mapping goals to layouts, typography, colors, and animations
- Pattern breaking (Duarte Sparkline) for engagement
- Chart.js integration for data visualization

## Critical Rule

**ALL slides MUST import `assets/design-tokens.css`** — single source of truth.
Use CSS variables exclusively, never hardcoded values.

```css
/* CORRECT */
color: var(--color-primary);
font-family: var(--font-heading);

/* WRONG */
color: #6366F1;
font-family: "Inter", sans-serif;
```

## Integration

**Dependencies:** brand, ui-styling skills
**Related Skills:** ui-ux-pro-max, frontend-design

## References

| Topic | File |
|-------|------|
| Token Architecture | `references/token-architecture.md` |
| Component Specs | `references/component-specs.md` |
| Slide Layouts | `references/slide-layouts.md` |
| Tailwind Config | `references/tailwind-config.md` |
