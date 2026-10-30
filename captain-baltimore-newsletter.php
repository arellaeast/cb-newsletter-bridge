<?php
/**
 * Plugin Name: Captain Baltimore Newsletter Bridge
 * Description: Exposes a secure REST endpoint so n8n can create and send newsletters through The Newsletter Plugin.
 * Version: 1.1.1
 * Author: Captain Baltimore
 *
 * 1.1.1 (2026-10-25): Fix the subscribers table name in the recipient query.
 * TNP's users table is {prefix}newsletter (constant NEWSLETTER_USERS_TABLE),
 * NOT {prefix}newsletter_users — v1.1.0's query hit a nonexistent table, the
 * delivery engine flagged the issue 'error' with a database error, and
 * nothing was sent (proven by the Lisa-only test issue 320). Use the TNP
 * constant when defined.
 *
 * 1.1.0 (2026-10-25): Fix the send hand-off to match The Newsletter Plugin 9.x
 * native format. v1.0.0 inserted status as integer 1 and send_on as 0, but the
 * TNP delivery engine only picks up rows where status='sending' (string) and
 * send_on <= time(), so Bridge-created issues stayed drafts forever. Also
 * build the recipient query the way TNP's own editor does, and poke the real
 * engine class (NewsletterEngine) — Newsletter::run() does not exist.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// 1.  ADMIN PAGE — lets you see / regenerate the secret key
// ---------------------------------------------------------------------------
add_action( 'admin_menu', function () {
    add_options_page(
        'Newsletter Bridge',
        'Newsletter Bridge',
        'manage_options',
        'cb-newsletter-bridge',
        'cb_bridge_admin_page'
    );
} );

function cb_bridge_admin_page() {
    // Regenerate key on request
    if (
        isset( $_POST['cb_regenerate_key'] ) &&
        check_admin_referer( 'cb_bridge_regenerate' )
    ) {
        update_option( 'cb_bridge_secret_key', wp_generate_password( 48, false ) );
        echo '<div class="updated"><p>Key regenerated.</p></div>';
    }

    $key = get_option( 'cb_bridge_secret_key', '' );
    if ( ! $key ) {
        $key = wp_generate_password( 48, false );
        update_option( 'cb_bridge_secret_key', $key );
    }

    $endpoint = rest_url( 'cb-bridge/v1/send-newsletter' );
    ?>
    <div class="wrap">
        <h1>Captain Baltimore — Newsletter Bridge</h1>
        <p>Use this endpoint and key in your n8n HTTP Request node.</p>

        <table class="widefat" style="max-width:700px">
            <tr>
                <th>Endpoint (POST)</th>
                <td><code><?php echo esc_html( $endpoint ); ?></code></td>
            </tr>
            <tr>
                <th>Secret Key Header</th>
                <td><code>X-CB-Secret</code></td>
            </tr>
            <tr>
                <th>Your Secret Key</th>
                <td><code><?php echo esc_html( $key ); ?></code></td>
            </tr>
        </table>

        <h2>JSON Body Fields</h2>
        <table class="widefat" style="max-width:700px">
            <thead>
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
            </thead>
            <tbody>
                <tr><td><code>subject</code></td><td>string</td><td>✅</td><td>Email subject line</td></tr>
                <tr><td><code>html</code></td><td>string</td><td>✅</td><td>Full HTML body of the newsletter</td></tr>
                <tr><td><code>send</code></td><td>boolean</td><td>❌</td><td>Set <code>true</code> to send immediately (default: true)</td></tr>
                <tr><td><code>list_ids</code></td><td>array</td><td>❌</td><td>List IDs to target, e.g. <code>[1,2]</code>. Omit to send to all confirmed subscribers.</td></tr>
            </tbody>
        </table>

        <h2>Example n8n Prepare Payload Code Node</h2>
        <pre style="background:#f0f0f0;padding:12px;border-radius:4px;max-width:700px;overflow:auto">const html    = $input.first().json.emailHtml;
const subject = $input.first().json.subject;

return [{ json: {
  payload: JSON.stringify({ subject, html, send: true })
} }];</pre>

        <h2>Regenerate Secret Key</h2>
        <form method="post">
            <?php wp_nonce_field( 'cb_bridge_regenerate' ); ?>
            <input type="hidden" name="cb_regenerate_key" value="1" />
            <p><input type="submit" class="button button-secondary" value="Regenerate Key" /></p>
        </form>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// 2.  REST ENDPOINT
// ---------------------------------------------------------------------------
add_action( 'rest_api_init', function () {
    register_rest_route( 'cb-bridge/v1', '/send-newsletter', [
        'methods'             => 'POST',
        'callback'            => 'cb_bridge_handle_request',
        'permission_callback' => 'cb_bridge_check_auth',
    ] );
} );

/**
 * Auth: validate the X-CB-Secret header.
 */
function cb_bridge_check_auth( WP_REST_Request $request ): bool {
    $stored = get_option( 'cb_bridge_secret_key', '' );
    $sent   = $request->get_header( 'X-CB-Secret' );
    return $stored && hash_equals( $stored, (string) $sent );
}

/**
 * Main handler: insert newsletter row then optionally trigger send.
 */
