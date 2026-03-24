# F! Insights — Product Roadmap & Expansion Context
**For AI model reference. Last updated: March 2026.**

---

## What this document is

This is a living brief for any AI model working on the F! Insights ecosystem. It covers the product architecture, the three-tier user model, all current and planned features, design decisions that have already been made, and the reasoning behind them. Read this before writing any code, feature copy, or product decisions for this project.

---

## The ecosystem at a glance

Three WordPress plugins under the F! (Fricking) brand, all built by the same author, designed to work together but deployable independently.

| Plugin | What it does | Who installs it |
|---|---|---|
| **F! Insights** | AI-powered Google Business Profile scanner. Drop a shortcode, scan any local business, capture leads, deliver white-label reports. | Your clients (agencies, freelancers) on their own WordPress sites |
| **F! Branding** | AI-powered brand audit tool. 101 questions across 10 categories, two-pass Claude pipeline, shareable reports, lead capture. | Your clients on their own WordPress sites |
| **F! Reviews** | Review collection and display tab inside F! Insights. Snippet-based deployment to local business owner sites. | Managed by your clients from inside F! Insights; snippet installed on local business owner sites |

The author (super admin) operates from **fricking.website**. All three plugins use Polar.sh as Merchant of Record for licensing.

---

## The three-tier user model

Understanding who is who is essential before touching anything in this codebase.

**Tier 1 — You (super admin)**
- Operates fricking.website
- Can generate F! Reviews snippets for direct inquiries (local business owners who find fricking.website themselves)
- Has full cross-account visibility in F! Reviews
- Does not store analytics data for your clients' clients — only for snippets that attribute back to fricking.website directly
- Sets default attribution for fricking.website-generated snippets

**Tier 2 — Your clients (agencies, freelancers, consultants)**
- Premium F! Insights license holders
- Install F! Insights on their own WordPress site
- Use F! Insights to scan local businesses, capture leads, manage prospects, run market intelligence
- Use the Reviews page (F! Insights → Market Leads → Reviews) to generate and manage snippets for their local business clients
- Decide independently what features to enable per client, what to charge, and how to structure their own plans
- Own the relationship with their local business clients entirely — F! Insights does not enforce any pricing or tier logic between your clients and their clients
- Their analytics (link views vs clicks per snippet) stay on their own install — you do not collect this data

**Tier 3 — Your clients' clients (local business owners)**
- Never log into F! Insights
- Receive a snippet (one script tag) from your client, paste it into their site
- The snippet renders whatever features your client has enabled for their record
- Never need a Google API key, a Place ID, or any technical knowledge
- Their customers (the public) interact with the rendered widget

---

## F! Insights — current feature state (v1.0.0)

### Core
- `[f_insights]` shortcode embeds the scanner on any WordPress page
- Visitor types a business name → live Google Business Profile data fetched → Claude grades it across 8 categories → scored report rendered
- Eight graded categories: Online Presence, Reviews and Reputation, Photos and Media, Business Information Completeness, Competitive Position, Website Performance, Local SEO Signals, Page Speed
- Overall score out of 100 with grade label and per-category recommendations
- Competitor comparison: three nearest businesses in same category with ratings and review counts

### Lead capture (premium)
- Optional email gate before full report delivery
- Configurable form fields: First Name, Last Name, Phone, Business Role, No. of Employees, Custom Field
- Fields toggled and required independently
- GDPR consent checkbox with custom text and privacy policy link
- Live preview in admin
- Report emailed to visitor on capture

### Shareable reports
- Time-limited share links (token-based)
- Anyone with the link can view the report without scanning again
- Tokens expire per Rate Limiting settings

### Bulk scan
- CSV import: required fields `name`, `address`, `city`, `state`, `postal_code`; optional `country` (ISO 3166-1 alpha-2)
- WP Cron processes one business per 30-second tick
- Stuck item recovery: auto-reset after 3 minutes; Kill Stuck and per-row Kill controls
- Duplicate detection before confirmation with Force rescan override
- Live monitor: Est. Time Remaining, Avg Time/Scan, Completed, Failed, Currently Scanning, timestamped activity log

### Lead pipeline
- Leads (organic captures) and Prospects (bulk-imported) in separate views
- Inline-editable: status, follow-up date, notes — all autosave
- Business name opens full report in iframe modal
- Sortable columns
- Outreach drafts: cold Pitch for Prospects, warm Reply Draft for Leads; subject line in copy-to-clipboard
- CSV export

