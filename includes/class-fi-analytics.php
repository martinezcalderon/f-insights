<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Analytics
 * Aggregated scan + lead data and Claude market intelligence calls.
 * All Market Intel methods accept a $filters array:
 *   [ 'category' => string, 'date_range' => '30'|'90'|'180'|'all' ]
 */
class FI_Analytics {

    // -------------------------------------------------------------------------
    // Interpretive briefing — Analytics tab (unfiltered, whole-market view)
    // -------------------------------------------------------------------------

    public static function get_briefing(): array {
        $total       = FI_DB::total_scans();
        $avg         = FI_DB::average_score();
        $dist        = FI_DB::score_distribution()[0] ?? (object) [ 'strong' => 0, 'average' => 0, 'weak' => 0 ];
        $high_need   = FI_DB::high_need_count();
        $total_leads = FI_DB::total_leads();
        $last7       = FI_DB::scans_in_last_days( 7 );
        $last30      = FI_DB::scans_in_last_days( 30 );
        $industries  = FI_DB::top_industries( 10 );
        $weakest     = FI_DB::weakest_industries( 2, 5 );
        $converting  = FI_DB::highest_converting_industries( 5 );
        $cities      = FI_DB::top_cities( 8 );
        $pain_by_ind = FI_DB::pain_points_by_industry();
        $pain_all    = FI_DB::get_top_pain_points();
        $conv_rate   = FI_DB::scan_to_lead_rate();

        $market_signal = '';
        if ( $total >= 10 ) {
            if ( $avg < 50 )      $market_signal = 'Weak market. Most businesses have serious gaps. High demand for help, low competition for whoever shows up with solutions.';
            elseif ( $avg < 65 )  $market_signal = 'Average market. Clear, consistent gaps across industries. Good opportunity for a consultant who can demonstrate the problem.';
            else                  $market_signal = 'Relatively strong market. Businesses are doing the basics. Differentiation requires going beyond profile optimization.';
        }

        $conv_signal = '';
        if ( $total >= 10 ) {
            if ( $conv_rate >= 15 )         $conv_signal = 'Strong. Your report is compelling enough that 1 in ' . round( 100 / max( $conv_rate, 1 ) ) . ' people want it emailed. That\'s a warm pipeline.';
            elseif ( $conv_rate >= 5 )      $conv_signal = 'Moderate. ' . $conv_rate . '% of scanners are converting to leads. Tweaking the lead form headline or adding urgency could move this.';
            elseif ( $total_leads > 0 )     $conv_signal = 'Low. Only ' . $conv_rate . '% conversion. The report may not be creating enough urgency, or the lead form is too early in the experience.';
        }

        $velocity_signal = '';
        if ( $last7 > 0 && $last30 > 0 ) {
            $weekly_avg = round( $last30 / 4, 1 );
            if ( $last7 > $weekly_avg * 1.5 )              $velocity_signal = 'Accelerating. This week\'s volume is above your monthly average. Something is driving traffic to your scanner.';
            elseif ( $last7 < $weekly_avg * 0.5 && $last30 > 4 ) $velocity_signal = 'Slowing. Scan volume this week is below your recent average. Worth checking if the shortcode page is getting traffic.';
        }

        $opportunity_signal = '';
        if ( count( $weakest ) >= 2 ) {
            $top2 = array_slice( $weakest, 0, 2 );
            $opportunity_signal = ucfirst( $top2[0]->category ) . ' (avg ' . $top2[0]->avg_score . '/100) and '
                . $top2[1]->category . ' (avg ' . $top2[1]->avg_score . '/100) are your weakest categories '
                . 'with enough scan volume to be meaningful. These are your highest-probability cold outreach targets.';
        } elseif ( count( $weakest ) === 1 ) {
            $opportunity_signal = ucfirst( $weakest[0]->category ) . ' averages ' . $weakest[0]->avg_score . '/100 across '
                . $weakest[0]->scans . ' scans; your clearest current opportunity.';
        }

        return compact(
            'total', 'avg', 'dist', 'high_need', 'total_leads', 'conv_rate',
            'last7', 'last30', 'industries', 'weakest', 'converting',
            'cities', 'pain_by_ind', 'pain_all',
            'market_signal', 'conv_signal', 'velocity_signal', 'opportunity_signal'
        );
    }

    // -------------------------------------------------------------------------
    // Filtered aggregate data — Market Intel tab
    // -------------------------------------------------------------------------

    public static function aggregate_data( array $filters = [] ): array {
        $f = array_merge( [ 'category' => 'all', 'date_range' => 'all' ], $filters );

        $total      = FI_DB::filtered_total_scans( $f );
        $weakest    = FI_DB::filtered_weakest_industries( $f, 2, 5 );
        $industries = FI_DB::filtered_top_industries( $f, 10 );
        $cities     = FI_DB::filtered_top_cities( $f, 5 );
        $pains      = FI_DB::filtered_top_pain_points( $f );

        return [
            'total_scans'        => $total,
            'total_leads'        => FI_DB::total_leads(),
            'avg_score'          => FI_DB::filtered_average_score( $f ),
            'high_need_count'    => FI_DB::filtered_high_need_count( $f ),
            'scan_to_lead_rate'  => FI_DB::scan_to_lead_rate(),
            'top_industries'     => $industries,
            'weakest_industries' => $weakest,
            'top_pain_points'    => $pains,
            'pain_by_industry'   => FI_DB::pain_points_by_industry(),
            'top_cities'         => $cities,
            'leads_by_status'    => FI_DB::leads_by_status(),
            'score_distribution' => FI_DB::score_distribution(),
            'recent_scans'       => FI_DB::get_recent_scans( 20 ),
            '_filters'           => $f,
        ];
    }

    // -------------------------------------------------------------------------
    // Signal quality assessment
    // -------------------------------------------------------------------------

    /**
     * Returns 'strong' | 'moderate' | 'limited' based on filtered scan count
     * and category spread.
     */
    public static function signal_quality( array $data ): string {
        $total      = (int) $data['total_scans'];
        $categories = count( (array) $data['top_industries'] );

        if ( $total >= 20 && $categories >= 3 ) return 'strong';
        if ( $total >= 10 || $categories >= 2 ) return 'moderate';
        return 'limited';
    }

    // -------------------------------------------------------------------------
    // Run action
    // -------------------------------------------------------------------------

    public static function run_action( string $action, array $filters = [], string $platform = '' ) {
        if ( ! get_option( 'fi_claude_api_key', '' ) ) {
            return new WP_Error( 'no_key', 'Claude API key not configured.' );
        }

        $data   = self::aggregate_data( $filters );
        $prompt = self::build_action_prompt( $data, $action, $platform );

        if ( is_wp_error( $prompt ) ) return $prompt;

        FI_Logger::api( 'Market Intel action call', [ 'action' => $action, 'filters' => $filters ] );

        // Admin Intelligence Model — separately configurable from the Report Model
        // so heavy admin calls don't inflate per-scan API costs.
        $admin_model = get_option( 'fi_claude_model_admin', get_option( 'fi_claude_model', 'claude-haiku-4-5-20251001' ) );

        return FI_Claude::request( $prompt, [
            'model'      => $admin_model,
            'system'     => 'You are a direct, senior marketing strategist. Everything you write is immediately usable; no fluff, no hedging, no "consider" or "you might want to". Write like someone who has done this a hundred times and bills accordingly.',
            'max_tokens' => 3000,
            'timeout'    => 90,
        ] );
    }