function cb_bridge_handle_request( WP_REST_Request $request ) {
    global $wpdb;

    // -----------------------------------------------------------------------
    // Parse and validate input
    // -----------------------------------------------------------------------
    $body = $request->get_json_params();

    $subject  = isset( $body['subject'] )  ? sanitize_text_field( $body['subject'] ) : '';
    $html     = isset( $body['html'] )     ? $body['html']                           : '';
    $send     = isset( $body['send'] )     ? (bool) $body['send']                    : true;
    $list_ids = isset( $body['list_ids'] ) ? array_map( 'intval', (array) $body['list_ids'] ) : [];

    if ( ! $subject || ! $html ) {
        return new WP_Error(
            'missing_fields',
            'Both "subject" and "html" are required.',
            [ 'status' => 400 ]
        );
    }

    // -----------------------------------------------------------------------
    // Check that The Newsletter Plugin is active
    // -----------------------------------------------------------------------
    if ( ! class_exists( 'Newsletter' ) ) {
        return new WP_Error(
            'newsletter_missing',
            'The Newsletter Plugin is not active.',
            [ 'status' => 500 ]
        );
    }

    // -----------------------------------------------------------------------
    // Build the options blob the Newsletter plugin stores per-newsletter.
    // We replicate the minimal set of keys it uses internally.
    // -----------------------------------------------------------------------
    $options = [
        'composer'    => 'html',   // signals it is a raw-HTML newsletter
        'track_links' => 1,
        'track_opens' => 1,
    ];

    // If specific lists were requested, store them in the options array.
    // TNP 9.x keeps options->lists as an ARRAY of list IDs.
    if ( $list_ids ) {
        $options['lists'] = array_values( $list_ids );
    }

    // -----------------------------------------------------------------------
    // Build the recipient query exactly the way TNP's own editor does
    // (emails/edit.php): all confirmed subscribers, optionally narrowed
    // to the requested lists (list_N = 1 conditions, OR-combined).
    // -----------------------------------------------------------------------
    $users_table = defined( 'NEWSLETTER_USERS_TABLE' ) ? NEWSLETTER_USERS_TABLE : $wpdb->prefix . 'newsletter';
    $query       = "select * from {$users_table} where status='C'";
    if ( $list_ids ) {
        $list_where = [];
        foreach ( $list_ids as $list_id ) {
            $list_where[] = 'list_' . (int) $list_id . '=1';
        }
        $query .= ' and (' . implode( ' or ', $list_where ) . ')';
    }

    // -----------------------------------------------------------------------
    // Insert into wp_newsletter_emails.
    // TNP 9.x status values are STRINGS: 'new' (draft), 'sending', 'sent',
    // 'paused', 'error'. The delivery engine picks up a row only when
    // status='sending' AND send_on <= time().
    // -----------------------------------------------------------------------
    $table  = $wpdb->prefix . 'newsletter_emails';
    $status = $send ? 'sending' : 'new';
    $now    = current_time( 'mysql', true ); // UTC

    $inserted = $wpdb->insert(
        $table,
        [
            'subject'      => $subject,
            'message'      => $html,        // raw HTML column
            'message_text' => '',           // plain-text fallback (Newsletter plugin generates it)
            'status'       => $status,
            'type'         => 'message',    // standard newsletter type
            'send_on'      => $send ? time() : 0,
            'created'      => $now,
            'updated'      => $now,
            'options'      => maybe_serialize( $options ),
            'query'        => $query,
            'total'        => 0,            // Newsletter will recalculate
            'sent'         => 0,
            'open_count'   => 0,
            'click_count'  => 0,
        ],
        [
            '%s', // subject
            '%s', // message
            '%s', // message_text
            '%s', // status
            '%s', // type
            '%d', // send_on
            '%s', // created
            '%s', // updated
            '%s', // options
            '%s', // query
            '%d', // total
            '%d', // sent
            '%d', // open_count
            '%d', // click_count
        ]
    );

    if ( ! $inserted ) {
        return new WP_Error(
            'db_error',
            'Failed to insert newsletter: ' . $wpdb->last_error,
            [ 'status' => 500 ]
        );
    }

    $newsletter_id = $wpdb->insert_id;

    // -----------------------------------------------------------------------
    // If sending, ask the Newsletter plugin's delivery engine to process
    // it now rather than waiting for the next cron tick. The engine lives
    // in the NewsletterEngine class (Newsletter::run() does not exist —
    // v1.0.0's method_exists() guard silently skipped this call).
    // TNP's own cron (every ~5 minutes) is the backstop either way.
    // -----------------------------------------------------------------------
    if ( $send && class_exists( 'NewsletterEngine' ) ) {
        NewsletterEngine::instance()->run();
    }

    // -----------------------------------------------------------------------
    // Return success
    // -----------------------------------------------------------------------
    return rest_ensure_response( [
        'success'       => true,
        'newsletter_id' => $newsletter_id,
        'status'        => $send ? 'sending' : 'draft',
        'subject'       => $subject,
        'message'       => $send
            ? 'Newsletter queued for delivery. The Newsletter Plugin cron will dispatch it within its next cycle.'
            : 'Newsletter saved as draft.',
    ] );
}
