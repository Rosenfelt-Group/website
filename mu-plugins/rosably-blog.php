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
    <?php
    return ob_get_clean();
}
