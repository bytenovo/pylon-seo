# Pylon SEO

SEO plugin for WordPress with schema markup, sitemaps, redirects, content analysis, image SEO, link assistant, and more. Built by [Bytenovo](https://bytenovo.com) — every core feature is free and fully functional. No locked features, no blurred pages, no limits.

* Requires WordPress 6.4+ / PHP 7.4+
* License: GPLv2 or later
* Author: Bytenovo

## Features

### Content & Metadata

| Feature | Description |
| --- | --- |
| Meta Tags | Edit titles, descriptions, Open Graph, Twitter Cards, canonical URLs, and robots meta per post. Full control over `<head>` output. |
| Schema Markup | Injects JSON-LD for Article, Product, FAQ, LocalBusiness, BreadcrumbList, and more. Auto FAQ extraction from page headings. |
| Author E-E-A-T | Enhanced author profiles with photo, bio, credentials, and topical expertise. Outputs structured Person schema markup. |
| Breadcrumbs | Shortcode `[pylon_breadcrumb]` and Gutenberg block for Schema.org BreadcrumbList markup. |
| Local SEO | LocalBusiness schema, location data, and an embedded map preview. |
| Multilingual / Hreflang | Automatic hreflang for WPML, Polylang, TranslatePress, and Weglot. |
| Social previews | Open Graph and Twitter Card tags generated from post data or manual overrides. |

### Technical SEO

| Feature | Description |
| --- | --- |
| XML Sitemaps | Dynamic sitemaps with per-post-type pagination, priority and changefreq settings. Replaces the WordPress core sitemap. Includes news and video sitemaps. |
| Redirect Manager | 301/302/307/308/410/451 redirects with regex support, CSV import/export, and a built-in 404 monitor that tracks hits per URL. 410/451 serve the correct HTTP status without redirecting. |
| Robots.txt Editor | Read and edit your robots.txt with automatic fallback to WordPress defaults. |
| LLMs.txt | Publish machine-readable `/llms.txt` and `/llms-full.txt` so AI engines (Google AI, ChatGPT, Perplexity) can understand your site. |
| IndexNow Protocol | Auto-submit URLs to IndexNow-compatible search engines on publish or update. Serves the API key verification file from your own site. |
| Site Verification | Verify your site with Google, Bing, Yandex, Baidu, Pinterest, Norton, and Alexa in one place. |
| Titles & Crawl Rules | Optional Title Case conversion for generated titles and automatic noindex for password-protected pages. |
| RSS Optimization | Add custom content before and after feed items. |
| HTML Sitemap | Human-readable sitemap via `[pylon_html_sitemap]` with pages, posts, taxonomies, and custom post types. |

### Content Tools

| Feature | Description |
| --- | --- |
| Content Analysis | On-page readability check with keyword density, heading structure, image alt attributes, and internal link analysis. |
| Image SEO | Scan the media library for missing alt text, bad filenames, and oversized images. Edit alt text inline. |
| Link Assistant | Smart internal link suggestions based on content similarity with one-click insertion. |
| Broken Link Checker | Detect and fix broken internal links and outbound link issues. |
| Content Freshness | Automatic stale content detection with a 0-100 freshness score. Daily cron identifies posts needing updates. |
| Keyword Research | Content gaps and AEO question ideas derived from your on-site coverage. |

### Administration

| Feature | Description |
| --- | --- |
| Migrator | One-click import from Yoast, Rank Math, AIOSEO, SEOPress, Slim SEO, and 15+ other plugins. |
| Conflict Detector | Warns when other SEO plugins would duplicate meta, schema, or sitemaps. |
| SEO Pulse Dashboard | At-a-glance health, usage stats, and quick actions in one place. |
| Onboarding Wizard | Five-step wizard that guides you through initial setup. |
| System Status | Environment and plugin diagnostics for troubleshooting. |
| Role Manager | Control which roles can access Pylon SEO screens. |

## Installation

1. Upload the `pylon-seo` folder to `/wp-content/plugins/` or install via **Plugins -> Add New**.
2. Activate the plugin through the **Plugins** screen.
3. The onboarding wizard guides you through initial setup.

### System Requirements

* PHP 7.4 or higher
* WordPress 6.4 or higher
* MySQL 5.7+ or MariaDB 10.3+

## External Services

**IndexNow** — notifies IndexNow-compatible search engines (Microsoft Bing, Naver, Seznam.cz, Yandex, Yep) when content is published or updated. Sends your host name, API key, and changed URLs while the feature is enabled. [Terms of use](https://www.indexnow.org/terms)

**OpenStreetMap** — the Local SEO settings page loads an embedded map preview using configured latitude/longitude bounds. Loaded only when you open the settings page. [Terms of use](https://www.openstreetmap.org/copyright) / [Privacy policy](https://osmfoundation.org/wiki/Privacy_Policy)

No other external services are contacted. There are no telemetry, license, or upsell endpoints.

## Source Code

All JavaScript and CSS shipped with this plugin is hand-written, human-readable source code. Nothing is compiled, bundled, minified, or generated by build tools — the files under `assets/js/` and `assets/css/` are the original, complete source and can be reviewed, studied, and modified directly. This repository contains the full, unmodified source of the plugin as distributed on WordPress.org.

```
pylon-seo/
├── pylon.php                  # Plugin bootstrap and constants
├── core/                      # Shared engine classes (HTTP client, JSON-LD, charts, ...)
├── modules/                   # One folder per feature module
│   ├── admin/                 # Admin shell, menus, asset loading
│   ├── meta/                  # Meta tags engine
│   ├── schema/                # JSON-LD schema generator
│   ├── redirects/             # Redirect manager and 404 monitor
│   ├── sitemap/               # XML sitemap engine (+ news, video, XSL)
│   ├── ...                    # Remaining feature modules
│   └── gutenberg/             # Gutenberg sidebar and breadcrumb block
├── assets/
│   ├── css/                   # admin.css + per-module stylesheets (hand-written)
│   └── js/                    # admin.js, gutenberg-sidebar.js, per-module scripts (hand-written)
├── languages/                 # Translations (textdomain: pylon-seo)
└── docs/
```

There are no third-party runtime libraries, no npm/composer dependencies, and no minified `.min.*` files anywhere in the plugin. No build step is required — clone or download this repository and it runs as-is.

## Frequently Asked Questions

**Can I import my settings from another SEO plugin?**
Yes — go to Pylon SEO -> Migrator and select your previous plugin. Imports from Yoast, Rank Math, AIOSEO, SEOPress, Slim SEO, and more are supported.

**What happens to my data if I deactivate Pylon SEO?**
All SEO meta fields remain in the database. Your content keeps every title, description, and schema value. Redirects and sitemaps stop functioning until reactivation.

**Will Pylon SEO slow down my site?**
No. Only essential modules load on the frontend; admin modules load exclusively in wp-admin. The redirect engine uses an in-memory cache and dashboard stats are cached in short-term transients.

**Does Pylon SEO replace the WordPress core sitemap?**
Yes. It disables the core sitemap and replaces it with a customizable XML sitemap engine with per-post-type pagination and priority settings.

## Changelog

### 1.0.0
* Initial release.
* See `readme.txt` for the full list.

## License

Pylon SEO is licensed under the [GNU General Public License v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
