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

    $name_esc  = esc_html( $name );
    $tier_esc  = esc_html( $tier );
    $score_esc = (int) $score;

    $prospect_body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#ffffff;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;">
  <tr>
    <td align="center" style="padding:40px 20px;">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;font-family:Arial,sans-serif;font-size:16px;line-height:1.6;color:#1C1C1E;">

        <tr>
          <td style="padding-bottom:24px;">
            <p style="margin:0 0 16px 0;">Hi {$name_esc},</p>
            <p style="margin:0 0 16px 0;">You scored <strong>{$score_esc} out of 25</strong> on the Rosably AI Readiness Assessment. Your tier is <strong>{$tier_esc}</strong>.</p>
          </td>
        </tr>

        <tr>
          <td style="padding-bottom:24px;border-top:1px solid #e5e5e5;padding-top:24px;">
            <p style="margin:0 0 12px 0;font-weight:bold;">What the tiers mean:</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding:10px 0;border-bottom:1px solid #f0f0f0;">
                  <p style="margin:0 0 4px 0;font-weight:bold;">Ready to Move (22&#8211;25)</p>
                  <p style="margin:0;color:#4a4a4a;font-size:15px;">Your organization has strong foundations. You&#8217;re ready to start implementing AI across operations, content, or finance with minimal prep.</p>
                </td>
              </tr>
              <tr>
                <td style="padding:10px 0;border-bottom:1px solid #f0f0f0;">
                  <p style="margin:0 0 4px 0;font-weight:bold;">Ready with Light Prep (16&#8211;21)</p>
                  <p style="margin:0;color:#4a4a4a;font-size:15px;">You&#8217;re close. A few gaps in process documentation or technology infrastructure are worth addressing first &#8212; but you could start with a scoped pilot.</p>
                </td>
              </tr>
              <tr>
                <td style="padding:10px 0;border-bottom:1px solid #f0f0f0;">
                  <p style="margin:0 0 4px 0;font-weight:bold;">Getting There (10&#8211;15)</p>
                  <p style="margin:0;color:#4a4a4a;font-size:15px;">You have real potential but some foundational work to do. Focus on your lowest-scoring areas before committing to a full AI engagement.</p>
                </td>
              </tr>
              <tr>
                <td style="padding:10px 0;">
                  <p style="margin:0 0 4px 0;font-weight:bold;">Foundation First (0&#8211;9)</p>
                  <p style="margin:0;color:#4a4a4a;font-size:15px;">AI is not the right first investment right now. Focus on documenting your processes and stabilizing your technology stack first.</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding-bottom:24px;padding-top:24px;border-top:1px solid #e5e5e5;">
            <p style="margin:0 0 16px 0;">The attached guide goes deeper on each of these areas. Given your score, the sections on {$tier_esc} readiness will be most relevant to where you are right now.</p>
            <p style="margin:0;">If you want to talk through what your results mean for your organization, you can reach us here: <a href="https://rosably.com/contact" style="color:#C05621;text-decoration:none;">rosably.com/contact</a></p>
          </td>
        </tr>

        <tr>
          <td style="padding-top:24px;border-top:1px solid #e5e5e5;">
            <p style="margin:0 0 4px 0;">&#8212; Brian Rosenfelt<br>Rosably</p>
            <p style="margin:16px 0 0 0;font-size:14px;color:#6b6b6b;font-style:italic;">No obligation. If the timing isn&#8217;t right, the guide is still yours to keep.</p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;

    $prospect_email_data = rosably_attach_ebook( [
        'to'          => $email,
        'subject'     => "Your AI Readiness Results: {$score}/25 \xe2\x80\x94 {$tier}",
        'message'     => $prospect_body,
        'headers'     => [
            'Content-Type: text/html; charset=UTF-8',
            'From: Rosably <brian@rosenfeltgroup.com>',
            'Reply-To: brian@rosenfeltgroup.com',
        ],
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