### Market Analytics
- Filterable industry breakdowns
- Score distributions
- City maps
- Pain-point aggregation across scan database

### Market Intel (premium, requires 10+ scans)
- Eight Claude-powered actions: Industry Report, Competitive Gap Analysis, Pain Point Deep Dive, Local SEO Strategy, Score Directory, Lead Magnet, Outreach Script, Full Market Forecast
- Results cached; repeated runs on same data don't trigger additional API calls

### White label (premium)
- Brand name, logo URL, colors, report title, CTA, email footer
- All applied as CSS custom properties

### Follow-up reminders (premium)
- Set follow-up date on any lead or prospect
- Daily (8am) or Weekly (Monday 8am) digest emails in site timezone
- Closed/Lost records excluded automatically
- Changing a follow-up date resets the reminder

### Notifications (premium)
- New lead alerts with configurable score threshold
- Follow-up reminder digest schedule

### Admin navigation structure

**Settings tabs:** API Config / Shortcode / White Label / Lead Form / Rate Limiting / IP Exclusions / Cache / Notifications

**Market Leads pages:** Lead Form / Leads / Brand Intel / Bulk Scan / **Reviews** ← F! Reviews lives here

The Market Leads section is where everything downstream of a scan lives. Lead Form configures capture, Leads manages the pipeline CRM, Brand Intel runs AI analysis, Bulk Scan processes CSV imports, and Reviews is where your client manages snippet deployment for local business clients they have decided to act on. Reviews sits after Bulk Scan because the natural workflow is: scan → pipeline → bulk import at scale → deploy review snippets for active clients.

### Infrastructure
- Google Places API (New): Text Search, Place Details, Nearby Search, Autocomplete, Geocoding, PageSpeed Insights
- Anthropic Claude API: Haiku for scan reports (recommended), configurable separate model for Market Intel
- Polar.sh: Merchant of Record, webhooks, license key activation, premium grant/revoke
- `FI_DEV_MODE` constant: set to `false` before distribution; `true` bypasses all premium checks
- WP Cron dependency: bulk scan won't process if `DISABLE_WP_CRON` is true

---

## F! Reviews — planned feature state

F! Reviews is a tab inside F! Insights, not a separate plugin. It is a snippet-based review collection and display system with an agency-management layer.

### Architecture decision: snippet served from fricking.website

The snippet (one script tag) that your client pastes onto a local business owner's site makes a validation call to fricking.website on load. The server confirms:
1. License is active
2. Domain matches the registered domain for that record

If both pass, the snippet renders enabled features. If either fails, it renders the graceful fallback (plain message + link to fricking.website).

**What fricking.website stores:**
- Snippet records created directly by you (super admin) for direct inquiries
- Analytics for fricking.website-attributed snippets only (lapsed/fallback state or direct-inquiry records)
- License and domain validation state for all snippets (needed for validation calls)

**What fricking.website does NOT store:**
- Analytics for your clients' active, white-labeled snippets — those stay on the client's own install
- Any data about the local business owner's visitors or customers

### The Reviews page (F! Insights → Market Leads → Reviews)

Sits after Bulk Scan in the Market Leads section. This placement is intentional — Market Leads is already where your client manages everything downstream of a scan. The existing Leads tab provides a light CRM (status, follow-up dates, notes, report snapshots, outreach drafts). Reviews extends that into a new action category: once a business is in the pipeline and your client has decided to act, they promote it to a Reviews record and manage snippet deployment from here.

**Relationship to the Leads CRM:** Reviews does not replace or duplicate the Leads pipeline. The pipeline tracks the sales and outreach relationship. Reviews tracks the active service delivery relationship — which local business clients have a live snippet, what features are enabled, and how those snippets are performing. A business can exist in both: still in the pipeline for upsell conversations while already having an active Reviews snippet deployed.

One record per local business client. Records are **not created automatically from scans**. Your client promotes a scan to a Reviews record manually when they decide to act on it. This keeps the dashboard clean — test scans, cold prospects, and ghosted leads never appear in Reviews.

Each record contains:
- Business name (pre-filled from scan)
- Place ID (pre-filled from scan)
- Review URL (built automatically from Place ID)
- Registered domain
- Label and optional notes
- Feature toggle set (see below)
- Tracking ID configuration (optional)
- Generated snippet
- Last active / status indicator

**Archive without delete:** ended client relationships can be archived; snippet fires fallback; data retained for potential resumption.

### Feature toggles per client record

Each toggle is independent. Your client decides the combination. No tier logic enforced. Future features add new toggles without affecting existing records.

