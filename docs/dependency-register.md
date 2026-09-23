# Third-party dependency register

The Master Developer Instructions require that *"any third-party library, theme,
SDK, template, API, image, font, plugin or commercial component must be
disclosed with its license, recurring cost, ownership limitations and
replacement implications."* This register is that disclosure, maintained as
part of the codebase so it cannot drift from what is actually installed.

Last reviewed: 2026-09-24

---

## Runtime — front end

| Component | Version | Licence | Cost | Ownership / lock-in | Replacement |
|---|---|---|---|---|---|
| [Three.js](https://threejs.org) | ^0.171 | MIT | None | None. MIT permits commercial use, modification and redistribution. | The only consumer is `src/scenes/hero.ts`, a single decorative scene. Removing it deletes one file and one dynamic import; the hero already renders a complete CSS gradient fallback for visitors who never load it. No platform function depends on it. |

No front-end framework, UI kit, icon set, animation library or CSS framework is
used. The design system is the theme's own `theme.json` and four hand-written
stylesheets.

## Runtime — server

| Component | Version | Licence | Cost | Ownership / lock-in | Replacement |
|---|---|---|---|---|---|
| WordPress | 6.7 | GPL-2.0-or-later | None | None. Self-hosted; no wordpress.com account or service is involved. | — |
| MySQL | 8.0 | GPL-2.0 with FOSS exception | None | None. Standard SQL plus documented MySQL-specific features (triggers, `SIGNAL`), noted in the migration files. | MariaDB is drop-in compatible for everything used. |
| PHP | 8.3 | PHP Licence 3.01 | None | None | — |

`reservechain-core` has **no Composer runtime dependencies**. The plugin runs on
PHP standard library only, and ships a PSR-4 fallback autoloader so it boots
from a plain file copy without `composer install` — deliberately, because the
disaster-recovery procedure must not depend on a package manager reaching the
internet.

## Build and development only — not shipped to visitors

| Component | Version | Licence | Purpose |
|---|---|---|---|
| Vite | ^6.0 | MIT | Bundles TypeScript for the theme |
| TypeScript | ^5.7 | Apache-2.0 | Type checking |
| @types/three | ^0.171 | MIT | Type definitions |
| PHPStan | ^2.0 | MIT | Static analysis (dev dependency) |
| PHP_CodeSniffer + WPCS | ^3.11 / ^3.1 | BSD-3-Clause / MIT | Coding standards (dev dependency) |
| PHPUnit | ^9.6 | BSD-3-Clause | Unit tests (dev dependency) |
| Docker images: `mysql:8.0`, `wordpress:php8.3-apache`, `quay.io/minio/minio`, `axllent/mailpit`, `ghcr.io/foundry-rs/foundry` | — | Various OSS | Local development stack only |

## Deliberately not used

| Not used | Why it matters |
|---|---|
| Page builders (Elementor, WPBakery, Divi) | The brief rejects purchased templates and generic builds. A builder would also make the handover dependent on a commercial licence the client would have to keep renewing. |
| Commercial WordPress plugins | Any recurring licence would become an ownership limitation at handover. |
| Google Fonts (hosted) | Loading fonts from Google discloses visitor IP addresses to a third party before consent, which conflicts with the cookie and analytics requirements. Typography uses system stacks, with self-hosted webfonts to follow. |
| CDN-hosted JavaScript | All assets are served from the platform's own origin, so a third party cannot alter what executes on the site and the Content Security Policy can stay strict. |
| Analytics or tag managers | Not yet installed. When required, the account must be created under ReserveChain's own control, and no non-essential tracking may run before consent. |

## Review

This register is checked whenever a dependency is added or upgraded, and at
each milestone acceptance. Anything with a recurring cost, a restrictive
licence, or a meaningful replacement cost must be raised with the project owner
before it is introduced.
