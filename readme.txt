=== Pylon SEO ===
Contributors: bytenovo
Tags: seo, schema, sitemap, redirects, content analysis
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SEO plugin with schema markup, sitemaps, redirects, content analysis, image SEO, link assistant, and more.

== Description ==

Pylon SEO gives you everything you need to optimize your WordPress site for search engines. Built by Bytenovo, every core feature is free and fully functional — no locked features, no blurred pages, no limits.

= Core Features =

* **Meta Tags** — Edit titles, descriptions, Open Graph, Twitter Cards, canonical URLs, and robots meta per post. Full control over your `<head>` output.
* **XML Sitemaps** — Dynamic sitemaps with per-post-type pagination, priority and changefreq settings. Replaces WordPress core sitemap.
* **Schema Markup** — Injects JSON-LD for Article, Product, FAQ, LocalBusiness, BreadcrumbList, and more. Auto FAQ extraction from page headings.
* **Redirect Manager** — Create and manage 301/302/307/308/410/451 redirects. Built-in 404 monitor tracks hits per URL. Supports regex patterns. Import/export in CSV. 410/451 serve the correct HTTP status without redirecting.
* **LLMs.txt** — Publish a machine-readable /llms.txt and /llms-full.txt so AI engines (Google AI, ChatGPT, Perplexity) can understand your site.
* **Titles & Crawl Rules** — Optional Title Case conversion for generated titles and automatic noindex for password-protected pages.
* **Content Analysis** — On-page readability check with keyword density, heading structure, image alt attributes, and internal link analysis.
* **Image SEO** — Scan your media library for missing alt text, bad filenames, and oversized images. Edit alt text right from the table.
* **Link Assistant** — Smart internal link suggestions based on content similarity with one-click insertion.
* **Broken Link Checker** — Detect and fix broken links and outbound link issues.
* **Content Freshness** — Automatic stale content detection with freshness score (0-100). Daily cron identifies posts needing updates.
* **Author E-E-A-T** — Enhanced author profiles with photo, bio, credentials, and topical expertise. Outputs structured Person schema markup.
* **IndexNow Protocol** — Auto-submit URLs to IndexNow-compatible search engines on publish or update. Serves API key file.
* **Breadcrumbs** — Shortcode `[pylon_breadcrumb]` and Gutenberg block for Schema.org BreadcrumbList markup.
* **Local SEO** — LocalBusiness schema, location data, and an embedded map preview.
* **Robots.txt Editor** — Read and edit your robots.txt with automatic fallback to WordPress defaults.
* **HTML Sitemap** — Human-readable sitemap via `[pylon_html_sitemap]` with pages, posts, taxonomies, and CPTs.
* **Keyword Research** — Content gaps and AEO question ideas derived from your on-site coverage.
* **Multilingual / Hreflang** — Automatic hreflang for WPML, Polylang, TranslatePress, and Weglot.
* **Conflict Detector** — Warns when other SEO plugins would duplicate meta, schema, or sitemaps.
* **Site Verification** — Verify your site with Google, Bing, Yandex, Baidu, Pinterest, Norton, and Alexa in one place.
* **RSS Optimization** — Add custom content before and after feed items.
* **Migrator** — One-click import from Yoast, Rank Math, AIOSEO, SEOPress, Slim SEO, and 10+ other plugins.
* **SEO Pulse Dashboard** — At-a-glance health, usage stats, and quick actions in one place.
* **System Status** — Environment and plugin diagnostics for troubleshooting.

== Installation ==

1. Upload the `pylon-seo` folder to `/wp-content/plugins/` or install via **Plugins → Add New**.
2. Activate the plugin through the **Plugins** screen.
3. A 5-step onboarding wizard will guide you through initial setup.

= System Requirements =

* PHP 7.4 or higher
* WordPress 6.4 or higher
* MySQL 5.7+ or MariaDB 10.3+

== External Services ==

= IndexNow Protocol =

The IndexNow feature notifies IndexNow-compatible search engines when content is published or updated on your site, so they can crawl it sooner.