**Review Collection**
- Review button (enable/disable)
- QR code display on site (enable/disable)
- Multi-location dropdown (enable/disable; configure locations per record)

**Review Display**
- Review widget (enable/disable)
- Review count (numeric; default 5 matching natural Google API return; raise to show more from stored pool)
- Layout: list or grid
- Minimum star filter (1–5; show only reviews at or above threshold)
- Sort order: newest, highest rated, most relevant
- Individual review visibility (show/hide per review from record detail view)

**Attribution and Branding**
- Attribution display (on by default; disable for white-label delivery)
- Custom attribution text (overrides White Label default per record if needed)
- Custom attribution link (overrides White Label default per record if needed)
- Widget styling overrides (button text, button colour, widget colours; inherits White Label by default)

**Tracking (optional)**
- One or more tracking IDs per snippet
- Each surface (website button, QR display, email template link, embedded widget) can carry a distinct ID
- IDs append as URL parameters to the review link
- Views vs clicks tracked per ID
- IP exclusions apply: the same IP exclusion list from F! Insights Rate Limiting settings is used; your client's own IP and any excluded IPs do not count toward analytics
- Analytics stored on client's own install, not on fricking.website (except for fricking.website-attributed snippets)

### Deliverables generated per record

- **Snippet** — one script tag; paste once; serves all enabled features; updates live when toggles change; no reinstall required
- **QR image download** — print-ready export for that business; available regardless of whether embedded QR toggle is on; sized for common print formats
- **Email template** — pre-written review request with business name and review link pre-filled; copy-pasteable into any email tool
- **Snippet preview** — live render of exactly what the local business owner's site will show with current toggle configuration; your client sees it before handing it over

### The snippet lifecycle

| State | What happens |
|---|---|
| Active, registered domain, valid license | All enabled features render normally |
| License lapsed or cancelled | All snippets on that account revert to fricking.website fallback; review collection and display pause; friendly message shown; functionality resumes if license is reinstated |
| Domain mismatch | Fallback fires; prevents snippet sharing or reuse on unauthorised domains |
| Feature toggled off | Feature disappears from snippet output on next load; no broken elements |
| Record archived | Snippet fires fallback; data retained |

### Trial and white-label exposure

Attribution is **on by default** for all new records. A trial period therefore always shows your client's brand name — the local business owner sees your client's name, not fricking.website. There is nothing to trace back unless your client deliberately disables attribution. White-label removal is a manual toggle your client flips when a client has earned or paid for unbranded delivery.

This is a commercial relationship decision, not a technical lock. The right protection is your clients building strong service relationships. The attribution mechanic supports that by making their brand visible during every trial.

### Review display — competitor context

Three competitors reviewed before scoping this feature:

1. **Trustindex** — SaaS platform using a WordPress plugin as a funnel. Display only (with upsell to collection). 900k+ installs. Requires Trustindex account. Not a direct competitor because they target local business owners directly; no agency/reseller layer.

2. **Embedder for Google Reviews (PARETO Digital)** — Pure display plugin. Uses their own proxy API (easyreviewsapi.com) so no Google API key needed. Freemius for licensing. Infrastructure dependency risk. No collection features.

3. **Reviews and Rating – Google Reviews (Design Extreme)** — Solo developer, fully free, no upsell. Calls Google Places API directly. Already has `write_review_url` shortcode built in — the same mechanism as F! Reviews' collection button. Most technically serious of the three. Requires local business owner to configure their own API key and Place ID — the exact friction F! Reviews eliminates.

**The gap F! Reviews fills:** nobody offers a clean, agency-managed collection and display tool with snippet-based deployment, per-client toggle control, a reseller attribution layer, and zero setup friction for the local business owner. The Place ID is already resolved by F! Insights. That's the moat.

---

## F! Branding — current feature state (v1.0.0)

### Core
- `[f_branding]` shortcode embeds the brand audit tool
- 101 questions across 10 categories: Target Audience, Competitive Analysis, Differentiation Strategy, Personality and Verbal Identity, Communication Strategy, Visual Identity, Offer Strategy, Customer Journey Mapping, Experience Design, Marketing Strategy
- Four depth tiers: Starter (25 total), Deep Dive (51), Full Picture (76), Everything (96)
- Session resume from any device (server-side after teaser screen; sessionStorage before)

