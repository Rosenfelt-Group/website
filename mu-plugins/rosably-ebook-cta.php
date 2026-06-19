<?php
/**
 * Plugin Name: Rosably eBook CTA + Lead Gate
 * Description: Drives traffic to the "Beyond the Chatbot" eBook from three surfaces:
 *              (1) an end-of-post block on single blog posts, (3) a credibility block
 *              on the /services/ page, and (4) an exit-intent / scroll slide-in shown
 *              only on blog posts and the services page (never the homepage).
 *
 *              The eBook page itself is readable ungated. The downloadable PDF is the
 *              lead magnet: a name+email form (on the eBook page, #get-pdf) posts to
 *              /wp-json/rosably/v1/ebook-lead, which persists the lead to Supabase
 *              (assessment_leads, source='ebook') + emails Brian + emails the visitor a
 *              signed link. The PDF is served only via /wp-json/rosably/v1/ebook-download
 *              behind an HMAC token; the raw file is blocked from direct HTTP access.
 *
 * All markup uses inline styles to match the rest of the site's mu-plugins (no asset
 * pipeline). Brand: accent #C05621, ink #1A1A1A, off-white #F6F1EB, Plus Jakarta Sans.
 */

if (!defined('ABSPATH')) { exit; }

// The /services/ page id (per docs reference). Slug match is a belt-and-suspenders fallback.
if (!defined('ROSABLY_SERVICES_PAGE_ID')) { define('ROSABLY_SERVICES_PAGE_ID', 642); }

// Filesystem path to the gated PDF (blocked from direct HTTP access via .htaccess).
if (!defined('ROSABLY_EBOOK_PDF_PATH')) {
    define('ROSABLY_EBOOK_PDF_PATH', WP_CONTENT_DIR . '/uploads/ebook-assets/beyond-the-chatbot.pdf');
}

function rosably_ebook_url() {
    return home_url('/beyond-the-chatbot/');
}

/** Where every "download" affordance points — the gated form on the eBook page. */
function rosably_ebook_getpdf_url() {
    return rosably_ebook_url() . '#get-pdf';
}

/** Secret lookup: rosably-secrets.php constant first, then docker-compose env var. */
function rosably_ebook_secret($name) {
    if (function_exists('rosably_secret')) {
        $v = rosably_secret($name);
        if ($v) { return $v; }
    }
    return (string) getenv($name);
}

function rosably_is_services_page() {
    return is_page(ROSABLY_SERVICES_PAGE_ID) || is_page('services');
}

