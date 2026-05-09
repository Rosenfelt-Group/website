<?php
/**
 * Plugin Name: Rosably Assessment
 * Description: Attaches the AI Readiness Guide ebook to WPForms notification emails for the assessment and contact forms.
 */

// WPForms >= 1.8.5 changed wpforms_emails_send_email_data to pass ($email_data, $notifications)
// instead of ($email_data, $fields, $entry, $form_data). accepted_args must be 2.
add_filter( 'wpforms_emails_send_email_data', function( $email_data, $notifications ) {
    $form_data = $notifications->form_data ?? [];
    $fields    = $notifications->fields ?? [];
    $form_id   = (int) ( $form_data['id'] ?? 0 );
    $to        = $email_data['to'] ?? '';

    // Assessment Lead Capture (form 1969) — attach to every prospect email
    if ( $form_id === 1969 ) {
        if ( strpos( $to, 'rosenfeltgroup.com' ) !== false ) {
            return $email_data; // Brian's alert — no attachment
        }
        return rosably_attach_ebook( $email_data );
    }

    return $email_data;
}, 10, 2 );

// REST endpoint for React assessment — bypasses WPForms token requirement
add_action( 'rest_api_init', function() {
    register_rest_route( 'rosably/v1', '/submit-assessment', [
        'methods'             => 'POST',
        'callback'            => 'rosably_handle_assessment_submission',
        'permission_callback' => '__return_true',
    ] );
} );

function rosably_handle_assessment_submission( WP_REST_Request $request ) {
    $name    = sanitize_text_field( $request->get_param( 'name' ) );
    $email   = sanitize_email( $request->get_param( 'email' ) );
    $score   = (int) $request->get_param( 'score' );
    $tier    = sanitize_text_field( $request->get_param( 'tier' ) );
    $summary = sanitize_text_field( $request->get_param( 'section_summary' ) );

    if ( ! $name || ! is_email( $email ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid input.' ], 400 );
    }

    $prospect_body =
        "Hi {$name},\n\n"
        . "Thank you for completing the Rosably AI Readiness Assessment.\n\n"
        . "OVERALL SCORE: {$score} out of 25\n"
        . "READINESS TIER: {$tier}\n\n"
        . "SCORE BY SECTION:\n{$summary}\n\n"
        . "---\n\nWHAT THIS MEANS:\n\n"
        . "[Ready to Move — 22-25] You have the processes, data, team openness, and budget to start generating real value from AI now.\n\n"
        . "[Ready with Light Prep — 16-21] A few gaps, but nothing that blocks progress. The right partner will help you address them as part of onboarding.\n\n"
        . "[Getting There — 10-15] Focus on your lowest-scoring sections. Document your top 3 workflows, move your data into a cloud system, and establish a content calendar. Re-evaluate in 60 days.\n\n"
        . "[Foundation First — under 10] AI amplifies what you have — so if the foundation is missing, start there. That is not a no. It is a path.\n\n"
        . "---\n\nThe full guide is attached to this email.\n\n"
        . "If you would like to talk through your results: rosably.com/contact\n\n"
        . "— The Rosably Team\nrosably.com";

    $prospect_email_data = rosably_attach_ebook( [
        'to'          => $email,
        'subject'     => "Your AI Readiness Results — {$tier}",
        'message'     => $prospect_body,
        'headers'     => [ 'Content-Type: text/plain; charset=UTF-8' ],
        'attachments' => [],
    ] );

    $sent = wp_mail(
        $prospect_email_data['to'],
        $prospect_email_data['subject'],
        $prospect_email_data['message'],
        $prospect_email_data['headers'],
        $prospect_email_data['attachments']
    );

    wp_mail(
        'brian@rosenfeltgroup.com',
        "New Assessment Lead — {$tier} ({$score}/25)",
        "New assessment submission:\n\nName:     {$name}\nEmail:    {$email}\nScore:    {$score} / 25\nTier:     {$tier}\nSections: {$summary}"
    );

    if ( ! $sent ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Email delivery failed.' ], 500 );
    }

    return new WP_REST_Response( [ 'success' => true ], 200 );
}

function rosably_attach_ebook( $email_data ) {
    $args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'meta_query'     => [[
            'key'     => '_wp_attached_file',
            'value'   => 'Rosably-AI-Readiness-Guide.pdf',
            'compare' => 'LIKE',
        ]],
    ];
    $attachments = get_posts( $args );

    if ( ! empty( $attachments ) ) {
        $file_path = get_attached_file( $attachments[0]->ID );
        if ( $file_path && file_exists( $file_path ) ) {
            $email_data['attachments'] = [ $file_path ];
        }
    }

    return $email_data;
}