### Two-pass AI pipeline
- **Pass 1 (Haiku):** reads 10 lite answers, generates Brand Snapshot teaser (3 JSON card objects: headline + body), builds personalised deep question queue
- **Pass 2 (Sonnet):** reads full answer set for selected tier, generates structured Brand Audit report: `positioning_statement`, `personality_descriptors`, `who_you_are_now`, `who_this_is_for`, `core_tension`, `keep`/`amplify`/`drop`, `priority_actions`

### Wizard mode (`[f_branding wizard="true"]`)
Five stages before questions begin:
1. Foundation: business name, industry dropdown, maturity stage
2. Brand Persona: archetype selection (12 archetypes), primary/influencer mix, approach style
3. Audience and Positioning: who they serve, competitor grid, market statement builder
4. Messaging: brand essence keywords, anti-keywords, Claude-generated tagline options (scored on clarity, catchiness, concision, cleverness)
5. Brand Story: before state, brand shift, after state

All wizard data injected into Pass 2 system prompt for materially richer reports.

### Multiple audit models
- Additional models configurable in Models tab
- Each deployed via `[f_branding model="slug"]` on its own page
- Each model has its own question cadence

### Premium features
- Lead capture with configurable fields and GDPR consent
- White-label: name, logo, six-token color system
- Full question editor: edit, lock, or remix any of 101 questions
- Brand Intel: eight AI analysis actions on aggregated audit data
- Pipeline dashboard: lead status, follow-up dates, notes, report snapshots, CSV export
- Resume link emails
- Admin and completion notifications
- Email report delivery

### Infrastructure
- Anthropic Claude API only (no Google dependency)
- Polar.sh licensing (same as F! Insights)
- `FR_DEV_MODE` constant: set to `false` before distribution
- WP Cron for scheduled emails

---

## Cross-plugin design decisions

### Licensing
Both F! Insights and F! Branding use Polar.sh as Merchant of Record. Same pattern expected for F! Reviews when it is built as a billable feature. Polar handles checkout, tax compliance, license key delivery, and webhook-verified subscription lifecycle.

Constants to know:
- `FI_DEV_MODE` / `FR_DEV_MODE` — bypass premium checks when `true`; always `false` in production
- `FI_VERSION` / `FR_VERSION` — both at `1.0.0`
- `FI_DB_VERSION` — currently `4.5`; bumped to trigger `create_tables()` which now also calls `FI_Reviews::create_tables()`
- Both plugins require PHP 8.0+ and WordPress 6.2+

### Code style
- PHP 8.0+ features used throughout (str_contains, named arguments, match expressions)
- Class-per-file structure: `class-fi-*.php` for F! Insights, `class-fr-*.php` for F! Branding
- Admin tabs as separate classes under `includes/admin/`
- No em dashes, en dashes, or hyphens as visual separators in any user-facing string (enforced project-wide)
- AJAX actions prefixed `fi_` or `fr_` respectively
- All assets versioned with plugin version constant for cache busting

### UI conventions
- Admin UI uses shared CSS classes with `fi-` prefix (both plugins share the admin stylesheet pattern)
- Save bar rendered via `FI_Admin::save_bar()` / `FR_Admin::save_bar()`
- Status items rendered via `status_item()` static method pattern
- Shortcode page tab uses dropdown selector (not textarea) for page assignment
- Explanation text on Shortcode tab explains share link URL construction including the domain preview

### README and documentation standards
- One `README.md` and one `readme.txt` per plugin — no prefixed duplicates
- `README.md` is the developer-facing doc (installation, configuration, class overview, AJAX actions, DB schema, constants, hooks)
- `readme.txt` is the WordPress.org-facing doc (description, FAQ, screenshots)
- Features sections must be benefits-led — lead with the outcome, not the feature name
- No changelog sections in any readme file
- No version mentions in readme files (version lives in the plugin header and constants only)
- All versions at `1.0.0`; `Requires at least: 6.2`

---

## What has been built

### F! Reviews page (F! Insights → Market Leads → Reviews) — COMPLETE (WordPress side)
The full WordPress implementation is done. What was built:

**New classes:**
- `FI_Reviews` (`class-fi-reviews.php`) — DB schema for `fi_review_records` and `fi_review_tracking`, full CRUD, snippet generation, Google review URL builder (`search.google.com/local/writereview?placeid=`), QR image URL via Google Charts API, email template builder, per-surface tracking event recording with IP exclusion check, archive/restore
- `FI_Admin_Tab_Reviews` (`admin/class-fi-admin-tab-reviews.php`) — record list with feature badges and tracking summary; record detail with autosaving fields, feature toggle rows with conditional panel show/hide (display config, attribution config), snippet copy, QR download, email template copy, tracking surface add/delete