/* ---------------------------------------------------------------------------
 * REST: lead capture + gated download
 * ------------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('rosably/v1', '/ebook-lead', [
        'methods'             => 'POST',
        'callback'            => 'rosably_ebook_lead_handler',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('rosably/v1', '/ebook-download', [
        'methods'             => 'GET',
        'callback'            => 'rosably_ebook_download_handler',
        'permission_callback' => '__return_true',
    ]);
});

/** Persist the lead to Supabase (assessment_leads, source='ebook'). Non-blocking. */
function rosably_ebook_persist_lead($name, $email, $company) {
    $url = rosably_ebook_secret('ROSABLY_SUPABASE_URL');
    $key = rosably_ebook_secret('ROSABLY_SUPABASE_SERVICE_KEY');
    if (!$url || !$key) {
        error_log('rosably ebook-lead: Supabase credentials not configured.');
        return false;
    }
    $response = wp_remote_post(rtrim($url, '/') . '/rest/v1/assessment_leads', [
        'timeout' => 10,
        'headers' => [
            'apikey'        => $key,
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=minimal',
        ],
        'body' => wp_json_encode([
            'name'   => $name,
            'email'  => $email,
            'org'    => $company,
            'source' => 'ebook',
        ]),
    ]);
    if (is_wp_error($response)) {
        error_log('rosably ebook-lead wp_error: ' . $response->get_error_message());
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        error_log('rosably ebook-lead Supabase HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
        return false;
    }
    return true;
}

/** Signed, per-email download URL (HMAC with the service key). */
function rosably_ebook_signed_url($email) {
    $key   = rosably_ebook_secret('ROSABLY_SUPABASE_SERVICE_KEY');
    $token = hash_hmac('sha256', strtolower($email), $key);
    return add_query_arg(
        ['e' => rawurlencode($email), 't' => $token],
        rest_url('rosably/v1/ebook-download')
    );
}

function rosably_ebook_lead_handler(WP_REST_Request $request) {
    $name    = sanitize_text_field($request->get_param('name'));
    $email   = sanitize_email($request->get_param('email'));
    $company = sanitize_text_field($request->get_param('company'));

    if (!$name || !is_email($email)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Please enter your name and a valid email.'], 400);
    }

    // Persist before email so a filtered/lost email doesn't lose the lead. Non-fatal.
    $persisted = rosably_ebook_persist_lead($name, $email, $company);

    // Lead alert to Brian (plain text).
    wp_mail(
        'brian@rosably.com',
        'New eBook Lead — Beyond the Chatbot',
        "New eBook PDF download:\n\n"
        . "Name:    {$name}\n"
        . "Email:   {$email}\n"
        . "Company: {$company}\n"
        . "Source:  Beyond the Chatbot eBook\n"
        . 'Persisted to Supabase: ' . ($persisted ? 'yes' : 'NO (email-only fallback)') . "\n"
    );

    $download = rosably_ebook_signed_url($email);

    // Send the visitor their copy.
    wp_mail(
        $email,
        'Your copy of “Beyond the Chatbot”',
        "Hi {$name},\n\n"
        . "Thanks for your interest — here's your copy of Beyond the Chatbot:\n\n"
        . "{$download}\n\n"
        . "It's a short read on building AI that actually remembers your business. If you'd like to talk "
        . "through what AI could do for you, just reply to this email or book a free call:\n"
        . "https://rosably.com/contact/\n\n"
        . "— Rosably\n",
        ['From: Rosably <brian@rosably.com>', 'Reply-To: brian@rosably.com']
    );

    return new WP_REST_Response([
        'success'   => true,
        'persisted' => $persisted,
        'pdf_url'   => $download,
    ], 200);
}

function rosably_ebook_download_handler(WP_REST_Request $request) {
    $email = strtolower(sanitize_email($request->get_param('e')));
    $token = (string) $request->get_param('t');

    if (!is_email($email)) {
        return new WP_REST_Response('Invalid request.', 400);
    }
    $key = rosably_ebook_secret('ROSABLY_SUPABASE_SERVICE_KEY');
    if (!$key) {
        return new WP_REST_Response('Service unavailable.', 503);
    }
    $expected = hash_hmac('sha256', $email, $key);
    if (!hash_equals($expected, $token)) {
        return new WP_REST_Response('Forbidden.', 403);
    }
    if (!file_exists(ROSABLY_EBOOK_PDF_PATH)) {
        return new WP_REST_Response('File not found.', 404);
    }

    // Stream the file and stop WordPress from emitting anything else.
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="beyond-the-chatbot.pdf"');
    header('Content-Length: ' . filesize(ROSABLY_EBOOK_PDF_PATH));
    header('Cache-Control: private, no-store, max-age=0');
    readfile(ROSABLY_EBOOK_PDF_PATH);
    exit;
}

/* ---------------------------------------------------------------------------
 * (1) + (3) Inline CTA cards
 * ------------------------------------------------------------------------- */

function rosably_ebook_cta_card($variant = 'post') {
    $url = esc_url(rosably_ebook_url());
    $pdf = esc_url(rosably_ebook_getpdf_url());

    if ($variant === 'services') {
        $eyebrow = 'The thinking behind our work';
        $blurb   = 'A short, plain-English read on how we build AI that actually remembers — and why that&rsquo;s the difference between a demo and a system you can rely on.';
        $btn     = 'Read the free eBook &rarr;';
    } else {
        $eyebrow = 'Free eBook';
        $blurb   = 'Why most business AI forgets &mdash; and what it takes to build AI that learns your business and keeps it. A 12-minute read.';
        $btn     = 'Read the eBook &rarr;';
    }

    return '<div style="margin:48px auto;max-width:680px;background:#1A1A1A;border-radius:14px;'
         . 'padding:32px 34px;color:#F6F1EB;font-family:\'Plus Jakarta Sans\',system-ui,sans-serif;">'
         . '<div style="font-size:11px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#C05621;margin-bottom:12px;">'
         . esc_html($eyebrow) . '</div>'
         . '<div style="font-size:26px;font-weight:800;letter-spacing:-.01em;margin-bottom:10px;color:#fff;">Beyond the Chatbot</div>'
         . '<p style="font-size:16px;line-height:1.55;color:#C9C3B8;margin:0 0 22px;">' . $blurb . '</p>'
         . '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:16px;">'
         . '<a href="' . $url . '" style="display:inline-block;padding:14px 28px;background:#C05621;color:#fff;'
         . 'text-decoration:none;border-radius:8px;font-weight:700;font-size:16px;">' . $btn . '</a>'
         . '<a href="' . $pdf . '" style="color:#C9C3B8;text-decoration:none;font-size:14px;font-weight:600;">'
         . 'or get the PDF &rarr;</a>'
         . '</div>'
         . '</div>';
}

function rosably_ebook_append_cta($content) {
    if (!is_main_query() || !in_the_loop()) {
        return $content;
    }
    if (is_page('beyond-the-chatbot')) {
        return $content;
    }
    if (is_singular('post')) {
        return $content . rosably_ebook_cta_card('post');
    }
    if (rosably_is_services_page()) {
        return $content . rosably_ebook_cta_card('services');
    }
    return $content;
}
add_filter('the_content', 'rosably_ebook_append_cta', 20);

/* ---------------------------------------------------------------------------
 * (4) Exit-intent / scroll slide-in — blog posts and the services page only.
 * ------------------------------------------------------------------------- */

function rosably_ebook_slidein() {
    if (!(is_singular('post') || rosably_is_services_page())) {
        return;
    }
    if (is_page('beyond-the-chatbot')) {
        return;
    }
    $url = esc_url(rosably_ebook_url());
    $pdf = esc_url(rosably_ebook_getpdf_url());
    ?>
<style>
  #rsb-eb-slidein {
    position: fixed; right: 22px; bottom: 22px; z-index: 99990;
    width: 340px; max-width: calc(100vw - 32px);
    background: #1A1A1A; color: #F6F1EB; border-radius: 14px;
    box-shadow: 0 18px 50px rgba(0,0,0,.32);
    font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
    padding: 22px 22px 20px;
    transform: translateY(160%); opacity: 0;
    transition: transform .42s cubic-bezier(.22,.61,.36,1), opacity .42s;
  }
  #rsb-eb-slidein.rsb-show { transform: translateY(0); opacity: 1; }
  #rsb-eb-slidein .rsb-eyebrow { font-size: 10.5px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; color: #C05621; margin-bottom: 9px; }
  #rsb-eb-slidein h4 { font-size: 19px; font-weight: 800; line-height: 1.2; margin: 0 0 8px; color: #fff; }
  #rsb-eb-slidein p { font-size: 14px; line-height: 1.5; color: #C9C3B8; margin: 0 0 16px; }
  #rsb-eb-slidein a.rsb-go { display: inline-block; padding: 11px 20px; background: #C05621; color: #fff;
    text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 14.5px; }
  #rsb-eb-slidein a.rsb-pdf { margin-left: 14px; color: #C9C3B8; text-decoration: none; font-size: 13px; font-weight: 600; }
  #rsb-eb-slidein a.rsb-pdf:hover { color: #F6F1EB; }
  #rsb-eb-slidein button.rsb-x { position: absolute; top: 10px; right: 12px; background: none; border: none;
    color: #8C867B; font-size: 20px; line-height: 1; cursor: pointer; padding: 4px; }
  #rsb-eb-slidein button.rsb-x:hover { color: #F6F1EB; }
