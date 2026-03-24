# F! Insights

**Version:** 1.0.0
**Requires:** WordPress 6.2+, PHP 8.0+
**Author:** Said Martínez Calderón
**License:** GPL-2.0+

AI-powered Google Business Profile scanner. Drop a shortcode on any page, let visitors scan any local business, capture leads, and deliver white-label reports — all from your WordPress dashboard.

---

## The Ecosystem

F! Insights is one of three WordPress plugins under the F! (Fricking) brand. Each deploys independently but they are designed to work together.

| Plugin | What it does | Who installs it |
|---|---|---|
| **F! Insights** | AI-powered Google Business Profile scanner. Drop a shortcode, scan any local business, capture leads, deliver white-label reports. | Agencies and freelancers on their own WordPress sites |
| **F! Branding** | AI-powered brand audit tool. 101 questions across 10 categories, two-pass Claude pipeline, shareable reports, lead capture. | Agencies and freelancers on their own WordPress sites |
| **F! Reviews** | Review collection and display tab inside F! Insights. Snippet-based deployment to local business owner sites. | Managed by agencies from inside F! Insights; snippet installed on local business owner sites |

All three plugins use Polar.sh as Merchant of Record for licensing.

---

## The Three-Tier User Model

Understanding who is who matters before touching this codebase.

**Tier 1 — Super admin (you, operating fricking.website)**
Can generate F! Reviews snippets for direct inquiries. Has full cross-account visibility in F! Reviews. Does not store analytics data for your clients' clients — only for snippets that attribute back to fricking.website directly.

**Tier 2 — Your clients (agencies, freelancers, consultants)**
Premium F! Insights license holders. Install F! Insights on their own WordPress site. Use it to scan local businesses, capture leads, manage prospects, run market intelligence, and deploy review snippets for their clients. They own the relationship with their local business clients entirely — F! Insights enforces no pricing or tier logic between them and their clients.

**Tier 3 — Your clients' clients (local business owners)**
Never log into F! Insights. Receive a snippet (one script tag) from your client and paste it into their site. Never need a Google API key, a Place ID, or any technical knowledge.

---

## Features

- **Instant credibility on demand** — visitors type a business name and get a live, scored audit backed by real Google data in seconds; nothing fabricated, nothing estimated
- **Eight-category scored report** — Online Presence, Reviews, Photos, Business Info, Competitive Position, Website Performance, Local SEO, and Page Speed, each graded with specific recommendations grounded in the business's actual data
- **Competitor context built in** — every report shows the three nearest businesses in the same category with their ratings and review counts, so prospects can see exactly where they stand
- **Turn every scan into a lead** — optional email gate delivers a branded report to the visitor and stores them as a lead in your dashboard; configurable fields including GDPR consent
- **A CRM that lives in WordPress** — sortable, inline-editable pipeline with status tracking, follow-up dates, notes, and a full report popup per record for both Leads and Prospects
- **Write cold outreach from real data** — one-click pitch generation for Prospects grounded in their specific scan gaps and competitor weaknesses; warm reply drafts for Leads referencing their own scores
- **Follow-up reminders that actually fire** — set a date on any lead or prospect and receive a digest email when it comes due, daily or weekly, in your site timezone
- **Bulk scan entire markets overnight** — import a CSV of businesses and process them via WP Cron with live progress monitoring, duplicate detection, and automatic stuck-item recovery
- **Market Analytics across your full database** — filterable industry breakdowns, score distributions, city maps, and pain-point aggregation to spot patterns across hundreds of scans
- **Market Intel powered by Claude** — eight AI analysis actions on your aggregated data: Industry Report, Competitive Gap Analysis, Pain Point Deep Dive, Local SEO Strategy, Score Directory, Lead Magnet, Outreach Script, and Full Market Forecast
- **Turn closed deals into deployed review tools** — when a lead is marked Closed, one click creates a Reviews record pre-filled from scan data; generate a snippet, QR code, and email template for that business without leaving the pipeline
- **Per-client feature toggles** — enable or disable the review button, QR display, review widget, display layout, star filter, sort order, and attribution independently per client; changes take effect on next snippet load with no reinstall
- **Surface-level link tracking** — add multiple tracking surfaces per client (website button, email template, in-store QR) each with its own tagged URL; views and clicks tracked separately per surface; IP exclusions apply so your own testing never skews counts
- **White-label from top to bottom** — your name, logo, and a full color system covering headers, buttons, body text, links, cards, and CTA
- **Shareable timed report links** — let anyone view a completed report without scanning again; tokens expire on your schedule
- **API budget protection built in** — per-IP rate limiting with proxy/CDN detection, IP exclusion list for testing, and smart result caching with a configurable TTL so repeat scans never burn API calls
- **Per-task model selection** — run Haiku for client-facing scans to keep costs low; run Sonnet or Opus for admin-side Market Intel where output quality matters more
- **Your keys, your data** — connect your own Google Places and Anthropic API keys and pay at their standard rates; every scan, lead, and report lives in your WordPress database

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.0 |
| WordPress | 6.2 |
| Google API key | Places API (New) enabled |
| Anthropic API key | Claude access (Haiku recommended) |

