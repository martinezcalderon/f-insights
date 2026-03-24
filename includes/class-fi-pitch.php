<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FI_Pitch
 *
 * Two distinct outreach generators:
 *
 *  generate()       → Cold pitch for Prospects. They've never heard of you.
 *  generate_reply() → Warm follow-up draft for Leads. They requested the report.
 */
class FI_Pitch {

    // ── Shared helpers ────────────────────────────────────────────────────────

    private static function extract_intel( ?object $scan ): array {
        $intel = [
            'weakest_cats'          => '',
            'competitive_narrative' => '',
            'sentiment_summary'     => '',
            'pagespeed_score'       => '',
            'priority_action'       => '',
        ];

        if ( ! $scan || empty( $scan->report_json ) ) return $intel;
        $report = json_decode( $scan->report_json, true );
        if ( ! $report ) return $intel;

        $cats       = $report['categories'] ?? [];
        $cat_labels = FI_Utils::cat_labels();

        if ( ! empty( $cats ) ) {
            $sorted = $cats;
            uasort( $sorted, fn( $a, $b ) => ( $a['score'] ?? 100 ) <=> ( $b['score'] ?? 100 ) );
            $lines = [];
            foreach ( array_slice( $sorted, 0, 2, true ) as $key => $cat ) {
                $label    = $cat_labels[ $key ] ?? $key;
                $headline = $cat['headline'] ?? '';
                $score    = $cat['score']    ?? '';
                if ( $headline ) $lines[] = "- {$label} ({$score}/100): {$headline}";
            }
            $intel['weakest_cats']      = implode( "\n", $lines );
            $intel['sentiment_summary'] = $cats['customer_reviews']['sentiment_summary'] ?? '';
            if ( isset( $cats['pagespeed_insights']['score'] ) ) {
                $intel['pagespeed_score'] = (int) $cats['pagespeed_insights']['score'];
            }
        }

        $intel['competitive_narrative'] = $report['competitive_narrative'] ?? '';

        $actions = $report['priority_actions'] ?? [];
        if ( ! empty( $actions ) ) {
            $first = $actions[0];
            $intel['priority_action'] = ( $first['title'] ?? '' )
                . ( isset( $first['description'] ) ? ' — ' . $first['description'] : '' );
        }

        return $intel;
    }

    private static function claude( string $prompt, string $system, int $max_tokens = 500 ): string|\WP_Error {
        $model = get_option( 'fi_claude_model_admin', get_option( 'fi_claude_model', 'claude-haiku-4-5-20251001' ) );
        return FI_Claude::request( $prompt, [
            'model'      => $model,
            'system'     => $system,
            'max_tokens' => $max_tokens,
            'timeout'    => 45,
        ] );
    }

    // ── Cold Pitch — Prospects only ───────────────────────────────────────────

    public static function generate( int $lead_id ) {
        $lead = FI_Leads::get( $lead_id );
        if ( ! $lead ) return new WP_Error( 'not_found', 'Lead not found.' );

        $scan     = FI_DB::get_scan_by_id( $lead->scan_id );
        $score    = (int) $lead->overall_score;
        $business = $lead->business_name;
        $category = $lead->category ?? 'local business';
        $intel    = self::extract_intel( $scan );

        $blocks = [];
        if ( $intel['weakest_cats'] )
            $blocks[] = "Two lowest-scoring areas (AI headline from actual data):\n" . $intel['weakest_cats'];
        if ( $intel['competitive_narrative'] )
            $blocks[] = "Competitive context — use competitor names and numbers if present:\n" . $intel['competitive_narrative'];
        if ( $intel['sentiment_summary'] )
            $blocks[] = "What their customers say in reviews:\n" . $intel['sentiment_summary'];
        if ( $intel['pagespeed_score'] !== '' )
            $blocks[] = "PageSpeed score: {$intel['pagespeed_score']}/100";
        if ( $intel['priority_action'] )
            $blocks[] = "Highest-impact fix identified:\n" . $intel['priority_action'];

        $data_block = $blocks
            ? implode( "\n\n", $blocks )
            : 'No detailed scan data — use score and category only.';

        $score_label = $score >= 80 ? 'above average but with a specific ceiling'
                     : ( $score >= 60 ? 'average — visible locally but losing ground to competitors'
                                      : 'well below competitors in this area' );

        $prompt = <<<PROMPT
Write a cold outreach email to the owner of {$business}, a {$category}.

Their Google Business Profile was scanned. Here is what the data actually shows:
Overall score: {$score}/100 ({$score_label})

{$data_block}

Write 3 short paragraphs. No greeting. No sign-off.

Paragraph 1 (1-2 sentences): Open with the single most striking specific finding — a named competitor with a rating or review gap, a PageSpeed number, a direct headline from their weakest area. Something they'd want to fact-check because it's that specific. Never open with the overall score. Never say "your online presence needs work."

Paragraph 2 (1-2 sentences): State the highest-impact fix and what changes if they do it. Concrete. No generic best-practice language.

Paragraph 3 (1 sentence): One ask — reply or book 15 minutes. Nothing else.

Hard rules:
- Every claim must be grounded in the data above. Do not invent specifics.
- If competitor names are in the competitive context, use them.
- Total: under 110 words.
- Write like a peer who looked at the data, not like a marketing agency.
PROMPT;

        $text = self::claude( $prompt,
            'You are a local marketing specialist writing cold outreach emails grounded in real scan data. You are specific, direct, and human. You never use generic phrases. If the data supports naming a competitor or citing a number, you use it. If it does not, you say something true and brief rather than fabricating detail.'
        );

        if ( is_wp_error( $text ) ) return $text;
        if ( ! $text ) return new WP_Error( 'empty_response', 'Empty response from Claude.' );

        $subject = $score < 60
            ? "Found something in {$business}'s Google profile"
            : "{$business}'s local search profile: one thing worth knowing";

        return [ 'subject' => $subject, 'body' => $text, 'email' => $lead->email ?? '' ];
    }

