# Gnuboard7

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/ContextualWisdomLab/g7)

Gnuboard7 is a Laravel 12 + React CMS and commerce platform with modular extensions, templates, language packs, and JSON-driven layouts. This repository is the ContextualWisdomLab fork of [`gnuboard/g7`](https://github.com/gnuboard/g7); upstream remains authoritative for the original product, copyright, and official distribution history.

## Start here

For installation and local evaluation, follow the [repository README](../README.md) and [`INSTALL.md`](../INSTALL.md). The current source expects PHP 8.2+, Laravel 12, React/Vite, and a MySQL- or MariaDB-backed runtime; verify exact dependency manifests on the revision you intend to deploy.

A typical source checkout starts with:

```bash
composer install
cp .env.example .env
php artisan serve
```

Then open the installer at `http://localhost:8000/install` and complete the environment-specific database, cache, mail, queue, and web-server configuration described in the install guide.

## Product boundary

The source tree provides the CMS core and extension model used to build community, content, and commerce experiences. Authentication/authorization, content and settings, modular UI/layout composition, search/SEO, notifications, and related application services live inside that platform boundary. External payment, identity-verification, mail, storage, and other providers retain their own security, availability, licensing, and policy authority.

## Architecture and extension model

The backend is Laravel/PHP; the frontend uses React and Vite. Extensions are organized through modules, plugins, templates, and language packs so product-specific capability can be composed without treating every customization as a core fork. JSON-driven layouts provide a declarative rendering boundary for React components.

When integrating another ContextualWisdomLab service, preserve this repository's application boundary and use explicit API/extension contracts rather than coupling to another service's private persistence.

## Verification and releases

Use the repository's own test/build entry points on the exact revision being evaluated:

```bash
composer test
npm run test:run
npm run build
```

This fork currently has no independently verified GitHub Release published by ContextualWisdomLab. A source-tree version string or inherited upstream changelog is not, by itself, evidence that this fork shipped an immutable release. Consult the upstream project for upstream release authority and this repository's protected branch, CI, and review state for fork-specific changes.

## Documentation

- [README](../README.md) — product overview, fork boundary, quick start, and release truth
- [Installation guide](../INSTALL.md) — environment and installation procedures
- [Changelog](../CHANGELOG.md) — source-tree change history
- [Upstream README reference](../UPSTREAM_README_REFERENCE.md) — preserved upstream-oriented product detail
- [Ask DeepWiki](https://deepwiki.com/ContextualWisdomLab/g7) — repository-aware navigation and questions

This landing page is documentation source only. GitHub Pages should be considered published only after repository settings, deployment state, and the live HTTPS site are independently verified.