---

## Installation

1. Upload the `f-insights` folder to `/wp-content/plugins/`
2. Activate through **Plugins → Installed Plugins**
3. Go to **Settings → F-Insights → API Keys** and enter your Google Places and Anthropic API keys
4. Add `[f_insights]` to any page
5. Visit that page — the scanner is live

---

## Configuration

### API Keys
**Settings → F-Insights → API Keys**

- **Google Places API key** — must have the [Places API (New)](https://developers.google.com/maps/documentation/places/web-service/op-overview) enabled. Uses Text Search, Place Details, Nearby Search, Autocomplete, Geocoding, and PageSpeed Insights.
- **Anthropic Claude API key** — grades scans and powers Market Intel. Haiku recommended for scan reports. A separate model can be selected for admin-side Market Intel and outreach generation (Premium).

Both keys have a **Test Connection** button that validates the key live before you save.

### Shortcode
```
[f_insights]
```

### Shortcode Page
**Settings → F-Insights → Shortcode**

Assign the page where your shortcode lives. Used to generate share link base URLs.

### White Label *(Premium)*
**Settings → F-Insights → White Label**

Override brand name, logo URL, colors, CTA, email report title, and footer copy. Lead notification settings live in the Notifications tab.

### Lead Form *(Premium)*
**Market Leads → Lead Form**

Configure the optional email capture form:
- Toggle fields (First Name, Last Name, Phone, Business Role, No. of Employees, Custom Field) independently from marking them required
- Custom Field label autosaves without a Save button
- GDPR consent checkbox with custom text and privacy policy link
- Live preview updates in real time

### Notifications *(Premium)*
**Settings → F-Insights → Notifications**

**New Lead Alerts** — email on organic lead capture. Configure alert email and score threshold (100 = every lead; lower = high-need only).

**Follow-Up Reminder Digest** — scheduled email for due follow-up dates. Daily (8am) or Weekly (Monday 8am) in the site timezone. Closed/Lost records excluded. Changing a follow-up date resets the reminder automatically.

### Caching
**Settings → F-Insights → Cache** — 24-hour default TTL. Errored scans never cached.

### Rate Limiting
**Settings → F-Insights → Rate Limiting** — per-IP scan throttling.

### IP Exclusions
**Settings → F-Insights → IP Exclusions** — IPs excluded from rate limiting and Reviews tracking counts.

---

## Bulk Scan

**Market Leads → Bulk Scan**

1. Prepare a CSV (see format below)
2. Upload or drag-and-drop onto the upload zone
3. Review the parsed preview and cost estimate
4. Click **Confirm & Start Scanning**
5. Scans process via WP Cron at one per 30-second tick
6. Completed businesses are added as Prospects in the pipeline

### CSV Format

Required: `name`, `address`, `city`, `state`, `postal_code`
Optional: `country` (ISO 3166-1 alpha-2 — `US`, `GB`, `FR`, `CA`, `AU`, etc.)

```
name,address,city,state,postal_code,country
Don Juan Restaurant,965 Thayer Ave,Silver Spring,MD,20910,US
The Breakfast Club,42 Chandos Place,London,England,WC2N 4HS,GB
```

Invalid `country` values (not exactly two letters) are silently dropped. Download the sample CSV from the upload zone for a complete example.

### Duplicate Detection

The validator checks each name against the scans database before confirmation. Non-expired cached scans are flagged as duplicates with their score and expiry. A "Force rescan" checkbox overrides them.

### Stuck Scan Recovery

Items stuck in `scanning` for 3+ minutes are automatically reset to `queued` on the next cron tick. For immediate control:

- **⚠ Kill Stuck** — in the monitor header; force-fails all stuck items at once
- **Kill** — per-row button on each actively-scanning item

### Monitor Panel

Real-time right-hand stats: Est. Time Remaining, Avg. Time / Scan, Completed, Failed, Currently Scanning (business name + rotating phase label), timestamped Activity log (last 8 events).

---

## Lead Pipeline

**Market Leads → Leads**

- **Leads** — organic email captures. Status: New → Contacted → Qualified → Closed / Lost
- **Prospects** — bulk-imported businesses. Status: Uncontacted → Contacted → Qualified → Closed / Lost

Inline editing in the table (no separate edit screen):
- Status, Follow-up date, Notes — all save automatically with a green flash
- Click business name → full report in an iframe modal
- Sortable columns: Business, Email (Leads), Score, Top Pain Points, Status

**Outreach:** ✉ Pitch on Prospects (cold, data-grounded); ✉ Reply Draft on Leads (warm, report-aware). Both generate a subject line and copy-to-clipboard includes it.

---

## Market Intel

**Market Leads → Market Intel** — requires 10+ scans.

| Action | Min scans | Description |
|---|---|---|
| Industry Report | 10 | Category breakdown with opportunity scoring |
| Competitive Gap Analysis | 25 | Where local businesses consistently fall short |
| Pain Point Deep Dive | 25 | Most common issues across your scan data |
| Local SEO Strategy | 50 | Keyword and content angle recommendations |
| Score Directory | 25 | Publish a public business ranking page |
| Lead Magnet | 50 | Data-backed content asset ideas |
| Outreach Script | 25 | Cold email copy from scan pain points |
| Full Market Forecast | 100 | Long-form strategic report |

---

## Polar.sh Integration

Polar acts as the Merchant of Record — handling checkout, international tax compliance, automatic license key delivery, and subscription lifecycle management.

### Setup

1. **Organization ID** — find it at Polar Dashboard → Settings → General → "Organization ID". Enter it under Settings → F-Insights → API Keys → Polar.sh Integration Settings.
2. **Checkout URL** — your product's Polar checkout link (Polar Dashboard → Products → your product → Checkout Links). All "Buy Premium" buttons in the plugin link here.
3. **Webhook Endpoint** — copy the read-only Webhook Endpoint URL from the API Keys tab and paste it into Polar Dashboard → Settings → Webhooks → Add Endpoint. Subscribe to: `order.paid`, `order.refunded`, `subscription.active`, `subscription.revoked`, `subscription.canceled`, `benefit_grant.created`.
4. **Webhook Secret** — after saving the endpoint, copy the generated secret back into the Webhook Secret field.
5. **Organization Access Token** *(optional)* — for admin-level Polar API calls. Not required for license validation or webhooks.

### License Activation

After purchase, the customer's license key appears in their Polar customer portal under Benefits → License Keys. They paste it into **Settings → F-Insights → API Keys → License Key** and click Activate.

---

## Roadmap

Features are listed in priority order. The F! Reviews snippet server is the only remaining blocker before Reviews is customer-facing — the WordPress side is complete.

### Next: F! Reviews snippet server (fricking.website infrastructure)

The WordPress implementation is done. What still needs to be built is the hosted validation endpoint.

- `GET /reviews/snippet.js?token=TOKEN` — validates license state and domain match; serves the widget JS for active records; serves graceful fallback for lapsed/archived/mismatched records
- Analytics ingestion for fricking.website-attributed snippets (direct inquiries and fallback states) — white-labeled client snippets store tracking locally and never call this
- Super admin dashboard on fricking.website — cross-account snippet visibility, domain, license state, last-seen, attribution status
- Cloudflare Worker is the likely starting point given the validation logic is simple and latency matters for snippet load time

### CRM and Automation Integrations *(Premium)*

Push leads and prospects from the F! Insights pipeline into the tools your clients are already running — without leaving WordPress and without manual exports.

**Zapier**
- Webhook triggers for key pipeline events: new lead captured, lead status changed, prospect imported from bulk scan, follow-up date set
- Send full scan data (business name, score, pain points, category, address, phone, website) as Zapier fields
- Connect to 7,000+ apps — push to Mailchimp, Slack, Notion, Airtable, Google Sheets, ActiveCampaign, or any tool in a client's stack
- No Zapier account required on the WordPress side — triggered via outbound webhook; client configures the receiving Zap

**Make (formerly Integromat)**
- Same webhook trigger model as Zapier, compatible with Make's HTTP module out of the box
- Structured JSON payload maps cleanly to Make's data store and router modules
- Lets clients build multi-step automations: new prospect → enrich in Clearbit → push to CRM → notify team in Slack

**HubSpot**
- Direct OAuth connection — no Zapier or Make needed in the middle
- New leads and prospects sync as HubSpot Contacts automatically on capture
- Pain points map to a custom HubSpot Contact property (`fi_pain_points`)
- Overall score maps to a custom property (`fi_score`) for use in HubSpot lists and workflows
- Lead status changes in F! Insights optionally update the associated HubSpot Deal stage
- Two-way: HubSpot Deal stage changes can optionally write back to F! Insights lead status via webhook
- Per-account OAuth — each F! Insights license holder connects their own HubSpot account; no shared credentials

All three integrations are configured per-site under **Settings → F-Insights → Integrations** (tab not yet built). Each integration has an enable/disable toggle and a test connection button consistent with the existing API Config pattern.

### F! Insights + F! Branding Integration

When both plugins are active, Brand Intel in F! Branding can incorporate F! Insights scan data for richer market context. Each plugin runs independently; the integration is additive and opt-in.

### F! Reviews — Future Toggle Additions

The toggle architecture is additive — new features add new toggles without affecting existing records.

- Review response prompts (show business owner reply alongside review)
- Review age filter (show only reviews from last N months)
- Structured data / Schema.org output for SEO
- Floating widget variant (fixed position on page, not inline)
- Review count badge (aggregate score and count, links to full widget)

---

## Developer Notes

### Production Checklist

1. Set `FI_DEV_MODE` to `false` in `f-insights.php` before distributing — while `true`, all premium features are unlocked for every admin user without a license check
2. Configure Polar Organization ID and Checkout URL under Settings → API Keys
3. Register your webhook endpoint in Polar and paste the secret back into the plugin
4. Ensure `DISABLE_WP_CRON` is not `true` in `wp-config.php`, or set up a real system cron — bulk scan jobs will not process without cron firing

### Constants

| Constant | Default | Description |
|---|---|---|
| `FI_VERSION` | `1.0.0` | Plugin version, appended to asset URLs |
| `FI_DEV_MODE` | `false` | Bypasses premium license check when `true` |
| `FI_DB_VERSION` | `4.5` | DB schema version; triggers `create_tables()` on mismatch |
| `FI_LOG_DIR` | `wp-content/fi-insights-logs/` | Daily debug log directory |

### Hooks

```php
add_filter( 'fi_force_load_assets', '__return_true' );
add_filter( 'fi_website_check_sslverify', '__return_false' ); // disable SSL verification for website health checks if needed
```

### Code Style

- PHP 8.0+ features throughout (`str_contains`, named arguments, `match` expressions)
- Class-per-file: `class-fi-*.php`; admin tabs under `includes/admin/`
- AJAX actions prefixed `fi_`
- All assets versioned with `FI_VERSION` for cache busting
- No em dashes, en dashes, or hyphens as visual separators in any user-facing string

### Class Overview

| Class | File | Role |
|---|---|---|
| `FI_Utils` | `class-fi-utils.php` | `score_color()`, `cat_labels()`, `extract_pain_points()` |
| `FI_Scan_Runner` | `class-fi-scan-runner.php` | Full scan pipeline orchestration |
| `FI_Grader` | `class-fi-grader.php` | Claude prompt builder and report JSON parser |
| `FI_Claude` | `class-fi-claude.php` | Anthropic API wrapper |
| `FI_Google` | `class-fi-google.php` | Google Places API (New) wrapper |
| `FI_Bulk_Scan` | `class-fi-bulk-scan.php` | Cron batch processor, stuck-item recovery, kill handlers, bulk AJAX endpoints |
| `FI_Analytics` | `class-fi-analytics.php` | Market Intel Claude calls |
| `FI_Analytics_Page` | `class-fi-analytics-page.php` | Admin page renderer (Lead Form, Leads, Analytics, Market Intel, Bulk Scan, Reviews) |
| `FI_DB` | `class-fi-db.php` | All database reads and writes |
| `FI_Ajax` | `class-fi-ajax.php` | AJAX endpoint registration and handlers |
| `FI_Shortcode` | `class-fi-shortcode.php` | `[f_insights]` shortcode and shared report rendering |
| `FI_Share` | `class-fi-share.php` | Share link creation and token resolution |
| `FI_Leads` | `class-fi-leads.php` | Lead record creation and CSV export |
| `FI_Reviews` | `class-fi-reviews.php` | Reviews record CRUD, snippet generation, QR URL builder, email template, tracking event recording *(premium)* |
| `FI_Email` | `class-fi-email.php` | Report email and admin alert sending |
| `FI_Pitch` | `class-fi-pitch.php` | `generate()` cold prospect pitch; `generate_reply()` warm lead follow-up |
| `FI_Polar` | `class-fi-polar.php` | Polar.sh webhook verification, license validation, premium grant/revoke |
| `FI_Premium` | `class-fi-premium.php` | License check and feature gate |
| `FI_Rate_Limiter` | `class-fi-rate-limiter.php` | Per-IP scan throttling |
| `FI_Cache` | `class-fi-cache.php` | Transient cache helpers |
| `FI_Logger` | `class-fi-logger.php` | Debug logging to daily files |
| `FI_Taxonomy` | `class-fi-taxonomy.php` | Google Place type to human label mapping |
| `FI_Followup_Reminder` | `class-fi-followup-reminder.php` | WP Cron follow-up digest email (daily + weekly) |
| `FI_Activator` | `class-fi-activator.php` | Activation: DB tables, defaults, cron scheduling |
| `FI_Deactivator` | `class-fi-deactivator.php` | Deactivation: cron unscheduling |

### Admin Tab Classes

| Class | Renders |
|---|---|
| `FI_Admin_Tab_Api` | Google + Anthropic keys, connection tests, model selector, Polar.sh settings |
| `FI_Admin_Tab_Cache` | Cache TTL, manual clear |
| `FI_Admin_Tab_IpExclusions` | IP exclusion list |
| `FI_Admin_Tab_LeadForm` | Capture form fields, live preview, GDPR consent *(premium)* |
| `FI_Admin_Tab_Notifications` | Lead alerts + follow-up reminder digest *(premium)* |
| `FI_Admin_Tab_RateLimiting` | Per-IP throttle settings |
| `FI_Admin_Tab_Reviews` | Reviews record list, record detail, feature toggles, snippet/QR/email delivery, tracking surfaces *(premium)* |
| `FI_Admin_Tab_Shortcode` | Shortcode page assignment |
| `FI_Admin_Tab_White_Label` | Brand colors, logo, copy, CTA *(premium)* |

### Database Schema (DB v4.5)

| Table | Purpose |
|---|---|
| `fi_scans` | Cached scan results, full report JSON, score, expiry |
| `fi_leads` | Lead/prospect records: status, follow-up date, notes, snapshots |
| `fi_shares` | Share link tokens with expiry |
| `fi_scan_jobs` | Bulk job metadata: status, counters, token usage |
| `fi_scan_queue` | Per-item queue rows; includes `scan_started_at` for stuck detection |
| `fi_review_records` | One row per local business client: snippet token, domain, feature toggles, display config, attribution, multi-location JSON |
| `fi_review_tracking` | One row per tracking surface per record: label, param, view count, click count |

### F! Reviews — Market Leads → Reviews

Review records are created from the Leads pipeline when a lead or prospect is marked **Closed**. Records are never created automatically from scans — only promoted manually. This keeps the Reviews dashboard clean; test scans and unconverted prospects stay in the pipeline.

**Record lifecycle:**
- `active` — snippet renders all enabled features; tracking counts accrue
- `archived` — snippet fires the fricking.website fallback message; data retained; restore at any time

**Snippet validation:** each snippet makes a server-side call to `fricking.website/reviews/snippet.js?token=TOKEN` on load. The endpoint validates license state and domain match. If either fails the fallback renders. This endpoint is not yet live — it is the next infrastructure piece to build before Reviews is customer-facing.

**IP exclusions:** the same `fi_excluded_ips` option used by the scanner rate limiter is reused for tracking event filtering. Excluded IPs do not increment view or click counts.

**Analytics scope:** `fi_review_tracking` is local to each client's WordPress install. `fricking.website` does not receive tracking data for active white-labeled snippets — only for snippets it directly attributed (direct inquiries and lapsed/fallback states).

### AJAX Actions

| Action | Handler | Description |
|---|---|---|
| `fi_scan` | `FI_Ajax::handle_scan` | Run a single scan |
| `fi_email_report` | `FI_Ajax::handle_email_report` | Capture lead email, send report |
| `fi_create_share` | `FI_Ajax::handle_create_share` | Generate a share link |
| `fi_view_lead_snapshot` | `FI_Ajax::handle_view_lead_snapshot` | Render snapshot as standalone HTML |
| `fi_run_market_intel` | `FI_Ajax::handle_run_market_intel` | Execute a Market Intel action |
| `fi_update_lead_status` | `FI_Ajax::handle_update_lead_status` | Save pipeline status change |
| `fi_save_lead_notes` | `FI_Ajax::handle_save_lead_notes` | Autosave pipeline notes |
| `fi_set_followup_date` | `FI_Ajax::handle_set_followup_date` | Save follow-up date, reset reminder |
| `fi_save_custom_label` | `FI_Ajax::handle_save_custom_label` | Autosave custom field label |
| `fi_export_leads` | `FI_Ajax::handle_export_leads` | Stream leads CSV download |
| `fi_generate_pitch` | `FI_Ajax::handle_generate_pitch` | Cold outreach for prospect |
| `fi_generate_reply` | `FI_Ajax::handle_generate_reply` | Warm reply draft for lead |
| `fi_bulk_start` | `FI_Bulk_Scan::ajax_start` | Create job, insert queue, spawn cron |
| `fi_bulk_pause` | `FI_Bulk_Scan::ajax_pause` | Pause running job |
| `fi_bulk_resume` | `FI_Bulk_Scan::ajax_resume` | Resume paused job |
| `fi_bulk_cancel` | `FI_Bulk_Scan::ajax_cancel` | Cancel job permanently |
| `fi_bulk_retry_item` | `FI_Bulk_Scan::ajax_retry_item` | Reset failed item to queued |
| `fi_bulk_kill_item` | `FI_Bulk_Scan::ajax_kill_item` | Force-fail a single stuck item |
| `fi_bulk_kill_stuck` | `FI_Bulk_Scan::ajax_kill_stuck` | Force-fail all scanning items in a job |
| `fi_bulk_poll` | `FI_Bulk_Scan::ajax_poll` | Return job state + all queue items |
| `fi_reviews_create` | `FI_Ajax::handle_reviews_create` | Create a Reviews record from a closed lead *(premium)* |
| `fi_reviews_update_field` | `FI_Ajax::handle_reviews_update_field` | Autosave a single field on a Reviews record *(premium)* |
| `fi_reviews_add_surface` | `FI_Ajax::handle_reviews_add_surface` | Add a tracking surface to a record *(premium)* |
| `fi_reviews_delete_surface` | `FI_Ajax::handle_reviews_delete_surface` | Remove a tracking surface *(premium)* |
| `fi_reviews_archive` | `FI_Ajax::handle_reviews_archive` | Archive a record; snippet fires fallback *(premium)* |
| `fi_reviews_restore` | `FI_Ajax::handle_reviews_restore` | Restore an archived record *(premium)* |

---

## License

F! Insights is free software released under the GPL-2.0+ license. Premium features require a separate commercial license purchased through Polar.sh.
