<?php
/**
 * Plugin Name: Rosably Site Customizations
 * Description: Latest-posts shortcode for homepage blog feed. Footer credit is set via Kadence theme mod.
 */

/**
 * [rosably_latest_posts count=3]
 * Renders a 3-column grid of recent posts styled to match the site design system.
 */
function rosably_render_latest_posts( $atts ) {
    $atts  = shortcode_atts( [ 'count' => 3 ], $atts );
    $posts = get_posts( [
        'numberposts' => (int) $atts['count'],
        'post_status' => 'publish',
    ] );

    if ( empty( $posts ) ) {
        return '<p style="color:#4A4A4A;text-align:center;">No posts yet — check back soon.</p>';
    }

    $cards = '';
    foreach ( $posts as $post ) {
        $url      = esc_url( get_permalink( $post ) );
        $title    = esc_html( get_the_title( $post ) );
        $cats     = get_the_category( $post );
        $cat_name = ! empty( $cats ) ? esc_html( $cats[0]->name ) : '';
        $raw_excerpt = get_the_excerpt( $post );
        if ( ! $raw_excerpt ) {
            $raw_excerpt = wp_strip_all_tags( get_the_content( null, false, $post ) );
        }
        $excerpt = esc_html( wp_trim_words( $raw_excerpt, 20, '…' ) );

        $eyebrow = $cat_name
            ? '<span style="font-size:9px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;color:#C05621;display:block;margin-bottom:10px;">' . $cat_name . '</span>'
            : '';

        $cards .= '<div style="background:#fff;border:1px solid rgba(26,26,26,0.08);border-radius:4px;padding:28px 24px;display:flex;flex-direction:column;">'
            . $eyebrow
            . '<h3 style="font-size:18px;font-weight:700;color:#1A1A1A;margin:0 0 12px;letter-spacing:-0.015em;line-height:1.3;">' . $title . '</h3>'
            . '<p style="font-size:14px;color:#4A4A4A;line-height:1.75;margin:0 0 20px;flex:1;">' . $excerpt . '</p>'
            . '<a href="' . $url . '" style="font-size:12px;font-weight:600;color:#C05621;text-decoration:none;letter-spacing:0.04em;text-transform:uppercase;">Read more &#8594;</a>'
            . '</div>';
    }

    return '<style>.rsb-blog-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;max-width:960px;margin:0 auto;}'
        . '@media(max-width:768px){.rsb-blog-grid{grid-template-columns:1fr;}}</style>'
        . '<div class="rsb-blog-grid">' . $cards . '</div>';
}
add_shortcode( 'rosably_latest_posts', 'rosably_render_latest_posts' );
