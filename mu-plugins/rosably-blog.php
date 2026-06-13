<?php
/**
 * Plugin Name: Rosably Blog
 * Description: Custom blog listing page with client-side category filtering.
 */

add_shortcode( 'rosably_blog', 'rosably_render_blog' );

function rosably_render_blog() {
    // Build post data array
    $query = new WP_Query( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        // Intentional: small dataset (<50 posts). Revisit if blog grows beyond ~100.
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    $posts = [];
    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();
            $id      = get_the_ID();
            $cats    = get_the_category( $id );
            $cat_arr = [];
            foreach ( $cats as $cat ) {
                $cat_arr[] = [ 'name' => $cat->name, 'slug' => $cat->slug ];
            }

            $raw_excerpt = get_the_excerpt();
            if ( ! $raw_excerpt ) {
                $raw_excerpt = wp_trim_words(
                    wp_strip_all_tags( get_the_content() ),
                    30,
                    '…'
                );
            }

            $posts[] = [
                'id'        => $id,
                'title'     => get_the_title(),
                'excerpt'   => $raw_excerpt,
                'permalink' => get_permalink(),
                'date'      => get_the_date( 'M j, Y' ),
                'categories'=> $cat_arr,
            ];
        }
    }
    wp_reset_postdata();

    $json = wp_json_encode( $posts, JSON_HEX_TAG | JSON_HEX_AMP );
    ob_start();
    ?>
    <script type="application/json" id="rsb-posts-data"><?php echo $json; ?></script>

    <style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
    /* Kill Kadence wrapper padding */
    .entry-content-wrap { padding: 0 !important; }
    .rsb-blog, .rsb-blog * { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; box-sizing: border-box; }

    /* Hero */
    .rsb-blog-hero { background: #1A1A1A; padding: 56px 40px 48px; }
    .rsb-blog-hero h1 { font-size: 40px; font-weight: 800; color: #F6F1EB; margin: 0 0 10px; letter-spacing: -0.03em; line-height: 1.1; }
    .rsb-blog-hero p  { font-size: 16px; color: rgba(246,241,235,0.65); margin: 0; line-height: 1.7; }

    /* Filter bar */
    #rsb-filter-bar { position: sticky; top: 0; z-index: 10; background: #fff; border-bottom: 1px solid rgba(26,26,26,0.08); padding: 14px 40px; display: flex; gap: 8px; flex-wrap: wrap; }
    .rsb-pill { font-family: inherit; font-size: 10px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; padding: 6px 14px; border-radius: 20px; border: 1px solid rgba(26,26,26,0.12); background: #F6F1EB; color: #555; cursor: pointer; transition: background 0.12s, color 0.12s, border-color 0.12s; }
    .rsb-pill.rsb-active, .rsb-pill:hover { background: #C05621; color: #fff; border-color: #C05621; }

    /* Grid */
    #rsb-card-grid { background: #F6F1EB; padding: 40px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; min-height: 200px; }
    @media (max-width: 1023px) { #rsb-card-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 767px)  { #rsb-card-grid { grid-template-columns: 1fr; padding: 24px 20px; } #rsb-filter-bar { padding: 12px 20px; } }

    /* Cards */
    .rsb-card { background: #fff; border: 1px solid rgba(26,26,26,0.1); border-radius: 6px; padding: 22px; display: flex; flex-direction: column; text-decoration: none; transition: box-shadow 0.15s; }
    .rsb-card:hover { box-shadow: 0 4px 16px rgba(26,26,26,0.08); }
    .rsb-card-cat  { font-size: 9px; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; color: #C05621; margin: 0 0 8px; }
    .rsb-card-bar  { width: 32px; height: 2px; background: #C05621; border-radius: 1px; margin: 0 0 12px; }
    .rsb-card-title { font-size: 16px; font-weight: 700; color: #1A1A1A; margin: 0 0 10px; line-height: 1.35; letter-spacing: -0.015em; }
    .rsb-card-exc  { font-size: 14px; color: #4A4A4A; line-height: 1.7; margin: 0 0 16px; flex-grow: 1; }
    .rsb-card-date { font-size: 12px; color: #999; margin: 0; }

    /* Empty state */
    .rsb-empty { grid-column: 1 / -1; text-align: center; padding: 60px 20px; font-size: 16px; color: #888; }
    </style>

    <div class="rsb-blog">
      <div class="rsb-blog-hero">
        <h1>Insights</h1>
        <p>AI strategy, operations, and more — for professional services firms and nonprofits.</p>
      </div>
      <div id="rsb-filter-bar"></div>
      <div id="rsb-card-grid"></div>
    </div>
    <?php
    return ob_get_clean();
}