    // ── Warm Reply Draft — Leads only ─────────────────────────────────────────

    public static function generate_reply( int $lead_id ) {
        $lead = FI_Leads::get( $lead_id );
        if ( ! $lead ) return new WP_Error( 'not_found', 'Lead not found.' );

        $scan     = FI_DB::get_scan_by_id( $lead->scan_id );
        $score    = (int) $lead->overall_score;
        $business = $lead->business_name;
        $category = $lead->category ?? 'local business';
        $intel    = self::extract_intel( $scan );

        $blocks = [];
        if ( $intel['weakest_cats'] )
            $blocks[] = "Their two lowest-scoring areas:\n" . $intel['weakest_cats'];
        if ( $intel['priority_action'] )
            $blocks[] = "Highest-impact fix the report identified:\n" . $intel['priority_action'];
        if ( $intel['competitive_narrative'] )
            $blocks[] = "How they compare to competitors:\n" . $intel['competitive_narrative'];
        if ( $intel['sentiment_summary'] )
            $blocks[] = "What their customers say:\n" . $intel['sentiment_summary'];

        $context_block = $blocks
            ? implode( "\n\n", $blocks )
            : 'Score and category only — no detailed breakdown available.';

        $score_label = $score >= 80 ? 'solid overall but with a specific gap worth a conversation'
                     : ( $score >= 60 ? 'average with fixable gaps'
                                      : 'significantly below where it could be' );

        $prompt = <<<PROMPT
Draft a short follow-up email reply for someone who just received a Google Business Profile audit report for {$business}, a {$category}.

Their score: {$score}/100 ({$score_label})

What the report found:
{$context_block}

Important context: They requested this report themselves — they already know their score and have seen the full breakdown. This is NOT a pitch. Write as a knowledgeable person who reviewed their report and has something useful to add.

Write 3 short paragraphs. No formal greeting. No sign-off.

Paragraph 1 (1-2 sentences): Pick the finding that best explains why their score matters in practice — not the score itself, but what it means for how customers find or choose them. Be specific to a {$category} if possible.

Paragraph 2 (2 sentences): Add one thing the automated report can't easily convey — context about why their biggest gap matters specifically for this type of business, or what closing it realistically looks like. Be honest: if something is genuinely strong, say so. Don't manufacture urgency.

Paragraph 3 (1 sentence): Offer something concrete — a short call to go through it together, or a single direct question that invites them to respond.

Rules:
- Warm, direct, no jargon, no sales language.
- Do not re-explain the score — they have the report.
- Under 140 words total.
PROMPT;

        $text = self::claude( $prompt,
            'You are a local marketing specialist writing a follow-up email to someone who requested a Google Business Profile audit. Your tone is warm, knowledgeable, and zero-pressure. You write like a trusted advisor — helpful first, commercial second. You never send generic follow-ups: every reply is specific to what the data actually shows.',
            600
        );

        if ( is_wp_error( $text ) ) return $text;
        if ( ! $text ) return new WP_Error( 'empty_response', 'Empty response from Claude.' );

        return [
            'subject' => "Re: Your {$business} profile report",
            'body'    => $text,
            'email'   => $lead->email ?? '',
        ];
    }
}