</style>
<div id="rsb-eb-slidein" role="dialog" aria-label="Beyond the Chatbot eBook">
  <button class="rsb-x" aria-label="Dismiss">&times;</button>
  <div class="rsb-eyebrow">Before you go &mdash; free eBook</div>
  <h4>Beyond the Chatbot</h4>
  <p>Building AI that learns your business &mdash; and keeps it. A 12-minute read on what separates a demo from a system.</p>
  <a class="rsb-go" href="<?php echo $url; ?>">Read the eBook &rarr;</a>
  <a class="rsb-pdf" href="<?php echo $pdf; ?>">Get the PDF</a>
</div>
<script>
(function () {
  var KEY = 'rsb_eb_slidein_seen';
  try { if (sessionStorage.getItem(KEY)) return; } catch (e) {}
  var el = document.getElementById('rsb-eb-slidein');
  if (!el) return;
  var shown = false;

  function seen() { try { sessionStorage.setItem(KEY, '1'); } catch (e) {} }
  function show() {
    if (shown) return;
    shown = true;
    el.classList.add('rsb-show');
    seen();
    document.removeEventListener('mouseleave', onLeave);
    window.removeEventListener('scroll', onScroll);
  }
  function hide() { el.classList.remove('rsb-show'); }

  function onLeave(e) { if (e.clientY <= 0) show(); }
  function onScroll() {
    var h = document.documentElement;
    var scrolled = (h.scrollTop || document.body.scrollTop);
    var height = (h.scrollHeight - h.clientHeight);
    if (height > 0 && scrolled / height > 0.55) show();
  }

  el.querySelector('.rsb-x').addEventListener('click', function () { hide(); seen(); });
  document.addEventListener('mouseleave', onLeave);
  window.addEventListener('scroll', onScroll, { passive: true });
})();
</script>
    <?php
}
add_action('wp_footer', 'rosably_ebook_slidein');
