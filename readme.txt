=== F! Insights ===
Contributors: saidmartinezcalderon
Tags: google business profile, local seo, lead generation, ai, business scanner, bulk scan, crm, white label, zapier, hubspot
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered Google Business Profile scanner. Drop a shortcode on any page, let visitors scan any local business, capture leads, and build your market intelligence database — all from WordPress.

== Description ==

Drop one shortcode. Get a lead generation machine that runs on your own Google and Claude API keys — no middlemen, no markup, no data leaving your server.

= What it does =

A visitor types a business name. F! Insights pulls live data from Google — ratings, reviews, hours, photos, website, category, address, competitors — and sends it through Claude for analysis. Seconds later, they're reading a scored audit of their own business with specific recommendations they can actually act on.

That's your hook. You deliver the value. They give you their email. Now you have a warm prospect who already trusts your analysis.

= What you get =

* A scanner widget on any page via one shortcode: [f_insights]
* Live data from Google Business Profile on every scan — nothing is fabricated or estimated
* Eight scored categories: Online Presence, Reviews and Reputation, Photos and Media, Business Information Completeness, Competitive Position, Website Performance, Local SEO Signals, and Page Speed
* An overall score out of 100 with specific, data-grounded recommendations for every category
* A competitor comparison showing the three nearest businesses in the same category so your prospect sees exactly where they stand
* Configurable lead capture form — first name, last name, phone, business role, team size, custom field; GDPR consent with custom text and privacy policy link
* Branded email delivery of the full report at capture using your name, logo, and colors
* Shareable timed report links so prospects can forward their results to their team
* A CRM that lives in WordPress — inline status editing, follow-up dates, notes, and a full report popup per record for both Leads and Prospects
* Cold pitch generation for Prospects grounded in that business's specific scan gaps and competitor weaknesses
* Warm reply draft for Leads referencing the scores and recommendations they already received
* Follow-up reminder digest — set a date on any lead, get a digest email when it comes due, daily or weekly in your site timezone
* Market Analytics across your full scan database — industry breakdowns, score distributions, city maps, pain-point aggregation
* Market Intel — eight Claude-powered analysis actions on your aggregated data, filterable by industry and date range
* Bulk scan — import a CSV, process hundreds of businesses overnight via WP Cron with live progress monitoring, duplicate detection, and automatic stuck-item recovery
* F! Reviews (Premium) — when a lead is marked Closed, one click creates a Reviews record pre-filled from scan data; generate a snippet, QR code, and email template; per-client feature toggles; surface-level tracking with separate tagged URLs per placement
* White-label branding — your name, logo, and a full color system covering headers, buttons, body text, links, cards, and CTA
* Rate limiting per IP with proxy/CDN detection; IP exclusion list for testing; exclusions also apply to Reviews tracking so your own testing never skews counts
* Smart result caching with configurable TTL so repeat scans never burn API calls
* Per-task Claude model selection — Haiku for client-facing scans, Sonnet or Opus for admin-side Market Intel

= Your keys, your data =

You connect your own Google Places API key and Anthropic API key. You pay Google and Anthropic directly at their standard rates. Every scan, every lead, every report lives in your WordPress database. Nothing is routed through our servers.

= How licensing works =

The scanner, reports, caching, and rate limiting are free. Lead capture, email delivery, white-label branding, outreach generation, the pipeline CRM, Market Intel, bulk scan, F! Reviews, and admin notifications require a Premium license issued through Polar.sh. After purchase, your key is in the Polar customer portal under Benefits → License Keys — paste it into Settings → API Config and activate.

= What's coming =

**CRM and automation integrations (Premium)**

Push leads and prospects from the F! Insights pipeline into the tools your clients are already running — without manual exports.

* Zapier — trigger Zaps on key pipeline events (new lead captured, status changed, prospect imported, follow-up date set); send full scan data including score, pain points, category, phone, and website to 7,000+ connected apps
* Make (formerly Integromat) — same webhook trigger model; structured JSON payload works with Make's HTTP module, data store, and router out of the box
* HubSpot — direct OAuth connection; new leads and prospects sync as HubSpot Contacts automatically; pain points and score map to custom Contact properties; optional two-way status sync between F! Insights pipeline stages and HubSpot Deal stages

