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

    // Contact Us (form 1860) — attach only when guide checkbox (field 7) is checked
    if ( $form_id === 1860 ) {
        if ( strpos( $to, 'rosenfeltgroup.com' ) !== false ) {
            return $email_data; // Brian's alert — no attachment
        }
        $checked = isset( $fields[7]['value'] ) && ! empty( $fields[7]['value'] );
        if ( ! $checked ) {
            return $email_data;
        }
        return rosably_attach_ebook( $email_data );
    }

    return $email_data;
}, 10, 2 );

function rosably_attach_ebook( $email_data ) {
    $args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'meta_query'     => [[
            'key'     => '_wp_attached_file',
            'value'   => 'Rosably-AI-Readiness-Guide',
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
