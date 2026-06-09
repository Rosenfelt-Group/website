<?php
/**
 * Plugin Name: Rosably Assessment
 * Description: REST endpoints for the AI quiz — lead capture + alert email, server-side AI snapshot (Anthropic), and Stripe Checkout session for the AI Opportunity Review.
 */

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Return a secret constant defined in the (gitignored, docker-cp'd) rosably-secrets.php.
 * Never echo the value; callers surface a generic 500 when it's missing.
 */
function rosably_secret( $name ) {
    return defined( $name ) ? (string) constant( $name ) : '';
}

/**
 * Best-effort client IP for rate-limiting. Prefer the first X-Forwarded-For hop
 * (the site sits behind a proxy, so REMOTE_ADDR is often the gateway and would
 * bucket every visitor together), falling back to REMOTE_ADDR.
 */
function rosably_client_ip() {
    if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
        $ip    = trim( $parts[0] );
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/* -------------------------------------------------------------------------
 * REST routes
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', function() {
    register_rest_route( 'rosably/v1', '/submit-assessment', [
        'methods'             => 'POST',
        'callback'            => 'rosably_handle_assessment_submission',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'rosably/v1', '/generate-snapshot', [
        'methods'             => 'POST',
        'callback'            => 'rosably_handle_generate_snapshot',
        'permission_callback' => '__return_true',
    ] );

    register_rest_route( 'rosably/v1', '/create-checkout', [
        'methods'             => 'POST',
        'callback'            => 'rosably_handle_create_checkout',
        'permission_callback' => '__return_true',
    ] );

    // Direct-link buy path for the /services/ page CTAs. GET so a plain <a href>
    // works; creates a fresh Checkout Session per click (sessions expire) and
    // 302-redirects to it. Email is collected on the Stripe Checkout page itself.
    register_rest_route( 'rosably/v1', '/buy/stack-audit', [
        'methods'             => 'GET',
        'callback'            => 'rosably_handle_buy_stack_audit',
        'permission_callback' => '__return_true',
    ] );
} );

/* -------------------------------------------------------------------------
 * 1) Lead capture + emails
 * ---------------------------------------------------------------------- */

/**
 * Persist a quiz lead to Supabase assessment_leads.
 * Returns true on success, false on any failure (non-blocking — email is the backup).
 */
function rosably_persist_lead( $name, $email, $org, $type, $stage, array $pain, $blocker, $vision ) {
    // Try PHP constant first (rosably-secrets.php), fall back to env var (docker-compose).
    $url = rosably_secret( 'ROSABLY_SUPABASE_URL' ) ?: (string) getenv( 'ROSABLY_SUPABASE_URL' );
    $key = rosably_secret( 'ROSABLY_SUPABASE_SERVICE_KEY' ) ?: (string) getenv( 'ROSABLY_SUPABASE_SERVICE_KEY' );
    if ( ! $url || ! $key ) {
        error_log( 'rosably persist_lead: Supabase credentials not configured.' );
        return false;
    }

    $response = wp_remote_post( rtrim( $url, '/' ) . '/rest/v1/assessment_leads', [
        'timeout' => 10,
        'headers' => [
            'apikey'        => $key,
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=minimal',
        ],
        'body' => wp_json_encode( [
            'name'           => $name,
            'email'          => $email,
            'org'            => $org,
            'org_type'       => $type,
            'ai_stage'       => $stage,
            'pain_points'    => $pain,
            'blocker'        => $blocker,
            'success_vision' => $vision,
            'source'         => 'quiz',
        ] ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( 'rosably persist_lead wp_error: ' . $response->get_error_message() );
        return false;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        error_log( 'rosably persist_lead Supabase HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
        return false;
    }

    return true;
}

function rosably_handle_assessment_submission( WP_REST_Request $request ) {
    $name    = sanitize_text_field( $request->get_param( 'name' ) );
    $email   = sanitize_email( $request->get_param( 'email' ) );
    $org     = sanitize_text_field( $request->get_param( 'org' ) );
    $type    = sanitize_text_field( $request->get_param( 'org_type' ) );
    $stage   = sanitize_text_field( $request->get_param( 'ai_stage' ) );
    $blocker = sanitize_text_field( $request->get_param( 'blocker' ) );
    $vision  = sanitize_textarea_field( $request->get_param( 'success_vision' ) );

    $pain = $request->get_param( 'pain_points' );
    $pain = is_array( $pain ) ? array_map( 'sanitize_text_field', $pain ) : [];
    $pain_str = implode( ', ', $pain );

    if ( ! $name || ! is_email( $email ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid input.' ], 400 );
    }

    // Persist to Supabase before email — a filtered/lost email no longer loses the lead.
    $persisted = rosably_persist_lead( $name, $email, $org, $type, $stage, $pain, $blocker, $vision );
    if ( ! $persisted ) {
        // Non-fatal: emails are the backup. Log already written inside persist_lead.
        // Return 207 so the React app can surface a non-blocking warning.
        $lead_warning = true;
    }

    // Brian lead alert (plain text).
    wp_mail(
        'brian@rosably.com',
        "New Quiz Lead — {$type} — {$stage}",
        "New quiz submission:\n\n"
        . "Name:           {$name}\n"
        . "Email:          {$email}\n"
        . "Organization:   {$org}\n"
        . "Org type:       {$type}\n"
        . "AI stage:       {$stage}\n"
        . "Time wasters:   {$pain_str}\n"
        . "Biggest blocker:{$blocker}\n"
        . "90-day success: {$vision}\n"
    );

    // Prospect confirmation (HTML, no attachment).
    $name_esc = esc_html( $name );
    $prospect_body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#ffffff;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;">
  <tr>
    <td align="center" style="padding:40px 20px;">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;font-family:Arial,sans-serif;font-size:16px;line-height:1.6;color:#1A1A1A;">
        <tr>
          <td style="padding-bottom:24px;">
            <p style="margin:0 0 16px 0;">Hi {$name_esc},</p>
            <p style="margin:0 0 16px 0;">Thanks for taking the AI quiz. Your personalized AI snapshot was generated and is waiting for you on screen &#8212; head back to the tab where you took the quiz to read it.</p>
            <p style="margin:0;">If you&#8217;re ready to go deeper, the full AI Opportunity Review gives you a complete assessment, a detailed findings report, and a prioritized roadmap built specifically for your organization.</p>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 0 32px 0;">
            <a href="https://rosably.com/services/" style="display:inline-block;padding:14px 28px;background:#C05621;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;font-size:16px;">Get your full AI Opportunity Review &#8212; \$750</a>
          </td>
        </tr>
        <tr>
          <td style="padding-top:24px;border-top:1px solid #e5e5e5;">
            <p style="margin:0 0 4px 0;">&#8212; Brian Rosenfelt<br>Rosably</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;

    wp_mail(
        $email,
        "Your AI snapshot is ready — here's what we found",
        $prospect_body,
        [
            'Content-Type: text/html; charset=UTF-8',
            'From: Rosably <brian@rosably.com>',
            'Reply-To: brian@rosably.com',
        ]
    );

    // The on-screen snapshot is the deliverable; never block results on a mail hiccup.
    // If Supabase persistence failed, return 207 so the client can surface a soft warning.
    $status = ( $lead_warning ?? false ) ? 207 : 200;
    return new WP_REST_Response( [ 'success' => true, 'persisted' => ! ( $lead_warning ?? false ) ], $status );
}

/* -------------------------------------------------------------------------
 * 2) Server-side AI snapshot (Anthropic) — key never reaches the browser
 * ---------------------------------------------------------------------- */

function rosably_handle_generate_snapshot( WP_REST_Request $request ) {
    $org     = sanitize_text_field( $request->get_param( 'org_name' ) );
    $type    = sanitize_text_field( $request->get_param( 'org_type' ) );
    $stage   = sanitize_text_field( $request->get_param( 'ai_stage' ) );
    $blocker = sanitize_text_field( $request->get_param( 'blocker' ) );
    $vision  = sanitize_textarea_field( $request->get_param( 'success_vision' ) );

    $pain = $request->get_param( 'pain_points' );
    $pain = is_array( $pain ) ? array_map( 'sanitize_text_field', $pain ) : [];

    if ( ! $org || ! $type || ! $stage || empty( $pain ) || ! $blocker || ! $vision ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing required fields.' ], 400 );
    }

    // Rate limit. The per-IP cap is best-effort fairness only — behind a proxy the
    // client IP comes from X-Forwarded-For, which the client can spoof to mint fresh
    // buckets. The global hourly cap is the real backstop: it hard-bounds Anthropic
    // spend even if an attacker rotates the per-IP key on every request.
    $ip_key       = 'rosably_snapshot_' . md5( rosably_client_ip() );
    $global_key   = 'rosably_snapshot_global';
    $ip_count     = (int) get_transient( $ip_key );
    $global_count = (int) get_transient( $global_key );
    if ( $ip_count >= 3 || $global_count >= 40 ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Too many requests' ], 429 );
    }
    set_transient( $ip_key, $ip_count + 1, HOUR_IN_SECONDS );
    set_transient( $global_key, $global_count + 1, HOUR_IN_SECONDS );

    $key = rosably_secret( 'ROSABLY_ANTHROPIC_API_KEY' );
    if ( ! $key ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Snapshot service unavailable.' ], 500 );
    }

    $pain_str = implode( ', ', $pain );

    $system = "You are a warm, direct AI consultant writing a brief personalized AI snapshot for a prospective client. Write in plain English — no jargon, no consultant-speak. Never use these words: stack, tooling, leverage, empower, synergy, robust, utilize, core gap, pain points, best practices, cutting-edge, seamlessly. Write as if you are a trusted colleague who has just reviewed their situation.";

    $user = "Write a brief AI snapshot report for {$org}, a {$type}.\n\n"
        . "Their situation:\n"
        . "- Where they are with AI: {$stage}\n"
        . "- Where time gets wasted: {$pain_str}\n"
        . "- What's holding them back: {$blocker}\n"
        . "- What success looks like in 90 days: {$vision}\n\n"
        . "Write exactly this structure (use these exact headers):\n\n"
        . "**What we're seeing**\n"
        . "2-3 sentences summarizing their situation in plain language, specific to their org type and answers. Mirror their language from the 90-day vision answer.\n\n"
        . "**Where the quick wins are**\n"
        . "3 bullet points — specific, actionable opportunities based on their time wasters and org type. Each bullet one sentence.\n\n"
        . "**The one thing holding most organizations like yours back**\n"
        . "2 sentences addressing their specific blocker directly and honestly. Don't sugarcoat it.\n\n"
        . "**What this could look like in 90 days**\n"
        . "2-3 sentences painting a specific picture of success that echoes their 90-day vision. Make it feel achievable.\n\n"
        . "End with this exact line (fill in org_name):\n"
        . "'This is a preview. A full AI Opportunity Review goes 12x deeper — with findings and a prioritized roadmap built specifically for {$org}.'\n\n"
        . "Keep the total response under 350 words.";

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 30,
        'headers' => [
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ],
        'body' => wp_json_encode( [
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => 1000,
            'system'     => $system,
            'messages'   => [
                [ 'role' => 'user', 'content' => $user ],
            ],
        ] ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( 'rosably generate-snapshot: ' . $response->get_error_message() );
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Could not generate snapshot.' ], 502 );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || empty( $body['content'][0]['text'] ) ) {
        $detail = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : "HTTP {$code}";
        error_log( 'rosably generate-snapshot Anthropic error: ' . $detail );
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Could not generate snapshot.' ], 502 );
    }

    return new WP_REST_Response( [ 'snapshot' => $body['content'][0]['text'] ], 200 );
}

/* -------------------------------------------------------------------------
 * 3) Stripe Checkout session for the AI Opportunity Review ($750)
 * ---------------------------------------------------------------------- */

/**
 * Create a fresh Stripe Checkout Session for the AI Opportunity Review ($750).
 * Sessions expire, so this is always called per-request — never cache the URL.
 *
 * $email and $org are optional. When $email is empty, Stripe collects the email
 * on the Checkout page itself (the direct-link path from /services/). $org is
 * added to metadata only when present so the n8n handler can enrich the work item.
 *
 * Returns [ 'url' => string ] on success, or [ 'error' => 'no_key' | 'failed' ].
 */
function rosably_create_checkout_session( $email = '', $org = '', $source = 'quiz' ) {
    $key = rosably_secret( 'ROSABLY_STRIPE_SECRET_KEY' );
    if ( ! $key ) {
        return [ 'error' => 'no_key' ];
    }

    $metadata = [
        'product' => 'ai_opportunity_review',
        'source'  => $source,
    ];
    if ( $org ) {
        $metadata['org_name'] = $org;
    }

    // Array body → wp_remote_post form-encodes nested keys (line_items[0][price], etc.).
    $body = [
        'payment_method_types' => [ 'card' ],
        'line_items'           => [
            [ 'price' => 'price_1TYriNLcDxAuVnkzQXYuA2Il', 'quantity' => 1 ],
        ],
        'mode'        => 'payment',
        'success_url' => 'https://rosably.com/stack-audit-confirmed/',
        'cancel_url'  => 'https://rosably.com/services/',
        'metadata'    => $metadata,
    ];
    if ( $email ) {
        $body['customer_email'] = $email;
    }

    $response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', [
        'timeout' => 20,
        'headers' => [ 'Authorization' => 'Bearer ' . $key ],
        'body'    => $body,
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( 'rosably checkout-session: ' . $response->get_error_message() );
        return [ 'error' => 'failed' ];
    }

    $code  = wp_remote_retrieve_response_code( $response );
    $rbody = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || empty( $rbody['url'] ) ) {
        $detail = is_array( $rbody ) && isset( $rbody['error']['message'] ) ? $rbody['error']['message'] : "HTTP {$code}";
        error_log( 'rosably checkout-session Stripe error: ' . $detail );
        return [ 'error' => 'failed' ];
    }

    return [ 'url' => $rbody['url'] ];
}

/**
 * POST /create-checkout — called from the quiz flow with org_name + email
 * (which pre-fills the Stripe Checkout page). Returns JSON { url }.
 */
function rosably_handle_create_checkout( WP_REST_Request $request ) {
    $org   = sanitize_text_field( $request->get_param( 'org_name' ) );
    $email = sanitize_email( $request->get_param( 'email' ) );

    if ( ! $org || ! is_email( $email ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid input.' ], 400 );
    }

    $result = rosably_create_checkout_session( $email, $org, 'quiz' );

    if ( ! empty( $result['url'] ) ) {
        return new WP_REST_Response( [ 'url' => $result['url'] ], 200 );
    }
    if ( ( $result['error'] ?? '' ) === 'no_key' ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Checkout service unavailable.' ], 500 );
    }
    return new WP_REST_Response( [ 'success' => false, 'message' => 'Could not start checkout.' ], 502 );
}

/**
 * GET /buy/stack-audit — direct-link path from the /services/ page CTAs.
 * No params: email is collected on the Stripe Checkout page. 302-redirects to
 * the fresh session URL, or back to /services/ with a flag if Stripe fails.
 */
function rosably_handle_buy_stack_audit( WP_REST_Request $request ) {
    $result = rosably_create_checkout_session( '', '', 'services_page' );

    if ( ! empty( $result['url'] ) ) {
        wp_redirect( $result['url'], 302 );
        exit;
    }

    wp_redirect( 'https://rosably.com/services/?checkout=error', 302 );
    exit;
}
