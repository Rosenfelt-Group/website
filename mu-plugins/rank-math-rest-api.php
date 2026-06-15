<?php
/**
 * Plugin Name: Rank Math REST API Fields
 * Description: Registers rank_math_title, rank_math_description, and rank_math_focus_keyword
 *              for the WP REST API so the Avery agent can write Rank Math SEO meta via REST.
 *              Rank Math does not register these fields itself; without this plugin all REST
 *              writes to those keys are silently discarded.
 */
add_action( 'init', function () {
    $fields = [
        'rank_math_focus_keyword' => 'string',
        'rank_math_title'         => 'string',
        'rank_math_description'   => 'string',
    ];
    foreach ( $fields as $key => $type ) {
        register_post_meta( 'post', $key, [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => $type,
            'auth_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ] );
    }
} );