**Modified files:**
- `class-fi-analytics-page.php` — Reviews tab added to Market Leads navigation; bulk lookup of review record IDs for closed leads (single query, `$reviews_by_lead_id` map) eliminates N+1; both pipeline tables use map lookups; `class_exists('FI_Reviews')` guards throughout
- `class-fi-ajax.php` — six new admin handlers: `fi_reviews_create`, `fi_reviews_update_field`, `fi_reviews_add_surface`, `fi_reviews_delete_surface`, `fi_reviews_archive`, `fi_reviews_restore`; all guarded with `class_exists('FI_Reviews')` and `self::require_admin()`
- `class-fi-db.php` — calls `FI_Reviews::create_tables()` at end of `FI_DB::create_tables()`
- `f-insights.php` — DB version bumped to `4.5`; `class-fi-reviews.php` in core includes; `class-fi-admin-tab-reviews.php` in premium includes
- `assets/js/admin.js` — `initReviews()` function added; status change handler extended to reveal Set Up Reviews button live on Closed transition
- `assets/css/admin.css` — full Reviews stylesheet appended

**Bugs fixed during review:**
- `int|false` union return types on `create_from_lead()` and `add_tracking_surface()` replaced with plain `int` (0 on failure) to match codebase convention
- `$_POST['value']` passed through `wp_unslash()` at AJAX boundary before reaching `FI_Reviews::update()`
- Redundant `preg_match` in `handle_reviews_add_surface` removed; `sanitize_key()` handles it
- `record_event()` SQL restructured — two explicit `prepare()` calls instead of column name interpolation inside a single `prepare()`
- Duplicate inline `<style>` block removed from `render_record_detail()`; all rules are in `admin.css`

---

## What hasn't been built yet — in priority order

### 1. F! Reviews snippet server (fricking.website infrastructure) — NEXT
This is the only remaining blocker before Reviews is customer-facing. The WordPress side is complete; the hosted endpoint does not yet exist.

Required:
- `GET /reviews/snippet.js?token=TOKEN` — validates license state and domain match; serves the widget JS for active records; serves fallback HTML for lapsed/archived/mismatched records
- Analytics ingestion endpoint for fricking.website-attributed snippets (direct inquiries and fallback states) — client white-labeled snippets store tracking locally and never call this
- Super admin dashboard on fricking.website — cross-account snippet visibility, domain, license state, last-seen, attribution status
- Decision still open: serverless function (Cloudflare Worker, Vercel Edge) vs lightweight VPS — Cloudflare Worker is the lowest-friction starting point given the validation logic is simple and latency matters for snippet load

QR code approach is resolved: Google Charts API (`chart.googleapis.com/chart?cht=qr`) — no library dependency, no API key, URL-only. Already implemented in `FI_Reviews::qr_image_url()`.

### 2. CRM and Automation Integrations *(Premium)*

Push leads and prospects from the F! Insights pipeline into the tools your clients are already running. All three integrations configured under a new **Settings → F-Insights → Integrations** tab, following the same enable/disable + test connection pattern as the existing API Config tab.

**Zapier**
- Outbound webhook triggers for: new lead captured, lead status changed, prospect imported from bulk scan, follow-up date set
- Payload includes: business name, score, pain points, category, address, phone, website, lead type (lead/prospect), current status
- No Zapier account required on the WordPress side — client configures the receiving Zap using the webhook trigger URL generated in the Integrations tab
- Connects to 7,000+ apps: Mailchimp, Slack, Notion, Airtable, Google Sheets, ActiveCampaign, and anything else in a client's stack

**Make (formerly Integromat)**
- Same outbound webhook model as Zapier, structured JSON payload compatible with Make's HTTP module out of the box
- Maps cleanly to Make's data store and router modules for multi-step automations
- Typical use case: new prospect → enrich in Clearbit → push to CRM → notify team in Slack

**HubSpot**
- Direct OAuth connection — no Zapier or Make needed in the middle
- New leads and prospects sync as HubSpot Contacts automatically on capture
- Scan data mapped to standard and custom HubSpot Contact properties: `fi_pain_points`, `fi_score`, `fi_category`, `fi_scan_url`
- Lead status changes in F! Insights optionally update the associated HubSpot Deal stage
- Two-way (optional): HubSpot Deal stage changes can write back to F! Insights lead status via HubSpot webhook
- Per-site OAuth — each F! Insights license holder connects their own HubSpot account; no shared credentials
- Scoped to the minimum required HubSpot OAuth permissions (contacts read/write, deals read/write)