    /**
     * Dispatcher used by FI_Ajax::handle_run_market_intel().
     * Accepts action type and filters, delegates to run_action().
     */
    public static function run( string $action, array $filters = [] ) {
        // Extract platform before passing filters to aggregate_data() — DB queries
        // don't know about this key and it should not leak into WHERE clauses.
        $platform   = (string) ( $filters['platform'] ?? '' );
        $db_filters = array_diff_key( $filters, [ 'platform' => true ] );

        // Enforce minimum scan counts server-side so the API is never called
        // regardless of what the frontend shows. Mirrors the UI tier thresholds.
        $action_thresholds = [
            // Tier 1
            'industry_report'       => 10,
            'landing_page'          => 10,
            'prospect_hit_list'     => 10,
            'objection_cheat_sheet' => 10,
            'market_one_pager'      => 10,
            // Tier 2
            'cold_outreach'         => 25,
            'pitch_deck'            => 25,
            'discovery_call_script' => 25,
            'social_media_series'   => 25,
            'pricing_anchor_script' => 25,
            'niche_positioning'     => 25,
            'google_ads_brief'      => 25,
            'follow_up_templates'   => 25,
            // Tier 3
            'content_strategy'      => 50,
            'annual_market_report'  => 50,
            'partnership_pitch'     => 50,
            'competitor_gap_analysis' => 50,
            'case_study_template'   => 50,
            'webinar_outline'       => 50,
            'video_script_series'   => 50,
            'proposal_template'     => 50,
            // Tier 4
            'press_release'         => 100,
            'franchise_brief'       => 100,
            'referral_partner_script' => 100,
            'newsletter_template'   => 100,
            'media_pitch'           => 100,
            'grant_proposal'        => 100,
            'white_label_package'   => 100,
            // Tier 5
            'paid_intelligence_brief'  => 500,
            'score_directory'          => 500,
            'academic_partnership'     => 500,
            'city_hall_brief'          => 500,
            'competitive_intel_service'=> 500,
            'acquisition_package'      => 500,
            'annual_summit'            => 500,
        ];

        $required = $action_thresholds[ $action ] ?? 10;
        $actual   = FI_DB::filtered_total_scans( $db_filters );

        if ( $actual < $required ) {
            return new \WP_Error(
                'insufficient_scans',
                sprintf(
                    'This action requires at least %d scans in the current filter. You have %d.',
                    $required,
                    $actual
                )
            );
        }

        return self::run_action( $action, $db_filters, $platform );
    }

    // -------------------------------------------------------------------------
    // Shared context builder
    // -------------------------------------------------------------------------

    private static function build_context( array $data ): array {
        $top_industry   = $data['weakest_industries'][0] ?? ( $data['top_industries'][0] ?? null );
        $industry_name  = $top_industry ? ucfirst( (string) ( $top_industry->category ?? '' ) ) : 'local businesses';
        $industry_score = $top_industry->avg_score ?? 'N/A';
        $industry_scans = (int) ( $top_industry->scans ?? 0 );
        $avg_score      = $data['avg_score'];
        $total_scans    = $data['total_scans'];

        $top_pains  = array_slice( array_keys( (array) $data['top_pain_points'] ), 0, 4 );
        $pain_list  = ! empty( $top_pains )
                      ? implode( ', ', array_map( fn($p) => trim( explode('(',$p)[0] ), $top_pains ) )
                      : 'profile incompleteness, low review volume';

        $cities_arr   = array_column( (array) $data['top_cities'], 'city' );
        $city_list    = ! empty( $cities_arr ) ? implode( ', ', $cities_arr ) : 'your local area';
        $city_primary = $cities_arr[0] ?? 'your area';

        // Industry table for richer prompts
        $industry_table = '';
        foreach ( (array) $data['top_industries'] as $ind ) {
            $industry_table .= "  - {$ind->category}: {$ind->scans} scans, avg score {$ind->avg_score}/100\n";
        }

        // Active filter description for context header
        $filters   = $data['_filters'] ?? [];
        $scope_cat = ( ! empty( $filters['category'] ) && $filters['category'] !== 'all' )
                     ? ucfirst( $filters['category'] ) : 'All industries';
        $scope_date = match( (string) ( $filters['date_range'] ?? 'all' ) ) {
            '30'  => 'Last 30 days',
            '90'  => 'Last 90 days',
            '180' => 'Last 6 months',
            default => 'All time',
        };

        $context = "## Your Market Data ({$scope_cat} · {$scope_date})\n"
                 . "- Businesses scanned: {$total_scans}\n"
                 . "- Market average score: {$avg_score}/100\n"
                 . "- Top opportunity industry: {$industry_name} (avg {$industry_score}/100, {$industry_scans} scans)\n"
                 . "- Top pain points: {$pain_list}\n"
                 . "- Cities in data: {$city_list}\n"
                 . ( $industry_table ? "- Industry breakdown:\n{$industry_table}" : '' );

        return compact(
            'industry_name', 'industry_score', 'industry_scans',
            'avg_score', 'total_scans', 'pain_list',
            'city_list', 'city_primary', 'context'
        );
    }

    // -------------------------------------------------------------------------
    // Prompt builders — one per action
    // -------------------------------------------------------------------------