* What is sent: your site's host name, your IndexNow API key, and the URLs of published or updated posts.
* When: immediately after a post is published or updated, while the feature is enabled (Pylon SEO → IndexNow).
* The service is provided by the IndexNow protocol sponsors (Microsoft Bing, Naver, Seznam.cz, Yandex, and Yep). Submissions are made to the IndexNow endpoint and shared with participating search engines. The verification key file proving site ownership is hosted on your own site and accessed by participating search engines.
* Terms of use: https://www.indexnow.org/terms
* Privacy policy: https://www.indexnow.org/terms (the IndexNow Terms of Service include a privacy statement)

= OpenStreetMap =

The Local SEO module shows an embedded map preview in the admin settings using OpenStreetMap.

* What is sent: the latitude and longitude bounds of the configured location when the admin map preview is loaded.
* When: only when you open the Local SEO settings page with the map preview enabled.
* The service is provided by OpenStreetMap.
* Terms of use: https://www.openstreetmap.org/copyright
* Privacy policy: https://osmfoundation.org/wiki/Privacy_Policy

== Source Code ==

The complete source code of this plugin is publicly available at:
https://github.com/bytenovo/pylon-seo

All JavaScript and CSS shipped with this plugin is hand-written, human-readable source code. Nothing is compiled, bundled, minified, or generated by build tools — the files in `assets/js/` and `assets/css/` are the original, complete source and can be reviewed, studied, and modified directly:

* `assets/js/gutenberg-sidebar.js` — Gutenberg sidebar (plain ES5, no framework build)
* `assets/js/admin.js` — admin application logic
* `assets/css/admin.css` — shared admin styles
* `assets/css/modules/*.css` — per-module admin styles
* `assets/js/modules/*.js` — per-module scripts

There are no third-party runtime libraries, no npm/composer dependencies, and no minified `.min` files anywhere in the plugin.

== Frequently Asked Questions ==

= Can I import my settings from another SEO plugin? =

Yes. Navigate to Pylon SEO → Migrator and select your previous plugin. Pylon SEO supports imports from Yoast, Rank Math, AIOSEO, SEOPress, Slim SEO, and 10+ other plugins.

= What happens to my data if I deactivate Pylon SEO? =

All SEO meta fields remain in the database. Your content retains all existing titles, descriptions, and schema markup. Redirects and sitemaps will stop functioning until Pylon SEO is reactivated.

= Will Pylon SEO slow down my site? =

No. Frontend impact is minimal — only essential modules load on the frontend. Admin modules load exclusively in wp-admin. The redirect engine uses an in-memory cache, and dashboard stats are cached in short-term transients.

= Does Pylon SEO replace WordPress core sitemaps? =

Yes. Pylon SEO disables the WordPress core sitemap feature and replaces it with a more customizable XML sitemap engine with per-post-type pagination and priority settings.

== Screenshots ==

1. Dashboard — at-a-glance SEO health, usage stats, and quick actions.
2. Meta Editor — title and description editor with real-time preview.
3. Schema Builder — visual schema type selector with JSON-LD output.
4. Sitemap Manager — XML sitemap configuration with priority and changefreq controls.
5. Redirect Manager — add, edit, import, and export redirects with 404 tracking.
6. Content Analysis — readability and SEO checks in the post editor.
7. Image SEO — media library scan for alt text, filenames, and oversized images.
8. Content Freshness — dashboard showing stale posts with freshness scores.
9. Link Assistant — internal link suggestions with one-click insertion.

== Changelog ==

= 1.0.0 =
* Initial release.
* Meta tags engine with title, description, OG, Twitter Cards, canonical, robots.
* XML sitemap engine with per-type pagination and caching.
* Schema auto-generator supporting 10+ schema types.
* Redirect manager with 301/302/307/308/410/451 support and 404 monitor.
* Content analysis engine with readability and SEO checks.
* Image SEO scanner with inline alt text editing.
* Link assistant with internal link suggestions.
* Content freshness engine with stale content detection.
* Author E-E-A-T profiles with Person schema markup.
* IndexNow protocol support.
* Breadcrumbs shortcode and Gutenberg block.
* Local SEO settings with LocalBusiness schema.
* Migrator for importing from 15+ SEO plugins.
* Onboarding wizard for first-time setup.
* LLMs.txt generator for AI engines.
* Robots.txt editor.
* Multilingual / Hreflang support.
* Conflict Detector.
* Keyword Research hub.
* HTML Sitemap.
* SEO Pulse dashboard.
* Full i18n support with textdomain `pylon-seo`.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