Implementation notes:
- Zapier and Make: single outbound `wp_remote_post()` call per trigger event, fired on the same hooks that drive the pipeline autosave (status update, follow-up date, lead insert)
- HubSpot: OAuth token stored in WP options per site; refresh handled transparently; HubSpot API calls queued via WP Cron if the synchronous call fails to avoid blocking the admin response
- All three gated behind `FI_Premium::is_active()` check

### 3. F! Insights + F! Branding Integration
- When both plugins are active, Brand Intel in F! Branding can incorporate F! Insights scan data for deeper context
- Each plugin runs independently; integration is additive and opt-in
- Not yet scoped in detail

### 4. F! Reviews — Future Toggle Additions
The toggle architecture is designed to be additive. Features not yet scoped but likely candidates as the product matures:
- Review response prompts (show business owner reply alongside review)
- Review age filter (only show reviews from last N months)
- Structured data / Schema.org output for SEO
- Floating widget variant (fixed position on page, not inline)
- Review count badge (shows aggregate score and count, links to full widget)

### 5. Distribution and Discovery
- fricking.website is the primary distribution channel
- WordPress.org not a current target (crowded, competitive, wrong buyer)
- Direct to agencies and freelancers via content, outreach, and the F! Insights install base
- Affiliates explicitly not in scope at this stage

---

## Competitive landscape summary

### Jesse Kreun / Simplify with Jesse
- $297/month managed reputation service
- GoHighLevel-based (CRM, SMS, automations baked in)
- Sells directly to local business owners
- Not a direct competitor — different buyer, different model
- His lane: do it for you. Your lane: give your clients the tools to do it for their clients.

### Trustindex
- SaaS platform, WordPress plugin as acquisition funnel
- 900k+ WP installs
- Review display + upsell to collection via their platform
- No agency/reseller layer
- Requires Trustindex account for anything beyond basic display

### Embedder for Google Reviews (PARETO Digital)
- Pure display plugin
- Proxy API (easyreviewsapi.com) — no Google API key needed by user
- Freemius for licensing with affiliate program
- Infrastructure dependency risk (their proxy goes down, all widgets break)
- No collection features

### Reviews and Rating – Google Reviews (Design Extreme)
- Solo developer, fully free, no upsell, no SaaS backend
- Most technically serious: calls Google Places API directly
- Already has `write_review_url` shortcode (same mechanism as F! Reviews collection button)
- Requires local business owner to configure their own Google API key and Place ID
- 250 display themes, carousel, structured data, full shortcode system
- No agency layer, no multi-client management, no snippet deployment model

**The uncontested position:** agency-managed, snippet-deployed, zero-setup-for-local-business-owner, toggle-controlled review collection and display with a reseller attribution mechanic and a graceful license lifecycle. None of the competitors touch this model.

---

## Naming and brand conventions

- Plugin names use "F!" prefix with exclamation: **F! Insights**, **F! Branding**, **F! Reviews**
- Internal WordPress slugs: `f-insights`, `f-branding` (Reviews TBD)
- Text domain: `f-insights`, `f-branding`
- PHP constants: `FI_*` for F! Insights, `FR_*` for F! Branding, `FRV_*` anticipated for F! Reviews
- Author: Said Martínez Calderón
- Author URI: saidmartinezcalderon.com
- License: GPL-2.0+
- All plugins require PHP 8.0+ and WordPress 6.2+

---

## Things that have been explicitly decided and should not be revisited without good reason

- **No affiliate program** — too early; not the right stage
- **No masking of fricking.website path in snippets** — commercial relationship protection is the right approach, not technical obfuscation
- **No automatic scan-to-Reviews-record promotion** — keeps the Reviews dashboard clean; manual promotion only
- **No analytics stored on fricking.website for active white-labeled client snippets** — privacy and simplicity; only fricking.website-attributed snippets report back
- **F! Reviews lives at Market Leads → Reviews, not a top-level tab** — Market Leads is already where everything downstream of a scan lives; Reviews is the natural next action after Bulk Scan in that flow; the existing Leads CRM and Reviews are complementary, not duplicates
- **No SMS, CRM, or post-purchase automation** — managed service territory; different business model
- **No review display from platforms other than Google** — out of scope; adds complexity without adding to the core value proposition
- **Freemius not used** — Polar.sh is the chosen Merchant of Record for all three plugins
- **WordPress.org not a current distribution target** — wrong channel for this buyer
