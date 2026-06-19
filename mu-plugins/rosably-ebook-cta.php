<?php
/**
 * Plugin Name: Rosably eBook CTA
 * Description: Drives traffic to the "Beyond the Chatbot" eBook from three surfaces:
 *              (1) an end-of-post block on single blog posts, (3) a credibility block
 *              on the /services/ page, and (4) an exit-intent / scroll slide-in shown
 *              only on blog posts and the services page (never the homepage).
 *
 * All markup uses inline styles to match the rest of the site's mu-plugins (no asset
 * pipeline). Brand: accent #C05621, ink #1A1A1A, off-white #F6F1EB, Plus Jakarta Sans.
 */

if (!defined('ABSPATH')) { exit; }

// The /services/ page id (per docs reference). Slug match is a belt-and-suspenders fallback.
if (!defined('ROSABLY_SERVICES_PAGE_ID')) { define('ROSABLY_SERVICES_PAGE_ID', 642); }

function rosably_ebook_url() {
    return home_url('/beyond-the-chatbot/');
}

function rosably_ebook_pdf_url() {
    return home_url('/wp-content/uploads/beyond-the-chatbot.pdf');
}

function rosably_is_services_page() {
    return is_page(ROSABLY_SERVICES_PAGE_ID) || is_page('services');
}

/**
 * Render an inline CTA card. $variant = 'post' | 'services'.
 */
function rosably_ebook_cta_card($variant = 'post') {
    $url = esc_url(rosably_ebook_url());

    if ($variant === 'services') {
        $eyebrow = 'The thinking behind our work';
        $heading = 'Beyond the Chatbot';
        $blurb   = 'A short, plain-English read on how we build AI that actually remembers — and why that&rsquo;s the difference between a demo and a system you can rely on.';
        $btn     = 'Read the free eBook &rarr;';
    } else {
        $eyebrow = 'Free eBook';
        $heading = 'Beyond the Chatbot';
        $blurb   = 'Why most business AI forgets &mdash; and what it takes to build AI that learns your business and keeps it. A 12-minute read.';
        $btn     = 'Read the eBook &rarr;';
    }

    $pdf = esc_url(rosably_ebook_pdf_url());

    return '<div style="margin:48px auto;max-width:680px;background:#1A1A1A;border-radius:14px;'
         . 'padding:32px 34px;color:#F6F1EB;font-family:\'Plus Jakarta Sans\',system-ui,sans-serif;">'
         . '<div style="font-size:11px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#C05621;margin-bottom:12px;">'
         . esc_html($eyebrow) . '</div>'
         . '<div style="font-size:26px;font-weight:800;letter-spacing:-.01em;margin-bottom:10px;color:#fff;">'
         . esc_html($heading) . '</div>'
         . '<p style="font-size:16px;line-height:1.55;color:#C9C3B8;margin:0 0 22px;">' . $blurb . '</p>'
         . '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:16px;">'
         . '<a href="' . $url . '" style="display:inline-block;padding:14px 28px;background:#C05621;color:#fff;'
         . 'text-decoration:none;border-radius:8px;font-weight:700;font-size:16px;">' . $btn . '</a>'
         . '<a href="' . $pdf . '" download style="color:#C9C3B8;text-decoration:none;font-size:14px;font-weight:600;">'
         . '&darr; Download PDF</a>'
         . '</div>'
         . '</div>';
}

/**
 * (1) + (3) Append the CTA card to single blog posts and the services page.
 */
function rosably_ebook_append_cta($content) {
    if (!is_main_query() || !in_the_loop()) {
        return $content;
    }
    // Never on the eBook page itself.
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

/**
 * (4) Exit-intent / scroll-depth slide-in — blog posts and the services page only.
 * Shows at most once per browser session (sessionStorage), on either exit-intent
 * (cursor leaves the top of the viewport) or after scrolling 55% of the page.
 */
function rosably_ebook_slidein() {
    if (!(is_singular('post') || rosably_is_services_page())) {
        return;
    }
    if (is_page('beyond-the-chatbot')) {
        return;
    }
    $url = esc_url(rosably_ebook_url());
    $pdf = esc_url(rosably_ebook_pdf_url());
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
  <a class="rsb-pdf" href="<?php echo $pdf; ?>" download>PDF</a>
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