    private static function build_action_prompt( array $data, string $action, string $platform = '' ): string|\WP_Error {
        $c = self::build_context( $data );
        extract( $c ); // $industry_name, $industry_score, $pain_list, $city_list, $city_primary, $context, etc.

        switch ( $action ) {

            // ── Tier 1 ──────────────────────────────────────────────────────

            case 'industry_report':
                return <<<PROMPT
{$context}

Write a punchy, authoritative local market intelligence article a consultant can publish as a blog post, submit to a local business association newsletter, or use as a LinkedIn article.

Title: "{$industry_name} Businesses in {$city_list}: What the Data Says"

Structure:
1. **The State of the Market** — 2 paragraphs. What the average score means in plain terms. How these businesses compare to what a consumer actually expects when they search. Be direct.
2. **The Three Biggest Problems** — 3 paragraphs, one per pain point. Name the problem, explain why it costs the business customers, give a one-sentence fix.
3. **What the Best-Performing Businesses Do Differently** — 1 paragraph. Concrete observable practices.
4. **The Bottom Line** — 1 short paragraph. The cost of inaction and the opportunity for businesses willing to act.

Rules: Write in second person to the business owner. No bullet lists — paragraphs only. Under 700 words. Do not mention the tool or the consultant. This is market research, not a sales pitch.
PROMPT;

            case 'landing_page':
                return <<<PROMPT
{$context}

Write full conversion copy for a landing page a consultant uses to attract {$industry_name} clients in {$city_list}. Goal: get the owner to book a discovery call or request a free audit.

Output these sections clearly labeled:

**HEADLINE** — One line. Industry and location specific. Calls out the problem or the cost of ignoring it. No generic marketing language.

**SUBHEADLINE** — One sentence. Makes the stakes concrete.

**THE PROBLEM** (2–3 sentences) — What is costing them right now. Reference: {$pain_list}. Write to the owner's lived experience, not the technical issue.

**WHY IT MATTERS NOW** (2–3 sentences) — What changes if they don't act. Competitor pressure, customer search behavior, map pack visibility.

**WHAT YOU DO** (3–4 sentences) — Specific outcomes. Not service names. Not jargon.

**SOCIAL PROOF PLACEHOLDER** — 2 placeholder testimonial formats they can replace with real client quotes.

**CTA** — Primary button label + one surrounding sentence. One secondary option for people not ready to book.

Rules: Write for a skeptical owner who has deleted marketing emails before. No "leverage", "synergy", "holistic", or "game-changer". Under 500 words total.
PROMPT;

            case 'prospect_hit_list':
                return <<<PROMPT
{$context}

Generate a Prospect Hit List — a ranked, actionable list of the 10 highest-need business types in this market based on the scan data above.

For each entry output:
- **Rank** and **Business Type**
- **Why they're on this list** — 1 sentence referencing their average score or pain pattern
- **The single biggest problem** they're likely to have (from the pain points in the data)
- **Cold opener** — One sentence the consultant can use to start a cold email or LinkedIn message. Specific. No generic openers.
- **Best channel** — Email / LinkedIn / In-person / Phone — and one sentence why for this type

At the end, add a **Strategy Note** (2–3 sentences): which two or three of these ten represent the highest combined value (weak score + realistic budget + volume of businesses in the market) and why.

Rules: Be specific. Name the business type precisely ("family-owned auto repair", not "auto industry"). The cold openers must reference something the business would recognize about themselves, not about the consultant.
PROMPT;

            case 'objection_cheat_sheet':
                return <<<PROMPT
{$context}

Write an Objection Cheat Sheet for a consultant selling digital marketing or web design to {$industry_name} businesses in {$city_list}.

Based on the pain points in the data ({$pain_list}), these business owners will push back in predictable ways.

For each of the 6 most likely objections, output:
- **The Objection** — written exactly as the owner would say it, in their voice
- **What's Really Being Said** — the underlying fear or belief driving it (1 sentence)
- **The Response** — 2–4 sentences. Direct, confident, not defensive. Uses data where possible. Does not beg.
- **The Follow-Up Question** — one question that moves the conversation forward after the response

End with a **Pattern Note** (1 paragraph): what these objections have in common and what it tells you about how to position the offer from the start so fewer of these come up.

Rules: Write in the consultant's voice. These are spoken responses, not email copy — write for how a confident expert actually talks.
PROMPT;

            case 'market_one_pager':
                return <<<PROMPT
{$context}

Write a Market One-Pager — a single-page document a consultant can leave behind after a meeting, attach to a cold email, or hand out at a local business event.

It should read like research, not a sales pitch.

Output these sections:

**HEADLINE** — A data-driven statement about the local market. Specific city and industry. ("74% of {$city_primary} {$industry_name} businesses score below average on Google.")

**KEY FINDINGS** — 3 findings from the data, each a single sharp sentence. Lead with numbers or percentages where possible.

**WHAT THIS MEANS FOR YOUR BUSINESS** — 2 short paragraphs. What the data tells a business owner about their competitive position. Written to them, not about them.

**THE THREE THINGS THAT SEPARATE STRONG FROM WEAK PROFILES** — 3 brief bullet points. Observable, actionable, not jargon.

**ABOUT THIS RESEARCH** — 2 sentences. Where the data comes from (local business Google Business Profiles), how many scans it represents ({$total_scans}), and what market it covers ({$city_list}).

**FOOTER LINE** — One sentence inviting contact. Consultant's name and URL placeholders only — "[Your Name] · [yoursite.com]"

Rules: Tight. No fluff. Should fit on one printed page. Reads like it came from a local economic research organization, not a marketing agency.
PROMPT;

            // ── Tier 2 ──────────────────────────────────────────────────────

            case 'cold_outreach':
                return <<<PROMPT
{$context}

Write a 5-email cold outreach sequence targeting {$industry_name} business owners in {$city_list}.
Goal: get a reply or a booked discovery call. These are cold — recipients have not opted in or requested anything.

For each email output:
- **Subject line** (and an A/B variant where useful)
- **Body** (3–5 sentences max — hard limit)
- **Send timing** (Day X from first contact)
- **One-line goal** for this email

Email 1 — Pattern interrupt. Lead with the data ({$industry_name} averaging {$industry_score}/100). No ask. Just a relevant, surprising observation.
Email 2 — Cost of inaction. What the gap in their profile is costing them in concrete, recognizable terms — not abstract.
Email 3 — Social proof angle. What happened when a similar business fixed the problem. No names needed — just the outcome.
Email 4 — The direct ask. One low-friction action. Simple. Easy to say yes to.
Email 5 — The respectful close. Assume not interested but leave the door open without being needy.

Rules: Never open with "I" or "My name is". No more than 5 sentences per email. No links until Email 4. Never use "I wanted to reach out", "I hope this email finds you well", or "just checking in". Write as if you already know this industry inside out — demonstrate it.
PROMPT;

            case 'pitch_deck':
                return <<<PROMPT
{$context}

Write the full narrative and talking points for a 10-slide pitch deck a consultant presents to a {$industry_name} business owner to win a web design or digital marketing engagement.

For each slide output:
- **Slide title**
- **Headline** — the one sentence on the slide
- **Talking points** — 3 bullet points the consultant says out loud (not on the slide)
- **Transition** — one sentence bridging to the next slide

Slides:
1. The State of {$industry_name} Online in {$city_list}
2. What Customers Do Before They Choose a {$industry_name} Business
3. The Gap: What Your Profile Looks Like vs. What Customers Expect — [TEMPLATE: consultant fills this from the prospect's own scan report]
4. The Three Issues Costing You Customers Right Now — use: {$pain_list}
5. What Your Strongest Competitor Is Already Doing
6. What a Fixed Profile Actually Looks Like — before/after framing
7. The ROI Case — conservative estimate: 2 additional customers/month at your average ticket
8. Our Approach — What We Do and How We Do It
9. What Happens in the First 30 Days
10. The Ask — one clear next step

Rules: Slide 3 must be templatable — write "[SCAN SCORE]", "[PAIN POINT 1]" etc. as fill-in placeholders. Slide 7: believable, conservative numbers only — no "300% ROI" claims. Talking points must sound natural when spoken, not when read. No corporate jargon.
PROMPT;

            case 'discovery_call_script':
                return <<<PROMPT
{$context}

Write a structured 20-minute discovery call script for a consultant talking to a {$industry_name} business owner for the first time.

This is not a sales pitch — it's a diagnostic conversation. The consultant's goal is to understand the prospect's situation well enough to know whether and how to help.

Output these sections:

**OPENING** (60 seconds) — How to start. Sets the tone. Not a pitch. Establishes why this call is different from a typical sales call.

**CONTEXT SETTING** (2 minutes) — 2–3 sentences the consultant says to frame what they've observed in the market ({$industry_name} averaging {$industry_score}/100) without making it about the prospect yet.

**DIAGNOSTIC QUESTIONS** — 8–10 questions, in order. Each question:
- The question itself
- What you're actually trying to learn from it
- One follow-up if they give a surface-level answer

Group them: Business Understanding (3 questions) / Current Situation (3 questions) / Goals and Priorities (2–3 questions)

**TRANSITION TO NEXT STEP** — How to move from the call to a proposal or follow-up without it feeling like a hard close.

**COMMON DERAILMENTS** — 3 things that can go wrong on this call and how to handle them.

Rules: Questions should feel like genuine curiosity, not a script. Write for spoken delivery. Nothing that sounds like it came from a sales training manual.
PROMPT;

            case 'social_media_series':
                $platform_map = [
                    'facebook'  => [ 'name' => 'Facebook',  'format' => 'longer storytelling post (150–300 words), conversational, ends with a question to drive comments' ],
                    'instagram' => [ 'name' => 'Instagram',  'format' => 'punchy caption (50–100 words), hook in the first line, ends with CTA, 5 relevant hashtags' ],
                    'threads'   => [ 'name' => 'Threads',    'format' => 'short take (under 100 words), opinionated, designed to generate replies' ],
                    'twitter'   => [ 'name' => 'X (Twitter)','format' => 'thread format — opening tweet under 280 chars, 4–5 follow-up tweets, closing tweet with CTA' ],
                ];
                $plat = $platform_map[ $platform ] ?? $platform_map['facebook'];
                $plat_name   = $plat['name'];
                $plat_format = $plat['format'];

                return <<<PROMPT
{$context}

Write a 5-post social media content series for {$plat_name} that positions a consultant as the local expert on {$industry_name} businesses in {$city_list}.

Each post should be a different angle on the market data — not five variations of the same thought.

Post 1 — The Surprising Stat: Lead with the most striking number from the data. Something that makes a {$industry_name} owner stop scrolling.
Post 2 — The Story: A before/after narrative about a business that fixed their profile. No names needed — just the situation and the outcome.
Post 3 — The Myth: Bust a common misconception {$industry_name} owners have about their online presence. Use the data to make the case.
Post 4 — The Behind-the-Scenes: What the consultant actually looks at when auditing a {$industry_name} business profile. Make it specific and educational.
Post 5 — The Soft Pitch: The most natural way to mention the consultant's service after four posts of pure value. Should not feel like a pivot.

Format for each post: {$plat_format}

Rules: Write in first person as the consultant. Each post should work as a standalone — no "in my last post" references. Data from the market scan should appear in at least 3 of the 5 posts.
PROMPT;

            case 'pricing_anchor_script':
                return <<<PROMPT
{$context}

Write a Pricing Anchor Script — a verbal framework a consultant uses in a sales conversation to justify their price and make it feel inevitable rather than negotiable.

The market data shows {$industry_name} businesses in {$city_list} averaging {$industry_score}/100, with the top pain points being {$pain_list}.

Output:

**THE SETUP** (2–3 sentences the consultant says before mentioning price) — Frames the cost of the problem in the prospect's terms. Uses the market data as evidence.

**THE ANCHOR** — How to introduce the investment range. One paragraph. Specific technique for presenting price that leads with value, not number.

**THE ROI CALCULATION FRAMEWORK** — Walk through a conservative calculation the consultant can customize:
- Average ticket / revenue per new customer for {$industry_name} businesses (provide a realistic estimate range)
- Conservative estimate of additional customers per month from a fixed profile (1–3, not "300% growth")
- Monthly revenue impact
- Annual revenue impact
- How that compares to the consultant's fee
Written as a script the consultant walks the prospect through out loud.

**HANDLING "THAT'S TOO EXPENSIVE"** — 3 specific responses. Not defensive. Each one reframes rather than justifies.

**THE TAKEAWAY CLOSE** — A soft close that works after the ROI walkthrough. One option that moves forward, one that creates urgency without pressure.

Rules: All numbers must be conservative and believable. No "10x ROI" claims. The goal is for the prospect to feel like they would be foolish to say no, not pressured to say yes.
PROMPT;

            case 'niche_positioning':
                return <<<PROMPT
{$context}

Write a Niche Positioning Statement package for a consultant who works with {$industry_name} businesses in {$city_list}.

This is not a tagline — it's a positioning system they use across all their materials.

Output:

**THE CORE POSITIONING STATEMENT** — 1–2 sentences. Specific. Names the industry, the location, and the problem. Not "I help local businesses grow online." Something like: "I help auto dealers in South Florida fix the Google Business Profile problems that are sending customers to their competitors."

**BIO VERSION** (3 sentences) — For website About page and LinkedIn summary. Third person. Establishes credibility through specificity.

**COLD EMAIL SIGNATURE LINE** (1 sentence) — Goes under the consultant's name in cold outreach. Telegraphs expertise before they even read the email.

**ELEVATOR PITCH** (30 seconds when spoken) — For in-person networking. Ends with a question that opens a conversation.

**HEADLINE OPTIONS** — 3 alternative website headline options that position the same expertise differently:
- One that leads with the problem
- One that leads with the outcome
- One that leads with the market data

**WHAT TO AVOID** — 3 generic positioning phrases this consultant should stop using and why each one is weakening their position.

Rules: All copy must be specific to {$industry_name} and {$city_list}. If it could apply to any consultant in any market, rewrite it until it can't.
PROMPT;

            case 'google_ads_brief':
                return <<<PROMPT
{$context}

Write a Google Ads Campaign Brief for a consultant targeting {$industry_name} business owners in {$city_list} who need help with their digital presence.

Output:

**CAMPAIGN OBJECTIVE** — 1 sentence. What a click should lead to (discovery call / free audit / lead form).

**TARGET AUDIENCE PROFILE** — Who we're targeting. Business role, search intent, what they're likely searching when they're actually ready to buy. 3–4 sentences.

**KEYWORD STRATEGY**
- 8–10 primary keywords (exact and phrase match) — specific to the industry and location
- 5 broad match modifiers for discovery
- 10 negative keywords to prevent wasted spend — based on what {$industry_name} owners might search that isn't relevant
For each primary keyword: estimated intent and why it's worth bidding on.

**AD COPY — 3 COMPLETE ADS**
For each ad:
- Headline 1 (30 chars max)
- Headline 2 (30 chars max)
- Headline 3 (30 chars max)
- Description 1 (90 chars max)
- Description 2 (90 chars max)

**LANDING PAGE RECOMMENDATION** — 2–3 sentences. What the landing page must say/do to convert this traffic. Key trust signals for {$industry_name} owners.

**BUDGET GUIDANCE** — Conservative monthly budget range for this market. How to allocate between search and display. When to expect meaningful data.

Rules: All ad copy must be specific — no generic "Grow Your Business" headlines. Reference the industry and location in at least one headline per ad. Keep everything within Google character limits.
PROMPT;

            case 'follow_up_templates':
                return <<<PROMPT
{$context}

Write 3 situational follow-up email templates for a consultant working with {$industry_name} prospects in {$city_list}.

**TEMPLATE 1: After a Discovery Call**
Timing: Within 2 hours of the call.
Goal: Recap what was discussed, confirm next step, keep momentum.
Tone: Warm but businesslike. Not a pitch — a continuation.
Format: Subject line + email body (under 150 words).
Include a placeholder section: [WHAT WE DISCUSSED] and [AGREED NEXT STEP].

**TEMPLATE 2: After Sending a Proposal**
Timing: 3–4 days after the proposal with no response.
Goal: Re-engage without desperation. Surface any hesitation so it can be addressed.
Tone: Confident, not chasing.
Format: Subject line + email body (under 100 words).
The email should make it easy for them to say "actually, I have a question" rather than continue ignoring.

**TEMPLATE 3: Re-engagement After Going Silent (30+ days)**
Timing: When a warm prospect has gone completely quiet.
Goal: Reopen the conversation with a new value hook — not "just checking in."
Tone: Direct. No guilt. A genuine reason to reply.
Format: Subject line + email body (under 80 words).
Use a market data update or a relevant observation about their industry as the hook.

Rules: None of these emails should sound like they were written by a CRM. Write for a real person with a real opinion about this market. Reference {$industry_name} specifically in each one.
PROMPT;

            // ── Tier 3 ──────────────────────────────────────────────────────

            case 'content_strategy':
                return <<<PROMPT
{$context}

Create a 90-day SEO content strategy for a consultant ranking in {$city_list} for services related to {$industry_name}. The consultant is a web designer or digital marketer attracting inbound leads from local business owners.

**TARGET KEYWORDS** — 8–10 specific phrases. Mix:
- Industry + city ("{$industry_name} web design {$city_primary}")
- Problem-aware ("why my Google Business Profile isn't showing up")
- Solution-aware ("Google Business Profile optimization {$city_primary}")
For each: estimated intent (informational / commercial) and why it matters for this specific market.

**CONTENT CALENDAR — 90 days**
12 pieces. For each:
- Title (specific, SEO-optimized)
- Format (blog post / video / LinkedIn article / lead magnet)
- Target keyword
- Key angle — what makes this piece different from generic content
- 2-sentence brief

**LINK-BUILDING ANGLE**
2–3 concrete local backlink opportunities using the market data this consultant has. Not "get listed in directories" — which specific ones, why they're worth pursuing, and how to pitch inclusion.

**THE DATA ASSET**
One piece only this consultant can publish because the data is theirs. Describe the piece, the angle, and a specific promotion plan (3–4 distribution channels).

Rules: All content should position the consultant as someone who knows this local market, not just someone who does SEO. No generic advice that applies to any consultant anywhere.
PROMPT;

            case 'annual_market_report':
                return <<<PROMPT
{$context}

Write a full Annual Market Report on the state of online presence for {$industry_name} businesses in {$city_list}. This is a substantive document a consultant can:
- Submit to a local chamber of commerce
- Pitch to a local business journal or publication
- Gate behind an email signup as a lead magnet
- Use as the foundation for a media pitch

Structure:

**COVER HEADLINE** — A specific, data-driven statement about the market.

**EXECUTIVE SUMMARY** (3 paragraphs) — The most important findings, what they mean for business owners, and what they mean for the local economy. Written for a non-technical reader.

**METHODOLOGY** (1 paragraph) — What was scanned, how many businesses, how scores are calculated, what time period. Factual and credible.

**SECTION 1: Market Overview** — Current state of {$industry_name} online presence in {$city_list}. Average scores, distribution, comparison to what consumers expect. 2–3 paragraphs.

**SECTION 2: The Three Critical Gaps** — Deep dive on the top 3 pain points ({$pain_list}). One section per gap: what it is, why it matters, what it costs businesses, what the data shows about prevalence.

**SECTION 3: What Strong Performers Do Differently** — 1 paragraph. Based on what high-scoring profiles consistently show.

**SECTION 4: Implications** — What this means for business owners who act vs. those who don't. 1–2 paragraphs.

**CLOSING STATEMENT** — 1 paragraph. Ends with a call to action directed at business owners.

Rules: Write like a research analyst, not a marketer. Paragraphs only — no bullet lists except in Methodology. Third person throughout. Do not mention the consultant directly — "the research" or "this analysis" is the author.
PROMPT;

            case 'partnership_pitch':
                return <<<PROMPT
{$context}

Write a Partnership Pitch for a consultant approaching a local business association, chamber of commerce, or industry group in {$city_list} with a co-branded market research offer.

The proposition: the consultant shares their local scan data and market intelligence as a member resource. The association gets valuable data to share with members. The consultant gets access to a room full of pre-qualified prospects who already trust the association.

Output:

**THE PITCH EMAIL** — Cold email to the executive director or membership chair. Subject line + email body (under 200 words). Leads with what the association gets, not what the consultant wants.

**THE ONE-PARAGRAPH SUMMARY** — What the partnership looks like in practice. For the association to share internally. Plain language.

**WHAT THE CONSULTANT OFFERS**
- The market data package (describe concretely)
- Presentation offer (lunch-and-learn, webinar, guest article)
- What it costs the association: nothing

**WHAT THE CONSULTANT ASKS IN RETURN** — Written diplomatically. Specific. (Member email list access / speaking slot / co-branded report / endorsed introduction)

**OBJECTION RESPONSES** — 3 likely objections from the association and how to answer them without sounding like a vendor.

**FOLLOW-UP CADENCE** — How to follow up if no response. 3-touch plan.

Rules: The pitch must never sound like the consultant is using the association to sell. Lead with genuine value. The data is the asset — position it that way.
PROMPT;

            case 'competitor_gap_analysis':
                return <<<PROMPT
{$context}

Write a Competitor Gap Analysis that identifies where the real opportunity sits in this market — not just which businesses have weak profiles, but where other service providers aren't competing effectively.

Output:

**THE MARKET LANDSCAPE** (2 paragraphs) — Based on the scan data, what does the current state of {$industry_name} businesses in {$city_list} tell us about what providers are and aren't serving them? What's the evidence from the score and pain point patterns?

**UNDERSERVED NICHES** — 3–5 specific segments within this market that combine:
- High need (low average scores)
- Low existing provider attention (usually visible from how basic the problems are — providers who'd been there would have fixed them)
- Realistic budget (the segment can pay)
For each: name it, size it roughly, describe the typical profile gap, and explain why existing providers are likely missing it.

**THE WHITESPACE PLAY** — 1 paragraph. The single most defensible niche position in this market based on what the data shows. Where a consultant could own a category rather than compete in a crowded one.

**WHAT TO WATCH** — 2–3 signals that would indicate a competitor is moving into this whitespace. What to do to preempt it.

Rules: Ground everything in the data. Avoid generic "find a niche" advice. Be specific about which categories, why they're underserved, and what the consultant would need to do to own the position.
PROMPT;

            case 'case_study_template':
                return <<<PROMPT
{$context}

Write a Case Study Template for a consultant who works with {$industry_name} businesses in {$city_list}. This template is pre-filled with the market context and has clear placeholders for real client data.

The case study format should work as:
- A website page
- A PDF leave-behind
- A section in a proposal

Output the complete template with fill-in placeholders marked as [PLACEHOLDER]:

**HEADLINE** — Data-driven. References the transformation. "[CLIENT NAME] Went From [BEFORE SCORE/STATE] to [AFTER STATE] in [TIMEFRAME]"

**THE SITUATION** (2 paragraphs)
Paragraph 1: The context — what {$industry_name} businesses typically look like in {$city_list} and why it matters. (Pre-filled with market data — no placeholder needed.)
Paragraph 2: This client's specific situation before engagement. [PLACEHOLDER: describe their before state]

**THE PROBLEM IN NUMBERS** — 3 specific metrics showing the before state. [PLACEHOLDER: score, review count, photo count, etc.]

**WHAT WE DID** (1–2 paragraphs) — The approach. [PLACEHOLDER: specific services/actions taken]. Pre-fill the methodology section with what typically happens in a profile optimization engagement.

**THE RESULTS** — 3–5 measurable outcomes. [PLACEHOLDER: score improvement, new reviews, ranking change, leads generated]. Include a quote placeholder: "[CLIENT QUOTE]"

**THE TAKEAWAY** (1 paragraph) — What this tells other {$industry_name} owners. Pre-filled — references the broader market data.

**CTA** — One sentence inviting similar businesses to learn if they have the same problem.

Rules: The pre-filled sections should be strong enough to use as social proof even before the consultant has a real client story. The placeholders should be specific enough that filling them in takes 10 minutes, not an hour.
PROMPT;

            case 'webinar_outline':
                return <<<PROMPT
{$context}

Write a complete 45-minute webinar outline for a consultant presenting to a room of {$industry_name} business owners in {$city_list}. This is designed for a local BNI chapter, chamber of commerce, or industry association event.

Title: "Why Most {$industry_name} Businesses in {$city_list} Are Invisible Online — And What to Do About It"

Output:

**PRE-WEBINAR SETUP** — What to send attendees 24 hours before. Subject line + 3-sentence reminder email.

**FULL RUNNING ORDER** with timestamps:
- 0:00–5:00 — Opening
- 5:00–15:00 — The Market Data Presentation
- 15:00–25:00 — The Three Problems
- 25:00–35:00 — The Fix (Live Demo opportunity)
- 35:00–42:00 — Q&A
- 42:00–45:00 — The Offer

For each segment:
- **What the presenter says** (key talking points, not a script)
- **What's on the screen** (slide description)
- **The goal** of this segment

**THE DATA SLIDES** — Describe 4 slides that use the market scan data ({$avg_score}/100 average, {$industry_name} breakdown, pain points). What they show, what the presenter says about each.

**THE OFFER** — How to close the webinar with a specific, low-friction next step. Not a hard sell. Concrete and time-limited.

**POST-WEBINAR EMAIL** — Follow-up email to all attendees. Subject + body (under 150 words). Includes the offer from the closing.

Rules: The talking points should be specific to {$industry_name} and {$city_list} — not generic digital marketing content. The market data is the credibility engine. Use it throughout.
PROMPT;

            case 'video_script_series':
                return <<<PROMPT
{$context}

Write 3 short-form video scripts (60–90 seconds each when spoken) for a consultant posting on YouTube Shorts, Instagram Reels, or TikTok about the {$industry_name} market in {$city_list}.

These are educational, value-first videos — not ads. The consultant's service is mentioned only in the CTA at the end of each.

**VIDEO 1: The Stat**
Hook: A single surprising number from the market data that stops a {$industry_name} owner from scrolling.
Body: What that number means in practical terms. What it costs a business that ignores it.
CTA: One sentence. Low friction.

**VIDEO 2: The Mistake**
Hook: The single most common mistake {$industry_name} businesses make with their online presence.
Body: Why they make it, what it actually costs them, what to do instead.
CTA: One sentence. Different from Video 1.

**VIDEO 3: The Quick Win**
Hook: One thing a {$industry_name} owner can fix in 10 minutes that will immediately improve their visibility.
Body: Walk through exactly what to do. Specific steps.
CTA: Tease the next level — what fixing the easy stuff reveals about the harder problems.

For each script:
- **The full script** written for spoken delivery (not reading aloud — conversational)
- **On-screen text suggestions** — 3 text overlays per video
- **B-roll suggestions** — 2–3 ideas for what to show while talking
- **Thumbnail concept** — what the still image should show and say

Rules: Under 90 seconds when read at a natural pace. Hook must be in the first 3 seconds. Never start with "Hey guys" or "Welcome back". Write as if the consultant is already the recognized local expert.
PROMPT;

            case 'proposal_template':
                return <<<PROMPT
{$context}

Write a Proposal Template for a consultant pitching a digital marketing or web design engagement to a {$industry_name} business owner in {$city_list}.

The proposal should close deals — not inform them. Every section should push toward yes.

Output the complete template with [PLACEHOLDER] markers where consultant fills in client-specific details:

**COVER PAGE** — Proposal title, client name placeholder, date, consultant name/logo placeholder.

**EXECUTIVE SUMMARY** (1 page) — Opens with the prospect's specific situation [PLACEHOLDER: their scan score and top 2 pain points]. Describes the cost of inaction. Positions the proposed engagement as the logical response. Under 250 words.

**THE SITUATION** — What the data shows about [CLIENT NAME]'s current online presence. Pre-fill the market context ({$industry_name} in {$city_list} averaging {$industry_score}/100). Leave placeholders for their specific numbers.

**WHAT WE'LL DO** — 3–4 specific deliverables. Each with:
- What it is (plain language)
- Why it matters for this client
- What success looks like

**INVESTMENT** — How to present pricing. One recommended option. Optional upgrade. Payment terms. [PLACEHOLDER: insert actual figures]

**TIMELINE** — 4-week milestone plan. Pre-filled with standard phases. [PLACEHOLDER: specific dates]

**WHY US** — 3 sentences. Specific to this market. References the scan data as proof of local expertise.

**NEXT STEP** — One clear action. Deadline creates gentle urgency without pressure.

Rules: Proposal should read in under 10 minutes. Every section earns its place. Remove anything that only makes the consultant feel good about themselves.
PROMPT;

            // ── Tier 4 ──────────────────────────────────────────────────────

            case 'press_release':
                return <<<PROMPT
{$context}

Write a newsworthy press release announcing local market research findings about {$industry_name} businesses in {$city_list}.

This should be genuinely submittable to a local business journal, chamber newsletter, or regional news outlet. It should read like research, not a marketing exercise.

**FORMAT:**
FOR IMMEDIATE RELEASE

[HEADLINE] — Specific, data-driven, newsworthy. Quantified. Example: "New Data: {$avg_score}% of {$city_primary} {$industry_name} Businesses Score Below Average on Google — Costing Them Customers Daily"

[DATELINE] — {$city_primary}, [Month Year] —

[LEAD PARAGRAPH] — The who, what, where, when of the finding. Under 50 words.

[BODY — 3 paragraphs]
Para 1: Key finding and what it means for consumers in the market.
Para 2: The three most significant pain points and their business impact. Specific.
Para 3: Context — how these findings compare to what well-performing businesses look like and what the data suggests about market trends.

[QUOTE] — A quote attributed to "[Consultant Name], [City] Digital Marketing Consultant". Write it to sound like a real expert opinion, not marketing copy.

[ABOUT THE RESEARCH] — 2 sentences. Methodology, sample size ({$total_scans} businesses), market covered.

[MEDIA CONTACT] — [PLACEHOLDER: name, email, phone]

Rules: It must pass the "would a journalist actually print this?" test. No promotional language about the consultant's services. The data is the story.
PROMPT;

            case 'franchise_brief':
                return <<<PROMPT
{$context}

Write a Franchise / Multi-Location Prospecting Brief — a targeted document for approaching a franchise brand or regional chain whose individual location profiles the scan data shows are consistently weak.

This is a B2B pitch to a marketing director or regional operations manager, not a local business owner.

Output:

**THE EXECUTIVE BRIEF** (1 page equivalent)
- What the scan data shows about [FRANCHISE CATEGORY] location profiles in {$city_list}
- The consistency of the problem across locations (this is the key point — it's systemic, not one bad location)
- What it's costing them at the network level (extrapolate: if each location loses 2 customers/month, across N locations = X)
- The proposed engagement structure for multi-location work

**THE PITCH EMAIL** — To a regional marketing director or franchise development contact. Subject + body (under 200 words). Leads with the data, not the service.

**THE DECISION MAKER MAP** — Who to approach at a franchise organization and in what order. Typical titles, their concerns, and what each cares about most.

**PRICING FRAMING FOR MULTI-LOCATION** — How to structure and present pricing for 5, 10, or 20+ location engagements. Per-location vs. flat fee vs. retainer models. What each signals.

**THE OBJECTION UNIQUE TO THIS SALE** — "We have a corporate marketing team for this." Write 2 responses that acknowledge the reality and reframe the local gap as something corporate can't solve from the top.

Rules: Position the consultant as a local market specialist, not a generalist agency. The data is the proof. Multi-location deals are won on evidence of systemic problems, not on the consultant's credentials.
PROMPT;

            case 'referral_partner_script':
                return <<<PROMPT
{$context}

Write a Referral Partner Script for a consultant approaching non-competing service providers who serve the same {$industry_name} business owners in {$city_list}.

Target partners: accountants, commercial insurance agents, attorneys, commercial bankers, equipment suppliers — anyone who has a trusted relationship with local business owners but doesn't compete with digital marketing.

Output:

**THE INITIAL APPROACH** — A 3-sentence pitch for when the consultant meets a potential referral partner at a networking event or reaches out cold. Natural, not rehearsed-sounding.

**THE PARTNER PITCH EMAIL** — Subject line + body (under 150 words). Explains the mutual value. Leads with what the partner's clients get.

**THE MUTUAL VALUE PROPOSITION** — 1 paragraph. Written for the partner to share with their own network: "I've partnered with [Consultant] because…"

**THE REFERRAL PROCESS** — How it works in practice. Step by step. What the consultant does when they receive a referral, how they handle the relationship, how they report back to the partner.

**COMPENSATION OPTIONS** — 3 structures the consultant can offer (not all referral partners want money — some want reciprocal referrals, co-branded content, or joint speaking opportunities). Pros and cons of each.

**THE CONVERSATION STARTERS** — 5 natural ways the partner can introduce the consultant's value to a client without it feeling like a pitch. Written in the partner's voice, not the consultant's.

Rules: Referral partnerships die when they feel transactional. Write everything from the angle of genuine mutual benefit. The market data is the trust-builder — partners can see that their clients have real, documented problems.
PROMPT;

            case 'newsletter_template':
                return <<<PROMPT
{$context}

Write a Monthly Market Update Newsletter Template for a consultant who wants to stay top-of-mind with their list of {$industry_name} business owners and prospects in {$city_list}.

This newsletter goes out once a month. It must feel worth opening — not like a marketing email.

**THE TEMPLATE STRUCTURE:**

**Subject Line Formulas** — 5 subject line formats they can rotate monthly:
- One data-driven
- One question
- One contrarian take
- One "what I noticed this month"
- One seasonal/timely

**SECTION 1: The Market Pulse** (100–150 words)
A brief update on what the consultant has observed scanning local businesses this month. New patterns, surprising scores, industries moving up or down. [PLACEHOLDER: insert this month's observations]. Pre-write the framing so only the specific data changes monthly.

**SECTION 2: The Insight** (150–200 words)
One substantive observation about {$industry_name} businesses — something they can act on. Not a teaser for a paid service. Genuine value. Pre-write a template with [PLACEHOLDER: this month's insight].

**SECTION 3: The One-Thing** (50–75 words)
One specific, actionable tip a business owner can do this week to improve their online presence. Small enough to do. Real enough to matter.

**SECTION 4: The Soft CTA** (2–3 sentences)
A low-friction invitation to learn more, book a call, or reply with a question. Not a hard sell.

**FOOTER** — Unsubscribe language, contact info placeholders.

Rules: Every section should be completable in under 30 minutes of work per month. The market scan data is the content engine — as long as the consultant is scanning, they have something to say.
PROMPT;

            case 'media_pitch':
                return <<<PROMPT
{$context}

Write a Local Media Pitch — a pitch letter positioning a consultant as an expert source on local business digital trends for a journalist, podcast host, or local business publication editor in {$city_list}.

Output:

**THE EMAIL PITCH** — Subject line + body (under 200 words). Leads with the data. Pitches the consultant as a source, not a story subject. Makes it easy for the journalist to say yes.

**THE STORY ANGLES** — 3 specific story pitches the journalist could run:
For each: headline, why it's newsworthy now, the data angle (from the scan data), who the human sources would be, what makes it local and specific.

**THE PODCAST PITCH** — A slightly different version of the email pitch adapted for a local business or entrepreneur podcast. 150 words. Leads with the conversation angle, not the data.

**THE EXPERT BIO** — 3 sentences. Establishes local credibility. Mentions the scan data as primary research. Written in third person for the journalist/host to use.

**MEDIA KIT OUTLINE** — What the consultant should have ready if a journalist expresses interest. 5–6 items, each with a one-sentence description of what it contains.

Rules: Never pitch a story about the consultant. Pitch a story about the market that the consultant can illuminate. Journalists don't care about your services — they care about their readers.
PROMPT;

            case 'grant_proposal':
                return <<<PROMPT
{$context}

Write a Grant / Sponsorship Proposal for a consultant approaching a local economic development organization, SBA district office, SBDC, or regional bank about sponsoring their market research as a community resource.

The angle: the scan data is genuinely valuable public intelligence about local business health. Sponsoring its continuation and publication benefits the sponsor's mission (supporting local businesses) and gives the consultant funding to scale the research.

Output:

**THE EXECUTIVE SUMMARY** (1 page) — What the research is, what it shows, why it matters to the local business community, and what the sponsor would be funding. Written for a program officer or community investment director.

**THE COMMUNITY CASE** — 3 paragraphs. Why this data matters beyond the consultant's business. What local business owners gain from having access to it. How it could inform local business support programs.

**THE SPONSORSHIP PACKAGES** — 3 tiers:
- Research Sponsor (highest): what they get (co-branding, data access, speaking opportunity, press release credit)
- Community Partner (mid): what they get
- Supporting Sponsor (entry): what they get
For each: suggested contribution range and specific deliverables.

**THE PITCH MEETING REQUEST** — Email to the program director requesting a 20-minute conversation. Subject + body (under 150 words).

**POTENTIAL OBJECTIONS** — 3 concerns a program officer might raise and how to respond.

Rules: Write the community case first — if it doesn't hold up without the consultant's business interest, the pitch won't work. The data must be genuinely useful to others, not just a marketing tool.
PROMPT;

            case 'white_label_package':
                return <<<PROMPT
{$context}

Write a White Label Report Package — a framework for a consultant to license or co-brand their local market intelligence to a larger consulting firm, marketing agency, or chamber of commerce that wants to publish it under their own brand.

Output:

**THE LICENSING PITCH** — How to approach a potential white label buyer. Email pitch (subject + under 150 words) to an agency principal or association director. Leads with what they get, not what the consultant is selling.

**THE THREE LICENSING MODELS**
For each model, describe:
- What the buyer gets (data access, report customization, co-branding rights)
- What the consultant retains
- Suggested pricing structure
- Who this model is right for

Model 1: One-time report license (buy the research, publish it once)
Model 2: Ongoing data partnership (recurring access to new scan data as it accumulates)
Model 3: Branded intelligence platform (buyer presents the scan tool to their own clients as their proprietary research)

**THE DELIVERABLES LIST** — What the consultant actually hands over in a white label deal. Specific. Formats, file types, what's customizable.

**THE CONTRACT LANGUAGE OUTLINE** — The 5 key clauses a white label agreement needs. Not legal advice — just the business terms to cover. (Attribution, exclusivity, data ownership, update cadence, termination.)

**THE UPSELL PATH** — How a white label relationship typically evolves into a larger engagement. What to watch for and how to propose the next level.

Rules: The white label model is about the data being the product, not the consultant's time. Write from that angle. The goal is for the buyer to see the scan data as a competitive advantage they can deploy under their own brand.
PROMPT;

            // ── Tier 5 ──────────────────────────────────────────────────────

            case 'paid_intelligence_brief':
                return <<<PROMPT
{$context}

Design a Paid Intelligence Product — a recurring subscription briefing a consultant launches to sell their local market scan data directly, without it being tied to a consulting engagement.

Buyers: local business owners who want to know where they stand relative to their category, associations who want member value, and other agencies or consultants in adjacent markets who want the data without doing the research.

Output:

**THE PRODUCT CONCEPT** (2 paragraphs) — What the product is, what subscribers receive, how often, and why they pay for it instead of just asking the consultant for a free report. What makes it worth a recurring subscription specifically — not a one-time purchase.

**PRICING MODEL** — 3 tiers with specific price points:
- Individual business subscription (monthly or annual, what they get)
- Association / organization license (covers all members, what it includes, bulk pricing logic)
- Competitor agency license (access to the raw data or the formatted report under their own brand)
For each tier: the buyer profile, what they get, and what the consultant retains (data ownership, client rights, exclusivity terms).

**THE PRODUCT ITSELF** — Describe exactly what each issue/report contains. Sections, format, length, how data from {$total_scans} scans across {$city_list} gets translated into a subscriber-ready deliverable. Be specific about what changes each month and what stays templated.

**LAUNCH SEQUENCE** — How to get the first 50 paying subscribers without a large existing audience:
- The founding member offer (price, urgency, what makes it compelling to commit before it exists)
- The 3 distribution channels most likely to convert for this specific market
- The first email to send to the existing lead list

**PLATFORM RECOMMENDATION** — Substack vs. Beehiiv vs. Ghost vs. direct invoice. For each: what it handles well and where it breaks down for this use case. One clear recommendation with the reason.

Rules: The product must be completable in under 4 hours per issue. If it requires more than that, the price point is wrong or the scope is too wide. Write the whole thing from the angle that the consultant's time is the scarce resource.
PROMPT;

            case 'score_directory':
                return <<<PROMPT
{$context}

Design a Local Business Score Directory — a public-facing website called "[{$city_primary}] Business Profile Index" (or equivalent) where any business owner can look up their Google Business Profile score and see how they rank against competitors in their category.

The directory is a lead generation engine. Businesses who find a low score are warm prospects. The site generates organic search traffic and local press without cold outreach.

Output:

**THE CONCEPT** (2 paragraphs) — What the site is, who uses it, why it works as a lead magnet, and why no one else in {$city_list} has built it yet. What makes it credible rather than self-promotional.

**SITE STRUCTURE** — Every page the site needs:
- Homepage (what it shows, what the CTA is)
- Category index pages (one per industry in the scan data — list them based on: {$industry_name} and others in the data)
- Individual business score pages (what they show, how they're generated, what the upgrade path is)
- About / Methodology page (critical for credibility — what to include)
- Contact / Audit request page
For each page: the SEO purpose, the primary user action, and the lead capture mechanism.

**THE SEO STRATEGY** — Specific to {$city_list} and the industries in the scan data. Which pages target which keywords. How category pages create topical authority. How individual business pages generate long-tail traffic. Estimated time to first rankings based on competition levels typical for local markets.

**THE LEAD MECHANISM** — How a business owner who finds their score converts to a consultation. The exact flow from "I looked up my score" to "I booked a call." What the free experience shows them and what requires a conversation to get.

**THE PRESS ANGLE** — How to get local media to cover the directory launch. The story pitch (2–3 sentences), the journalist to target (title/beat, not a specific person), and what makes it newsworthy on day one before it has traffic.

**MONETIZATION BEYOND CONSULTING** — 2 additional revenue streams the directory can generate once it has traffic: one passive, one active.

Rules: The directory must be buildable on WordPress or a simple static site. Do not design something that requires custom development. The lead mechanism must feel like a service to the business owner, not a trap.
PROMPT;

            case 'academic_partnership':
                return <<<PROMPT
{$context}

Write an Academic Research Partnership Pitch for approaching a local university business school, community college business program, or MBA faculty member about using the scan data as the foundation for original research.

No federal grants. No government agencies. This is a direct relationship with a faculty member or department chair who needs primary data and can't easily get it.

What the consultant has that academics want: longitudinal, granular, local business health data that no one else is collecting. What academics have that the consultant wants: institutional credibility, co-authorship on published research, speaking invitations, and access to their student and alumni network of local business owners.

Output:

**THE FACULTY PITCH EMAIL** — Subject line + body (under 200 words). Written to a marketing, entrepreneurship, or small business management professor. Leads with the research opportunity, not the consultant's business. Proposes a specific initial collaboration — not an open-ended "let's talk."

**THE RESEARCH ANGLES** — 3 specific study proposals that could come from this data:
For each:
- Working title
- Research question
- Why it's publishable (what gap it fills in existing literature — keep this credible, not inflated)
- The data the consultant contributes
- What the faculty member contributes (methodology, IRB if needed, writing, journal submission)
- Realistic publication timeline

**THE MUTUAL VALUE STATEMENT** — 1 paragraph each:
- What the university/faculty gets from this partnership
- What the consultant gets
Written so either party could share it internally to get sign-off.

**THE FIRST MEETING AGENDA** — A 30-minute meeting outline. What to cover, what to propose, what to leave with. The goal is a specific follow-up commitment, not a general "let's explore this."

**THE CREDIBILITY BUILDERS** — What the consultant should have ready before reaching out: 3 things that make this pitch land as a peer-level conversation rather than a vendor pitch.

**THE STUDENT PIPELINE ANGLE** — How a university partnership creates a recurring pipeline of future clients: students who become business owners, alumni networks, MBA consulting projects that use the scan data. Specifically how to propose this without it sounding transactional.

Rules: No mention of government funding, grants, or federal programs. This is a direct academic relationship built on mutual research interest. The consultant is a primary data source and a practitioner-researcher, not a vendor.
PROMPT;

            case 'city_hall_brief':
                return <<<PROMPT
{$context}

Write a City / County Economic Development Brief for presenting the scan data to a local government economic development office, mayor's small business advisory council, downtown business improvement district, or county chamber economic committee.

Local government only — city councils, county commissions, BIDs, downtown development authorities. No state agencies, no federal programs, no grants with strings.

The frame: this data is a local economic health indicator that city staff and elected officials can't get from any other source. Every quarter, the consultant can show them whether the online health of local businesses is improving or declining — and which industries and neighborhoods are most at risk.

Output:

**THE ONE-PAGE BRIEF** — A clean, print-ready summary:
- Headline: a specific, data-driven statement about {$city_list} local businesses
- 3 key findings from the scan data (specific, quantified, locally relevant)
- What the data means for local economic health — not for the consultant's business
- A methodology note (credibility paragraph)
- A proposed ongoing relationship (data sharing, quarterly report, presentation to council)
- Contact line: [Consultant Name] · [Website] · [Phone]

**THE MEETING REQUEST EMAIL** — To the city's economic development director or the mayor's small business liaison. Subject + body (under 150 words). Leads with community value. The ask is a 20-minute meeting, not a contract.

**THE COUNCIL PRESENTATION OUTLINE** — If they invite the consultant to present to a city council committee or BID board: a 10-minute presentation structure with talking points. What slides to show, what data to feature, what the ask is at the end.

**WHAT TO NEVER SAY IN THESE MEETINGS** — 3 things that will kill the relationship with local government immediately. Written plainly — what consultants typically do wrong when pitching public officials.

**THE ONGOING DATA RELATIONSHIP** — What a recurring arrangement looks like in practice. How often, what format, what the city gets, what the consultant gets (which must be non-monetary — what you actually get is access, visibility, and referrals). How to propose this without it sounding like a sales pitch.

**THE REFERRAL PATHWAY** — How a relationship with the economic development office turns into direct client referrals. Specifically how local EDOs connect businesses to service providers and how to get onto that list.

Rules: No federal language anywhere. Keep it local — city, county, BID, downtown authority. The consultant is a community resource, not a vendor. The pitch works because the data is genuinely useful to the city, not because the consultant needs a contract.
PROMPT;

            case 'competitive_intel_service':
                return <<<PROMPT
{$context}

Design a Competitive Intelligence Service — a recurring B2B product the consultant sells directly to individual business owners: monthly competitor profile tracking, delivered as a clean report, no consulting engagement required.

The client pays monthly. The consultant scans the client's top competitors every month and sends a comparison report. No cold outreach to renew — the value is visible every month in the report.

Output:

**THE SERVICE DEFINITION** — Exactly what the client gets each month:
- What gets scanned (their profile + how many competitors, how chosen)
- What the report shows (score comparisons, month-over-month changes, new reviews, photo counts, profile updates)
- Format and delivery (email PDF, shared dashboard, Google Doc — recommend one and explain why)
- Time commitment from the client: zero — they just receive the report

**PRICING MODEL** — 3 tiers based on number of competitors tracked:
- Starter: 3 competitors, monthly (what it includes, suggested price)
- Standard: 7 competitors, monthly (what it includes, suggested price)
- Pro: 15 competitors + quarterly strategy call (what it includes, suggested price)
For each: who it's right for, the annual value argument, and the churn risk (what makes clients cancel and how to mitigate it).

**THE FIRST CLIENT ACQUISITION** — How to get the first 3 clients from the existing scan data without cold outreach. The businesses already in the database who would benefit most from this product — specifically which profile characteristics identify a good competitive intel buyer. The exact outreach (email or phone script, under 100 words) that converts a scan lead into a recurring subscriber.

**THE MONTHLY WORKFLOW** — Exactly what the consultant does each month to deliver this service. Step by step. Time estimate. What can be automated vs. what requires human judgment. Target: under 45 minutes per client per month.

**RETENTION MECHANICS** — What keeps clients paying month after month:
- The moment in the report that creates the most anxiety (and why that's a retention tool, not a problem)
- The quarterly check-in format that prevents churn before it starts
- What to do when a client's competitor improves significantly — the opportunity in that moment

**SCALING WITHOUT ADDING TIME** — How to go from 5 clients to 20 without the monthly workflow multiplying proportionally. What to systematize, what to templatize, and at what client count to hire or outsource.

Rules: No jargon. Write for a consultant who has never run a productized service before. Every workflow step should be specific enough to execute, not just described in concept.
PROMPT;

            case 'acquisition_package':
                return <<<PROMPT
{$context}

Write an Agency Acquisition Package — the documents and narrative a consultant uses to position their practice for acquisition by a larger agency that wants local market intelligence capability, a proven lead generation system, and an existing client base.

This is not generic "how to sell your business" advice. It is specifically how to frame this particular asset stack — scan data, lead capture system, Market Intel outputs, and client relationships — as something an acquirer will pay a premium for.

Output:

**THE ASSET INVENTORY** — A clear-eyed list of what the consultant actually owns and what it's worth to a buyer:
- The scan database ({$total_scans} scans, industries represented, cities covered, time depth)
- The lead capture system (shortcode, email capture, lead pipeline — what it generates monthly)
- The Market Intel output library (what's been generated, what the prompts are worth as IP)
- Active clients (without naming them — describe as recurring vs. project, industry mix, average tenure)
- The brand and domain (local authority positioning, any press or backlinks)
For each asset: what it's worth to a strategic buyer vs. a financial buyer, and what makes it defensible (i.e., hard for the buyer to replicate without the acquisition).

**THE ONE-PAGE TEASER** — What a buyer sees before signing an NDA:
- Business overview (no sensitive numbers — just what it is and why it's interesting)
- The data asset (why it exists, what it covers, why it compounds over time)
- The lead system (what it produces, what the conversion looks like)
- Why now (what makes this an interesting acquisition at this moment in the market)
- Contact line for interested parties

**THE SELLER NARRATIVE** — The story the consultant tells in the first meeting with a potential acquirer. Not a pitch — a conversation. What to lead with, what to hold back until LOI, and what to never volunteer. 3–4 paragraphs written as talking points.

**THE BUYER PROFILE** — Who actually buys practices like this and why:
- The national agency with no local intelligence capability (what they want, what they'll pay for)
- The regional agency looking to expand market coverage (different motivation, different price frame)
- The private equity-backed roll-up acquiring local marketing practices (what their diligence looks like)
For each: what they care about most, how to frame the data asset for their specific motivation, and what deal structure they typically prefer.

**THE VALUATION FRAME** — How to think about what this is worth without a broker. What multiples apply to recurring revenue vs. project revenue vs. data assets. What the scan database and lead system contribute to valuation beyond EBITDA. How to present this to a buyer who's never acquired a data asset before.

Rules: No inflated claims. A buyer will do diligence — everything in this package must hold up. The goal is to frame the asset accurately and compellingly, not to oversell and lose the deal in diligence.
PROMPT;

            case 'annual_summit':
                return <<<PROMPT
{$context}

Design an Annual Local Business Intelligence Summit — a live event the consultant positions as the definitive annual gathering for local business owners in {$city_list} who want to understand where they stand online and what to do about it.

This is not a networking mixer. It is a content-driven event where the consultant presents original market research, brings in credible local speakers, and closes a room full of exactly the right prospects — without it feeling like a sales event.

Output:

**THE EVENT CONCEPT** (2 paragraphs) — What happens, who it's for, why it becomes an annual institution rather than a one-off event, and what makes it different from every other local business event in {$city_list}. The name, the positioning line, and the reason business owners will actually show up.

**THE AGENDA** — Full half-day event structure (3–4 hours):
For each segment:
- Title and duration
- What happens (keynote, panel, workshop, presentation, Q&A)
- Who presents (consultant, local speaker, business owner case study)
- The goal of this segment — what the audience leaves knowing or feeling

The consultant's research presentation should be the anchor — build the agenda around it.

**SPONSORSHIP PACKAGES** — 3 tiers:
- Title Sponsor (local bank, commercial insurance firm, or accounting firm — who, what they get, suggested price)
- Presenting Sponsor (2–3 available — who, what they get, suggested price)  
- Supporting Sponsor (5–8 available — who, what they get, suggested price)
For each: the pitch in one sentence, what their logo/name appears on, what access they get to attendees, and why this is worth more than a directory ad.

**THE MARKETING SEQUENCE** — How to fill the room (100–200 attendees target) without a large email list:
- 8 weeks out through event day: what goes out when, to whom, through which channel
- The channels that work for this specific market (local business media, chamber, BID, email list, social) — rank them by expected yield
- The early bird / founding attendee offer that creates urgency

**THE REVENUE MODEL** — How the event pays for itself and generates profit:
- Ticket pricing (free vs. paid — make the case for one over the other for this specific market)
- Sponsorship revenue (realistic total based on the packages above)
- Backend consulting pipeline (how many attendees become clients, at what conversion rate, what that's worth)
- The following year uplift (why year 2 is easier and more profitable than year 1)

**THE CLOSE** — How the consultant moves from the stage to signed clients without the event feeling like a pitch fest. The specific moment in the agenda designed to create demand. What the offer is, how it's presented, and how to handle the conversation in the room afterward.

Rules: No LinkedIn promotion. The event marketing must work through email, local media, chamber relationships, and direct outreach to the businesses in the scan database. The summit should feel like the consultant is doing the market a service — the consulting pipeline is the byproduct, not the point.
PROMPT;

            default:
                return new \WP_Error( 'unknown_action', "Unknown Market Intel action: {$action}" );
        }
    }

}