All three configured under Settings → F-Insights → Integrations once the tab is built.

**F! Reviews snippet server**

The WordPress side of F! Reviews is complete. The hosted validation endpoint that serves the snippet and confirms license state and domain match is the next piece to build. Once live, F! Reviews becomes customer-facing.

**F! Insights + F! Branding**

When both plugins are active, Brand Intel in F! Branding can incorporate F! Insights scan data for richer market context. Each plugin runs independently; the integration is opt-in and additive.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ or install via the WordPress plugin screen.
2. Activate the plugin.
3. Go to F! Insights in the admin sidebar.
4. Enter your Google Places API key and Anthropic API key in Settings → API Config.
5. Add [f_insights] to any page or post.
6. Visit the page and run a test scan.

== Frequently Asked Questions ==

= What Google APIs do I need to enable? =

The Google Places API (New) must be enabled in your Google Cloud Console. The plugin uses Text Search, Place Details, Nearby Search, Autocomplete, Geocoding, and PageSpeed Insights. Enable all of them under the same key.

= Which Claude model should I use for scans? =

Haiku for client-facing scans — it is fast, inexpensive (roughly $0.02 per scan), and fully capable for report generation. With Premium you can configure a separate model for Market Intel and outreach generation, where Sonnet or Opus is worth the cost.

= How does bulk scan work? =

Import a CSV with business name, address, city, state, and postal code. The validator estimates API costs, detects duplicates against your existing database, and shows a confirmation screen before anything runs. Confirmed jobs process one business per 30-second WP Cron tick. A live monitor shows ETA, completed and failed counts, and which business is currently scanning. Items stuck for more than 3 minutes are automatically recovered.

= What CSV format does bulk scan require? =

Required columns: name, address, city, state, postal_code. Optional: country (two-letter ISO code — US, GB, CA, AU, etc.). Download the sample CSV from the upload zone in the Bulk Scan tab for a complete example.

= How does duplicate detection work? =

Before confirming a bulk job, the validator checks every business name against your scan database. Businesses with a non-expired cached scan are flagged as duplicates with their score and cache expiry shown. A Force Rescan checkbox overrides the flag.

= Can I push leads directly to HubSpot, Zapier, or Make? =

Not yet — these integrations are on the roadmap. Once built, they will be configured under Settings → Integrations and will cover new lead capture, status changes, and prospect imports from bulk scan. HubSpot will connect via OAuth and sync leads as Contacts with scan data mapped to custom properties. Zapier and Make will use outbound webhooks on your end with no account required on the WordPress side.

= Does the plugin phone home? =

No scan data, no lead data, and no report data is sent to our servers. The only outbound calls are to Google's Places API (for business data), Anthropic's API (for scoring and Market Intel), and Polar.sh (for license validation via webhook on purchase events). F! Reviews snippets make a validation call to fricking.website to verify license state and domain match on each page load — no tracking data is sent in that call.

= Is the plugin ready for production? =

Set FI_DEV_MODE to false in f-insights.php before distributing to clients. While true, all premium features are unlocked for every admin user without a license check.

= Can I run multiple instances on different client sites? =

Yes. Each WordPress install has its own API keys, its own scan database, and its own license key. Licenses are per-site.

= How does Polar licensing work? =

Polar is the Merchant of Record — they handle checkout, international tax compliance, license key delivery, and subscription lifecycle via HMAC-verified webhooks. After purchase, your key is under Benefits → License Keys in the Polar customer portal.

== Screenshots ==

1. Scanner widget — business name autocomplete and live scan in progress
2. Scored report — eight categories, competitor context, priority actions
3. Lead pipeline — inline status, follow-up dates, notes, report popup
4. Bulk Scan — CSV upload, cost estimator, live monitor with activity log
5. Market Intel — action launcher with progressive data thresholds
6. F! Reviews — per-client record with feature toggles and tracking surfaces
7. White Label — brand color system and logo configuration

== License ==

F! Insights is free software released under the GPL-2.0+ license. Premium features require a separate commercial license purchased through Polar.sh.
