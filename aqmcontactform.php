<?php
/**
 * Plugin Name:       A. Q. Mufti – Contact Form
 * Plugin URI:        https://github.com/AQMufti/aqm-contact-form
 * Description:       Contact form with email notification, database storage, admin-managed event types, spam protection and CSV export.
 * Version:           2.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            A. Q. Mufti
 * Author URI:        https://github.com/AQMufti
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aqm-contact-form
 * Update URI:        https://github.com/AQMufti/aqm-contact-form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AQM_CF_VERSION', '2.3.0' );
define( 'AQM_CF_DB_VERSION', 3 );
define( 'AQM_CF_FILE', __FILE__ );

// GitHub repository this plugin updates itself from, as "owner/repo".
if ( ! defined( 'AQM_CF_GITHUB_REPO' ) ) {
	define( 'AQM_CF_GITHUB_REPO', 'AQMufti/aqm-contact-form' );
}

/* ═══════════════════════════════════════════════════════════════════════
   1. SCHEMA — install, versioned upgrades, seed data
   ═══════════════════════════════════════════════════════════════════════ */

register_activation_hook( __FILE__, 'aqm_cf_install' );

function aqm_cf_entries_table() {
	global $wpdb;
	return $wpdb->prefix . 'aqm_contact_entries';
}

function aqm_cf_event_types_table() {
	global $wpdb;
	return $wpdb->prefix . 'aqm_event_types';
}

/**
 * Create/upgrade tables. Safe to run repeatedly — dbDelta diffs the schema.
 */
function aqm_cf_install() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$entries = aqm_cf_entries_table();
	$types   = aqm_cf_event_types_table();

	// NOTE: dbDelta is picky. No "IF NOT EXISTS", one field per line,
	// lowercase types, and TWO spaces after "PRIMARY KEY".
	$sql_entries = "CREATE TABLE $entries (
		id bigint(20) unsigned NOT NULL auto_increment,
		name varchar(120) NOT NULL,
		email varchar(120) NOT NULL,
		phone varchar(30) NOT NULL default '',
		event_type_id int(10) unsigned NOT NULL default 0,
		event_type varchar(120) NOT NULL,
		message text NOT NULL,
		consent tinyint(1) NOT NULL default 0,
		ip_address varchar(45) NOT NULL default '',
		user_agent varchar(255) NOT NULL default '',
		submitted_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY submitted_at (submitted_at),
		KEY email (email),
		KEY event_type_id (event_type_id)
	) $charset;";

	$sql_types = "CREATE TABLE $types (
		id int(10) unsigned NOT NULL auto_increment,
		label varchar(120) NOT NULL,
		sort_order int(11) NOT NULL default 0,
		PRIMARY KEY  (id),
		KEY sort_order (sort_order)
	) $charset;";

	dbDelta( $sql_entries );
	dbDelta( $sql_types );

	// Seed default event types only on a genuinely empty table. add_option()
	// relies on the unique index on option_name, so if two requests race
	// through this upgrade at once only one of them wins and seeds.
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $types" ); // phpcs:ignore WordPress.DB.PreparedSQL
	if ( 0 === $count && add_option( 'aqm_cf_seeded', 1, '', 'no' ) ) {
		// Neutral starting list, ordered roughly by how often each is picked.
		// Every one of these can be renamed, reordered or deleted under
		// AQM Contact → Event Types.
		$defaults = array(
			'General Enquiry',
			'Event Booking',
			'Request a Quote',
			'Wedding or Private Celebration',
			'Corporate or Business Event',
			'Conference or Seminar',
			'Workshop or Training',
			'Fundraiser or Charity Event',
			'Speaker or Presentation Request',
			'Media or Press Enquiry',
			'Partnership or Sponsorship',
			'Other',
		);
		foreach ( $defaults as $i => $label ) {
			$wpdb->insert(
				$types,
				array(
					'label'      => $label,
					'sort_order' => $i + 1,
				),
				array( '%s', '%d' )
			);
		}
	}

	// Backfill event_type_id for rows migrated from v2.0.
	$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		"UPDATE $entries e
		 JOIN $types t ON t.label = e.event_type
		 SET e.event_type_id = t.id
		 WHERE e.event_type_id = 0"
	);

	// Rows created before submitted_at was always supplied.
	$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		"UPDATE $entries SET submitted_at = '1970-01-01 00:00:00' WHERE submitted_at IS NULL"
	);

	update_option( 'aqm_cf_db_version', AQM_CF_DB_VERSION );
	aqm_cf_flush_event_type_cache();
}

/**
 * Activation only fires on activation — this catches plugin updates too.
 */
add_action( 'plugins_loaded', 'aqm_cf_maybe_upgrade' );

function aqm_cf_maybe_upgrade() {
	if ( (int) get_option( 'aqm_cf_db_version', 0 ) < AQM_CF_DB_VERSION ) {
		aqm_cf_install();
	}
}

add_action( 'init', 'aqm_cf_load_textdomain' );

function aqm_cf_load_textdomain() {
	load_plugin_textdomain( 'aqm-contact-form', false, dirname( plugin_basename( AQM_CF_FILE ) ) . '/languages' );
}

/* ═══════════════════════════════════════════════════════════════════════
   2. SETTINGS
   ═══════════════════════════════════════════════════════════════════════ */

function aqm_cf_default_settings() {
	return array(
		// Defaults to the site's own admin address. The real recipient is set
		// on the settings screen, so it lives in the database rather than in
		// this file — which matters if the source is ever published.
		'notify_email'    => (string) get_option( 'admin_email' ),
		'notify_cc'       => '',
		'autoreply'       => 1,
		'autoreply_subject' => 'We received your message',
		'autoreply_body'  => "Dear {name},\n\nThank you for getting in touch. We have received your message regarding \"{event_type}\" and will be in touch shortly.\n\nFor your records, here is what you sent:\n\n{message}\n\nWarm regards,\n{site_name}",
		'success_message' => 'Thank you, {name}! Your message has been received. We will be in touch soon.',
		'field_label'     => 'Type of Event',
		'consent_enabled' => 0,
		'consent_text'    => 'I consent to my details being stored so that my enquiry can be answered.',
		'spam_protection' => 1,
		'rate_limit'      => 5,
		'max_links'       => 4,
		'proxy_header'    => '',
		'store_ip'        => 1,
		'anonymise_ip'    => 0,
		'delete_on_uninstall' => 0,
	);
}

function aqm_cf_get_settings() {
	static $cached = null;
	if ( null === $cached ) {
		$cached = wp_parse_args( (array) get_option( 'aqm_cf_settings', array() ), aqm_cf_default_settings() );
	}
	return $cached;
}

function aqm_cf_setting( $key ) {
	$settings = aqm_cf_get_settings();
	return $settings[ $key ] ?? null;
}

/**
 * What the dropdown is called on the form and in the admin tables. "Type of
 * Event" by default, but a photographer might want "Session Type" and a
 * charity "Reason for Contact".
 */
function aqm_cf_field_label() {
	$label = trim( (string) aqm_cf_setting( 'field_label' ) );
	return '' === $label ? __( 'Type of Event', 'aqm-contact-form' ) : $label;
}

add_action( 'admin_init', 'aqm_cf_register_settings' );

function aqm_cf_register_settings() {
	register_setting(
		'aqm_cf_settings_group',
		'aqm_cf_settings',
		array( 'sanitize_callback' => 'aqm_cf_sanitize_settings' )
	);
}

function aqm_cf_sanitize_settings( $input ) {
	$defaults = aqm_cf_default_settings();
	$input    = (array) $input;
	$out      = array();

	$email = sanitize_email( $input['notify_email'] ?? '' );
	if ( ! is_email( $email ) ) {
		$email = get_option( 'admin_email' );
		add_settings_error( 'aqm_cf_settings', 'notify_email', __( 'The notification email was not valid — falling back to the site admin address.', 'aqm-contact-form' ) );
	}
	$out['notify_email'] = $email;

	// CC: comma-separated list, invalid entries dropped.
	$cc = array();
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $input['notify_cc'] ?? '' ) ) ) ) as $candidate ) {
		$candidate = sanitize_email( $candidate );
		if ( is_email( $candidate ) ) {
			$cc[] = $candidate;
		}
	}
	$out['notify_cc'] = implode( ', ', $cc );

	$out['autoreply']         = empty( $input['autoreply'] ) ? 0 : 1;
	$out['autoreply_subject'] = sanitize_text_field( $input['autoreply_subject'] ?? $defaults['autoreply_subject'] );
	$out['autoreply_body']    = sanitize_textarea_field( $input['autoreply_body'] ?? $defaults['autoreply_body'] );
	$out['success_message']   = sanitize_text_field( $input['success_message'] ?? $defaults['success_message'] );

	$label               = sanitize_text_field( $input['field_label'] ?? '' );
	$out['field_label']  = '' === $label ? $defaults['field_label'] : $label;

	$out['consent_enabled'] = empty( $input['consent_enabled'] ) ? 0 : 1;
	$out['consent_text']    = sanitize_text_field( $input['consent_text'] ?? $defaults['consent_text'] );

	$out['spam_protection'] = empty( $input['spam_protection'] ) ? 0 : 1;
	$out['rate_limit']      = max( 0, min( 100, (int) ( $input['rate_limit'] ?? $defaults['rate_limit'] ) ) );
	$out['max_links']       = max( 0, min( 50, (int) ( $input['max_links'] ?? $defaults['max_links'] ) ) );

	$proxy                  = (string) ( $input['proxy_header'] ?? '' );
	$out['proxy_header']    = array_key_exists( $proxy, aqm_cf_proxy_headers() ) ? $proxy : '';

	$out['store_ip']            = empty( $input['store_ip'] ) ? 0 : 1;
	$out['anonymise_ip']        = empty( $input['anonymise_ip'] ) ? 0 : 1;
	$out['delete_on_uninstall'] = empty( $input['delete_on_uninstall'] ) ? 0 : 1;

	return $out;
}

/* ═══════════════════════════════════════════════════════════════════════
   3. HELPERS
   ═══════════════════════════════════════════════════════════════════════ */

function aqm_cf_flush_event_type_cache() {
	wp_cache_delete( 'aqm_cf_event_types', 'aqm_cf' );
}

/**
 * @return array Objects with id, label, sort_order.
 */
function aqm_cf_get_event_types() {
	$cached = wp_cache_get( 'aqm_cf_event_types', 'aqm_cf' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;
	$table = aqm_cf_event_types_table();
	$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		"SELECT id, label, sort_order FROM $table ORDER BY sort_order ASC, label ASC"
	);
	$rows = is_array( $rows ) ? $rows : array();

	wp_cache_set( 'aqm_cf_event_types', $rows, 'aqm_cf', HOUR_IN_SECONDS );
	return $rows;
}

function aqm_cf_get_event_type( $id ) {
	foreach ( aqm_cf_get_event_types() as $type ) {
		if ( (int) $type->id === (int) $id ) {
			return $type;
		}
	}
	return null;
}

/**
 * The proxy headers an administrator may choose to trust.
 *
 * @return array header key => human label
 */
function aqm_cf_proxy_headers() {
	return array(
		''                       => __( 'None — the server sees visitors directly', 'aqm-contact-form' ),
		'HTTP_CF_CONNECTING_IP'  => __( 'Cloudflare', 'aqm-contact-form' ),
		'HTTP_X_FORWARDED_FOR'   => __( 'Standard proxy / load balancer (X-Forwarded-For)', 'aqm-contact-form' ),
		'HTTP_X_REAL_IP'         => __( 'Nginx proxy (X-Real-IP)', 'aqm-contact-form' ),
		'HTTP_TRUE_CLIENT_IP'    => __( 'Akamai / Cloudflare Enterprise (True-Client-IP)', 'aqm-contact-form' ),
	);
}

/**
 * Client IP. Proxies are NOT trusted unless the administrator selects one on
 * the settings screen — otherwise anyone could spoof X-Forwarded-For and walk
 * straight past the rate limiter.
 */
function aqm_cf_get_client_ip() {
	$ip     = $_SERVER['REMOTE_ADDR'] ?? '';
	$header = (string) aqm_cf_setting( 'proxy_header' );
	$header = apply_filters( 'aqm_cf_trusted_proxy_header', $header );

	if ( $header && ! empty( $_SERVER[ $header ] ) ) {
		$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
		$ip    = trim( $parts[0] );
	}

	$ip = filter_var( $ip, FILTER_VALIDATE_IP );
	return $ip ? $ip : '';
}

function aqm_cf_anonymise_ip( $ip ) {
	if ( ! $ip ) {
		return '';
	}
	if ( false !== strpos( $ip, ':' ) ) {
		$parts = explode( ':', $ip );
		return implode( ':', array_slice( $parts, 0, 4 ) ) . '::';
	}
	$parts = explode( '.', $ip );
	if ( 4 === count( $parts ) ) {
		$parts[3] = '0';
		return implode( '.', $parts );
	}
	return $ip;
}

/**
 * Signed timestamp so the time-trap cannot be forged or replayed forever.
 */
function aqm_cf_timestamp_token() {
	$now = time();
	return $now . '.' . wp_hash( 'aqm_cf_ts_' . $now );
}

function aqm_cf_timestamp_age( $token ) {
	$parts = explode( '.', (string) $token, 2 );
	if ( 2 !== count( $parts ) ) {
		return false;
	}
	$time = (int) $parts[0];
	if ( ! hash_equals( wp_hash( 'aqm_cf_ts_' . $time ), $parts[1] ) ) {
		return false;
	}
	return time() - $time;
}

/**
 * @return string Empty when the visitor's IP is unknown — in that case we do
 *                not rate limit at all, rather than dropping every anonymous
 *                visitor into one shared bucket and throttling them together.
 */
function aqm_cf_rate_limit_key() {
	$ip = aqm_cf_get_client_ip();
	return $ip ? 'aqm_cf_rl_' . md5( $ip ) : '';
}

function aqm_cf_rate_limit_exceeded() {
	$limit = (int) aqm_cf_setting( 'rate_limit' );
	$key   = aqm_cf_rate_limit_key();
	if ( $limit < 1 || '' === $key ) {
		return false;
	}
	return (int) get_transient( $key ) >= $limit;
}

function aqm_cf_record_submission() {
	$key = aqm_cf_rate_limit_key();
	if ( '' === $key ) {
		return;
	}
	set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
}

function aqm_cf_count_links( $text ) {
	return preg_match_all( '#\b(?:https?://|www\.)\S+#i', $text );
}

/**
 * Replace {placeholders} in message templates.
 */
function aqm_cf_render_template( $template, array $vars ) {
	$keys = array_map(
		static function ( $key ) {
			return '{' . $key . '}';
		},
		array_keys( $vars )
	);
	return str_replace( $keys, array_values( $vars ), $template );
}

/* ═══════════════════════════════════════════════════════════════════════
   4. FRONT-END SUBMISSION — handled on template_redirect so we can
      Post/Redirect/Get (no duplicate entries on browser refresh).
   ═══════════════════════════════════════════════════════════════════════ */

add_action( 'template_redirect', 'aqm_cf_handle_submission' );

function aqm_cf_handle_submission() {
	if ( empty( $_POST['aqm_cf_submit'] ) ) {
		return;
	}

	$values = array(
		'name'       => isset( $_POST['aqm_name'] ) ? sanitize_text_field( wp_unslash( $_POST['aqm_name'] ) ) : '',
		'email'      => isset( $_POST['aqm_email'] ) ? sanitize_email( wp_unslash( $_POST['aqm_email'] ) ) : '',
		'phone'      => isset( $_POST['aqm_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['aqm_phone'] ) ) : '',
		'event_type' => isset( $_POST['aqm_event_type'] ) ? (int) $_POST['aqm_event_type'] : 0,
		'message'    => isset( $_POST['aqm_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['aqm_message'] ) ) : '',
		'consent'    => empty( $_POST['aqm_consent'] ) ? 0 : 1,
	);

	$errors  = array();
	$general = '';

	/* ---- Nonce. A cached page can serve an expired nonce, so fail kindly. ---- */
	$nonce = isset( $_POST['aqm_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['aqm_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'aqm_contact_submit' ) ) {
		aqm_cf_flash_and_redirect(
			array(
				'status'  => 'error',
				'general' => __( 'Your session expired before the form was sent. Your details are still below — please press “Send Message” once more.', 'aqm-contact-form' ),
				'values'  => $values,
			)
		);
	}

	/* ---- Spam traps. ---- */
	if ( aqm_cf_setting( 'spam_protection' ) ) {
		$honeypot = isset( $_POST['aqm_website'] ) ? trim( (string) wp_unslash( $_POST['aqm_website'] ) ) : '';
		$age      = aqm_cf_timestamp_age( isset( $_POST['aqm_ts'] ) ? wp_unslash( $_POST['aqm_ts'] ) : '' );

		// Only these two are certain enough to discard silently: a filled
		// honeypot, or a missing/forged timestamp, neither of which a real
		// browser produces. Bots are shown "success" so they do not adapt.
		if ( '' !== $honeypot || false === $age || $age < 3 ) {
			aqm_cf_flash_and_redirect(
				array(
					'status'  => 'success',
					'general' => aqm_cf_success_text( $values['name'] ),
				)
			);
		}

		// A stale timestamp means a page that sat open (or was served from a
		// cache), NOT a bot. Discarding it silently would lose real enquiries,
		// so ask the visitor to send again instead.
		if ( $age > DAY_IN_SECONDS ) {
			aqm_cf_flash_and_redirect(
				array(
					'status'  => 'error',
					'general' => __( 'This page had been open for a while. Your details are still below — please press “Send Message” once more.', 'aqm-contact-form' ),
					'values'  => $values,
				)
			);
		}

		// Too many links is a strong spam signal but a plausible mistake, so
		// it is a visible validation error rather than a silent discard.
		$max_links = (int) aqm_cf_setting( 'max_links' );
		if ( $max_links > 0 && aqm_cf_count_links( $values['message'] ) > $max_links ) {
			aqm_cf_flash_and_redirect(
				array(
					'status'  => 'error',
					'general' => __( 'Please correct the highlighted fields and send the form again.', 'aqm-contact-form' ),
					'errors'  => array(
						'message' => sprintf(
							/* translators: %d: maximum number of links */
							__( 'Please include no more than %d web links. If you need to send more, write to us directly instead.', 'aqm-contact-form' ),
							$max_links
						),
					),
					'values'  => $values,
				)
			);
		}
	}

	if ( aqm_cf_rate_limit_exceeded() ) {
		aqm_cf_flash_and_redirect(
			array(
				'status'  => 'error',
				'general' => __( 'You have sent several messages already. Please wait an hour before sending another, or telephone us directly.', 'aqm-contact-form' ),
				'values'  => $values,
			)
		);
	}

	/* ---- Validation, field by field. ---- */
	if ( '' === $values['name'] ) {
		$errors['name'] = __( 'Please tell us your name.', 'aqm-contact-form' );
	} elseif ( mb_strlen( $values['name'] ) > 120 ) {
		$errors['name'] = __( 'Please keep your name under 120 characters.', 'aqm-contact-form' );
	}

	if ( '' === $values['email'] ) {
		$errors['email'] = __( 'Please enter your email address.', 'aqm-contact-form' );
	} elseif ( ! is_email( $values['email'] ) ) {
		$errors['email'] = __( 'That email address does not look right — please check it.', 'aqm-contact-form' );
	} elseif ( mb_strlen( $values['email'] ) > 120 ) {
		$errors['email'] = __( 'Please use an email address under 120 characters.', 'aqm-contact-form' );
	}

	if ( '' !== $values['phone'] && ! preg_match( '/^[0-9+()\.\-\s]{6,30}$/', $values['phone'] ) ) {
		$errors['phone'] = __( 'Please enter a telephone number using digits, spaces and ( ) + - only.', 'aqm-contact-form' );
	}

	$event = aqm_cf_get_event_type( $values['event_type'] );
	if ( ! $event ) {
		$errors['event_type'] = __( 'Please choose an option from the list.', 'aqm-contact-form' );
	}

	if ( '' === $values['message'] ) {
		$errors['message'] = __( 'Please write a short message so we know how to help.', 'aqm-contact-form' );
	} elseif ( mb_strlen( $values['message'] ) > 5000 ) {
		$errors['message'] = __( 'Your message is longer than 5,000 characters. Please shorten it a little.', 'aqm-contact-form' );
	}

	if ( aqm_cf_setting( 'consent_enabled' ) && ! $values['consent'] ) {
		$errors['consent'] = __( 'Please tick the box to confirm we may store your details.', 'aqm-contact-form' );
	}

	if ( $errors ) {
		aqm_cf_flash_and_redirect(
			array(
				'status'  => 'error',
				'general' => __( 'Please correct the highlighted fields and send the form again.', 'aqm-contact-form' ),
				'errors'  => $errors,
				'values'  => $values,
			)
		);
	}

	/* ---- Store. ---- */
	global $wpdb;

	$ip = '';
	if ( aqm_cf_setting( 'store_ip' ) ) {
		$ip = aqm_cf_get_client_ip();
		if ( aqm_cf_setting( 'anonymise_ip' ) ) {
			$ip = aqm_cf_anonymise_ip( $ip );
		}
	}

	$inserted = $wpdb->insert(
		aqm_cf_entries_table(),
		array(
			'name'          => $values['name'],
			'email'         => $values['email'],
			'phone'         => $values['phone'],
			'event_type_id' => (int) $event->id,
			'event_type'    => $event->label,
			'message'       => $values['message'],
			'consent'       => $values['consent'],
			'ip_address'    => $ip,
			'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
			'submitted_at'  => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		error_log( 'AQM Contact Form: failed to store entry — ' . $wpdb->last_error );
		aqm_cf_flash_and_redirect(
			array(
				'status'  => 'error',
				'general' => __( 'Something went wrong while saving your message. Please try again, or email us directly.', 'aqm-contact-form' ),
				'values'  => $values,
			)
		);
	}

	$entry_id = (int) $wpdb->insert_id;
	aqm_cf_record_submission();

	/* ---- Notify. ---- */
	aqm_cf_send_notification( $entry_id, $values, $event->label );

	if ( aqm_cf_setting( 'autoreply' ) ) {
		aqm_cf_send_autoreply( $values, $event->label );
	}

	do_action( 'aqm_cf_entry_saved', $entry_id, $values, $event );

	aqm_cf_flash_and_redirect(
		array(
			'status'  => 'success',
			'general' => aqm_cf_success_text( $values['name'] ),
		)
	);
}

function aqm_cf_success_text( $name ) {
	return aqm_cf_render_template(
		(string) aqm_cf_setting( 'success_message' ),
		array( 'name' => $name )
	);
}

/**
 * Current front-end URL. Note that wp_get_referer() cannot be used here: it
 * deliberately returns false when the referer matches the request URI, which
 * is exactly the case for a form that posts back to its own page.
 */
function aqm_cf_current_url() {
	$host = isset( $_SERVER['HTTP_HOST'] )
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
		: (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

	// Never trust a Host header we do not recognise. Compare with the port and
	// any "www." prefix removed so alias/www variants still redirect correctly.
	$normalise = static function ( $value ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/:\d+$/', '', $value );
		return preg_replace( '/^www\./', '', (string) $value );
	};

	$known = array_map(
		$normalise,
		array(
			wp_parse_url( home_url(), PHP_URL_HOST ),
			wp_parse_url( site_url(), PHP_URL_HOST ),
		)
	);

	if ( ! in_array( $normalise( $host ), $known, true ) ) {
		return home_url( '/' );
	}

	return set_url_scheme( 'http://' . $host . $uri );
}

/**
 * Store the outcome in a short-lived transient and redirect (PRG).
 * Never returns.
 */
function aqm_cf_flash_and_redirect( array $payload ) {
	// Lowercase hex only: the token is read back through sanitize_key(), which
	// lowercases, so a mixed-case token would never match its transient.
	$token = bin2hex( wp_generate_password( 10, false, false ) );
	set_transient( 'aqm_cf_flash_' . $token, $payload, 5 * MINUTE_IN_SECONDS );

	$redirect = remove_query_arg( 'aqm_cf', aqm_cf_current_url() );
	$redirect = add_query_arg( 'aqm_cf', $token, $redirect );

	wp_safe_redirect( $redirect . '#aqm-contact-form' );
	exit;
}

function aqm_cf_get_flash() {
	static $flash = null;
	if ( null !== $flash ) {
		return $flash;
	}

	$flash = array();
	if ( ! empty( $_GET['aqm_cf'] ) ) {
		$token   = sanitize_key( wp_unslash( $_GET['aqm_cf'] ) );
		$payload = get_transient( 'aqm_cf_flash_' . $token );
		if ( is_array( $payload ) ) {
			$flash = $payload;
			delete_transient( 'aqm_cf_flash_' . $token );
		}
	}
	return $flash;
}

/* ═══════════════════════════════════════════════════════════════════════
   5. EMAIL
   ═══════════════════════════════════════════════════════════════════════ */

function aqm_cf_mail_from_address() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$host = preg_replace( '/^www\./i', '', (string) $host );
	return apply_filters( 'aqm_cf_from_address', 'no-reply@' . $host );
}

function aqm_cf_send_notification( $entry_id, array $values, $event_label ) {
	$to = aqm_cf_setting( 'notify_email' );
	if ( ! is_email( $to ) ) {
		return;
	}

	// sanitize_text_field already strips newlines, so header injection via the
	// name is not possible; angle brackets are removed so Reply-To stays valid.
	$safe_name = trim( str_replace( array( '<', '>', '"' ), '', $values['name'] ) );

	$headers = array(
		'Content-Type: text/plain; charset=UTF-8',
		'From: ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' <' . aqm_cf_mail_from_address() . '>',
		'Reply-To: ' . $safe_name . ' <' . $values['email'] . '>',
	);

	$cc = trim( (string) aqm_cf_setting( 'notify_cc' ) );
	if ( $cc ) {
		$headers[] = 'Cc: ' . $cc;
	}

	$body = sprintf(
		"New submission received.\n\nName: %s\nEmail: %s\nPhone: %s\nEvent Type: %s\n\nMessage:\n%s\n\nSubmitted: %s\nView in dashboard: %s\n",
		$values['name'],
		$values['email'],
		$values['phone'] ? $values['phone'] : __( 'Not provided', 'aqm-contact-form' ),
		$event_label,
		$values['message'],
		current_time( 'mysql' ),
		admin_url( 'admin.php?page=aqm-contact-entries&action=view&id=' . $entry_id )
	);

	$sent = wp_mail(
		$to,
		sprintf( /* translators: %s: event type */ __( 'New Contact Form Submission – %s', 'aqm-contact-form' ), $event_label ),
		$body,
		$headers
	);

	// The entry is safely in the database either way, but a silently failing
	// mailer is the single most common way enquiries get missed.
	if ( ! $sent ) {
		error_log( 'AQM Contact Form: wp_mail() failed for entry #' . $entry_id );
		set_transient( 'aqm_cf_mail_failure', (int) $entry_id, WEEK_IN_SECONDS );
	}
}

function aqm_cf_send_autoreply( array $values, $event_label ) {
	$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

	$vars = array(
		'name'       => $values['name'],
		'email'      => $values['email'],
		'event_type' => $event_label,
		'message'    => $values['message'],
		'site_name'  => $site_name,
	);

	$headers = array(
		'Content-Type: text/plain; charset=UTF-8',
		'From: ' . $site_name . ' <' . aqm_cf_mail_from_address() . '>',
	);

	$reply_to = aqm_cf_setting( 'notify_email' );
	if ( is_email( $reply_to ) ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}

	wp_mail(
		$values['email'],
		aqm_cf_render_template( (string) aqm_cf_setting( 'autoreply_subject' ), $vars ),
		aqm_cf_render_template( (string) aqm_cf_setting( 'autoreply_body' ), $vars ),
		$headers
	);
}

/* ═══════════════════════════════════════════════════════════════════════
   6. ASSETS
   ═══════════════════════════════════════════════════════════════════════ */

add_action( 'wp_enqueue_scripts', 'aqm_cf_register_assets' );

function aqm_cf_register_assets() {
	wp_register_style( 'aqm-contact-form', false, array(), AQM_CF_VERSION );
	wp_add_inline_style( 'aqm-contact-form', aqm_cf_styles() );

	// Enqueue in <head> when the shortcode is in the main content. Widgets,
	// footers and archive pages are covered by the fallback in the shortcode.
	$post = get_post();
	if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'aqm_contact_form' ) ) {
		wp_enqueue_style( 'aqm-contact-form' );
		aqm_cf_mark_styles_done();

		// Headers have not been sent yet at this point, so this is the useful
		// place to tell caching layers not to store the page.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}
}

/**
 * Tracks whether the form CSS has already been output, so it is printed
 * exactly once however the shortcode reaches the page.
 *
 * @param bool $set Pass true to mark it done.
 */
function aqm_cf_mark_styles_done( $set = true ) {
	static $done = false;
	if ( $set ) {
		$done = true;
	}
	return $done;
}

/**
 * Print the CSS inline when we have missed the <head>.
 *
 * v2.0 printed a <style> block inside the shortcode output, which always
 * worked. Enqueuing alone would silently lose all styling when the shortcode
 * sits in a widget, a footer or an archive template, because those render
 * after wp_head() has already printed the stylesheet queue.
 */
function aqm_cf_style_fallback() {
	if ( aqm_cf_mark_styles_done( false ) ) {
		return '';
	}
	aqm_cf_mark_styles_done();
	return '<style id="aqm-contact-form-fallback-css">' . aqm_cf_styles() . '</style>';
}

function aqm_cf_styles() {
	return <<<CSS
.aqm-form-wrap{--aqm-primary:#1a4b6e;--aqm-accent:#c8923a;--aqm-border:#d0c9bd;--aqm-text:#2c2c2c;--aqm-error:#c0392b;--aqm-radius:6px;--aqm-shadow:0 2px 8px rgba(0,0,0,.08);max-width:780px;margin:0 auto;font-family:Georgia,'Times New Roman',serif;color:var(--aqm-text)}
.aqm-alert{display:flex;align-items:flex-start;gap:12px;padding:16px 20px;border-radius:var(--aqm-radius);margin-bottom:24px;font-size:15px;line-height:1.5}
.aqm-alert--success{background:#eaf5ec;border-left:4px solid #3a8a4a;color:#1e5c29}
.aqm-alert--error{background:#fdf0f0;border-left:4px solid var(--aqm-error);color:#7b1c1c}
.aqm-alert__icon{font-weight:bold;font-size:18px;flex-shrink:0;margin-top:1px}
.aqm-form__row{display:flex;gap:20px}
.aqm-form__row--half>*{flex:1 1 0;min-width:0}
@media(max-width:600px){.aqm-form__row{flex-direction:column;gap:0}}
.aqm-form__group{margin-bottom:20px}
.aqm-form__group label{display:block;font-size:14px;font-weight:600;letter-spacing:.4px;margin-bottom:6px;color:var(--aqm-primary);text-transform:uppercase;font-family:Arial,sans-serif}
.aqm-optional{font-weight:400;text-transform:none;color:#767676;font-size:12px;letter-spacing:0}
.aqm-required{color:#9a6c1f}
.aqm-form__group input,.aqm-form__group select,.aqm-form__group textarea{width:100%;box-sizing:border-box;padding:11px 14px;border:1px solid var(--aqm-border);border-radius:var(--aqm-radius);background:#fff;font-size:15px;font-family:Georgia,serif;color:var(--aqm-text);transition:border-color .2s,box-shadow .2s;box-shadow:var(--aqm-shadow)}
.aqm-form__group input:focus,.aqm-form__group select:focus,.aqm-form__group textarea:focus{outline:2px solid transparent;border-color:var(--aqm-primary);box-shadow:0 0 0 3px rgba(26,75,110,.35)}
.aqm-form__group select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%231a4b6e' d='M6 8L0 0h12z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:36px}
.aqm-form__group textarea{resize:vertical;min-height:130px}
.aqm-form__group--invalid input,.aqm-form__group--invalid select,.aqm-form__group--invalid textarea{border-color:var(--aqm-error);box-shadow:0 0 0 3px rgba(192,57,43,.12)}
.aqm-field-error{display:block;margin-top:6px;font-size:13.5px;color:#8c2f22;font-family:Arial,sans-serif}
.aqm-form__consent{display:flex;gap:10px;align-items:flex-start;margin-bottom:20px;font-size:14.5px;line-height:1.5}
.aqm-form__consent input{width:auto;flex:0 0 auto;margin-top:3px;box-shadow:none}
.aqm-form__consent label{text-transform:none;letter-spacing:0;font-weight:400;font-family:Georgia,serif;font-size:14.5px;color:var(--aqm-text);margin:0}
.aqm-hp{position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important;overflow:hidden!important}
.aqm-form__footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:8px}
.aqm-form__note{font-size:13px;color:#6b6b6b;margin:0;font-family:Arial,sans-serif}
.aqm-btn{display:inline-block;padding:13px 36px;background:var(--aqm-primary);color:#fff;border:none;border-radius:var(--aqm-radius);font-size:15px;font-family:Arial,sans-serif;font-weight:600;letter-spacing:.5px;cursor:pointer;transition:background .2s,transform .1s;box-shadow:0 3px 10px rgba(26,75,110,.25)}
.aqm-btn:hover{background:#163d5a}
.aqm-btn:active{transform:translateY(1px)}
.aqm-btn:focus-visible{outline:3px solid #c8923a;outline-offset:2px}
@media(prefers-reduced-motion:reduce){.aqm-form__group input,.aqm-form__group select,.aqm-form__group textarea,.aqm-btn{transition:none}}
CSS;
}

/* ═══════════════════════════════════════════════════════════════════════
   7. SHORTCODE  [aqm_contact_form]
   ═══════════════════════════════════════════════════════════════════════ */

add_shortcode( 'aqm_contact_form', 'aqm_cf_render_form' );

function aqm_cf_render_form() {
	// The form carries a nonce and a signed timestamp, both of which go stale
	// in a full-page cache — a cached copy would hand every visitor an expired
	// token. Tell the common caching plugins to leave this page alone.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	if ( ! headers_sent() ) {
		nocache_headers();
	}

	if ( ! did_action( 'wp_head' ) ) {
		wp_enqueue_style( 'aqm-contact-form' );
		aqm_cf_mark_styles_done();
	}

	$flash  = aqm_cf_get_flash();
	$status = $flash['status'] ?? '';
	$errors = isset( $flash['errors'] ) && is_array( $flash['errors'] ) ? $flash['errors'] : array();
	$values = isset( $flash['values'] ) && is_array( $flash['values'] ) ? $flash['values'] : array();

	$event_types = aqm_cf_get_event_types();

	$val = static function ( $key ) use ( $values ) {
		return isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
	};

	ob_start();
	?>
	<div class="aqm-form-wrap" id="aqm-contact-form">

		<div class="aqm-alert-region" role="status" aria-live="polite">
			<?php if ( 'success' === $status ) : ?>
				<div class="aqm-alert aqm-alert--success" tabindex="-1" id="aqm-alert">
					<span class="aqm-alert__icon" aria-hidden="true">&#10003;</span>
					<span><?php echo esc_html( $flash['general'] ?? '' ); ?></span>
				</div>
			<?php elseif ( 'error' === $status ) : ?>
				<div class="aqm-alert aqm-alert--error" tabindex="-1" id="aqm-alert">
					<span class="aqm-alert__icon" aria-hidden="true">!</span>
					<span><?php echo esc_html( $flash['general'] ?? '' ); ?></span>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( 'success' === $status ) : ?>
			<p class="aqm-form__note"><?php esc_html_e( 'If you need to send another message, please refresh this page.', 'aqm-contact-form' ); ?></p>
		<?php elseif ( ! $event_types ) : ?>
			<div class="aqm-alert aqm-alert--error">
				<span class="aqm-alert__icon" aria-hidden="true">!</span>
				<span><?php esc_html_e( 'This form is not available at the moment. Please contact us by telephone or email.', 'aqm-contact-form' ); ?></span>
			</div>
		<?php else : ?>

		<form class="aqm-form" method="post" action="">
			<?php wp_nonce_field( 'aqm_contact_submit', 'aqm_nonce' ); ?>
			<input type="hidden" name="aqm_cf_submit" value="1">
			<input type="hidden" name="aqm_ts" value="<?php echo esc_attr( aqm_cf_timestamp_token() ); ?>">

			<?php if ( aqm_cf_setting( 'spam_protection' ) ) : ?>
				<div class="aqm-hp" aria-hidden="true">
					<label for="aqm_website"><?php esc_html_e( 'Leave this field empty', 'aqm-contact-form' ); ?></label>
					<input type="text" id="aqm_website" name="aqm_website" value="" tabindex="-1" autocomplete="off">
				</div>
			<?php endif; ?>

			<div class="aqm-form__row aqm-form__row--half">
				<div class="aqm-form__group<?php echo isset( $errors['name'] ) ? ' aqm-form__group--invalid' : ''; ?>">
					<label for="aqm_name"><?php esc_html_e( 'Full Name', 'aqm-contact-form' ); ?> <span class="aqm-required" aria-hidden="true">*</span></label>
					<input type="text" id="aqm_name" name="aqm_name" maxlength="120"
						placeholder="<?php esc_attr_e( 'Your full name', 'aqm-contact-form' ); ?>"
						autocomplete="name"
						value="<?php echo esc_attr( $val( 'name' ) ); ?>"
						required aria-required="true"
						<?php echo isset( $errors['name'] ) ? 'aria-invalid="true" aria-describedby="aqm_name_error"' : ''; ?>>
					<?php if ( isset( $errors['name'] ) ) : ?>
						<span class="aqm-field-error" id="aqm_name_error"><?php echo esc_html( $errors['name'] ); ?></span>
					<?php endif; ?>
				</div>

				<div class="aqm-form__group<?php echo isset( $errors['email'] ) ? ' aqm-form__group--invalid' : ''; ?>">
					<label for="aqm_email"><?php esc_html_e( 'Email Address', 'aqm-contact-form' ); ?> <span class="aqm-required" aria-hidden="true">*</span></label>
					<input type="email" id="aqm_email" name="aqm_email" maxlength="120"
						placeholder="your@email.com"
						autocomplete="email" inputmode="email"
						value="<?php echo esc_attr( $val( 'email' ) ); ?>"
						required aria-required="true"
						<?php echo isset( $errors['email'] ) ? 'aria-invalid="true" aria-describedby="aqm_email_error"' : ''; ?>>
					<?php if ( isset( $errors['email'] ) ) : ?>
						<span class="aqm-field-error" id="aqm_email_error"><?php echo esc_html( $errors['email'] ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="aqm-form__row aqm-form__row--half">
				<div class="aqm-form__group<?php echo isset( $errors['phone'] ) ? ' aqm-form__group--invalid' : ''; ?>">
					<label for="aqm_phone"><?php esc_html_e( 'Telephone', 'aqm-contact-form' ); ?> <span class="aqm-optional"><?php esc_html_e( '(optional)', 'aqm-contact-form' ); ?></span></label>
					<input type="tel" id="aqm_phone" name="aqm_phone" maxlength="30"
						placeholder="(905) 555-0100"
						autocomplete="tel" inputmode="tel"
						value="<?php echo esc_attr( $val( 'phone' ) ); ?>"
						<?php echo isset( $errors['phone'] ) ? 'aria-invalid="true" aria-describedby="aqm_phone_error"' : ''; ?>>
					<?php if ( isset( $errors['phone'] ) ) : ?>
						<span class="aqm-field-error" id="aqm_phone_error"><?php echo esc_html( $errors['phone'] ); ?></span>
					<?php endif; ?>
				</div>

				<div class="aqm-form__group<?php echo isset( $errors['event_type'] ) ? ' aqm-form__group--invalid' : ''; ?>">
					<label for="aqm_event_type"><?php echo esc_html( aqm_cf_field_label() ); ?> <span class="aqm-required" aria-hidden="true">*</span></label>
					<select id="aqm_event_type" name="aqm_event_type" required aria-required="true"
						<?php echo isset( $errors['event_type'] ) ? 'aria-invalid="true" aria-describedby="aqm_event_type_error"' : ''; ?>>
						<option value=""><?php esc_html_e( '— Please select —', 'aqm-contact-form' ); ?></option>
						<?php foreach ( $event_types as $type ) : ?>
							<option value="<?php echo esc_attr( $type->id ); ?>" <?php selected( (int) $val( 'event_type' ), (int) $type->id ); ?>>
								<?php echo esc_html( $type->label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php if ( isset( $errors['event_type'] ) ) : ?>
						<span class="aqm-field-error" id="aqm_event_type_error"><?php echo esc_html( $errors['event_type'] ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="aqm-form__group<?php echo isset( $errors['message'] ) ? ' aqm-form__group--invalid' : ''; ?>">
				<label for="aqm_message"><?php esc_html_e( 'Message', 'aqm-contact-form' ); ?> <span class="aqm-required" aria-hidden="true">*</span></label>
				<textarea id="aqm_message" name="aqm_message" rows="6" maxlength="5000"
					placeholder="<?php esc_attr_e( 'Please describe your inquiry or event in as much detail as you’d like…', 'aqm-contact-form' ); ?>"
					required aria-required="true"
					<?php echo isset( $errors['message'] ) ? 'aria-invalid="true" aria-describedby="aqm_message_error"' : ''; ?>><?php echo esc_textarea( $val( 'message' ) ); ?></textarea>
				<?php if ( isset( $errors['message'] ) ) : ?>
					<span class="aqm-field-error" id="aqm_message_error"><?php echo esc_html( $errors['message'] ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( aqm_cf_setting( 'consent_enabled' ) ) : ?>
				<div class="aqm-form__group aqm-form__consent<?php echo isset( $errors['consent'] ) ? ' aqm-form__group--invalid' : ''; ?>">
					<input type="checkbox" id="aqm_consent" name="aqm_consent" value="1"
						<?php checked( '1', $val( 'consent' ) ); ?>
						required aria-required="true"
						<?php echo isset( $errors['consent'] ) ? 'aria-invalid="true" aria-describedby="aqm_consent_error"' : ''; ?>>
					<label for="aqm_consent">
						<?php echo esc_html( (string) aqm_cf_setting( 'consent_text' ) ); ?>
						<?php if ( isset( $errors['consent'] ) ) : ?>
							<span class="aqm-field-error" id="aqm_consent_error"><?php echo esc_html( $errors['consent'] ); ?></span>
						<?php endif; ?>
					</label>
				</div>
			<?php endif; ?>

			<div class="aqm-form__footer">
				<p class="aqm-form__note"><span class="aqm-required" aria-hidden="true">*</span> <?php esc_html_e( 'Required fields', 'aqm-contact-form' ); ?></p>
				<button type="submit" class="aqm-btn"><?php esc_html_e( 'Send Message', 'aqm-contact-form' ); ?></button>
			</div>
		</form>
		<?php endif; ?>
	</div>

	<?php if ( $status ) : ?>
	<script>
	(function () {
		var alertBox = document.getElementById('aqm-alert');
		if (!alertBox) { return; }
		alertBox.focus();
		var firstInvalid = document.querySelector('.aqm-form__group--invalid input, .aqm-form__group--invalid select, .aqm-form__group--invalid textarea');
		if (firstInvalid) { firstInvalid.focus({ preventScroll: true }); }
	})();
	</script>
	<?php endif; ?>
	<?php
	return aqm_cf_style_fallback() . ob_get_clean();
}

/* ═══════════════════════════════════════════════════════════════════════
   8. ADMIN — menu
   ═══════════════════════════════════════════════════════════════════════ */

add_action( 'admin_menu', 'aqm_cf_admin_menu' );

function aqm_cf_admin_menu() {
	add_menu_page(
		__( 'A. Q. Mufti Contact', 'aqm-contact-form' ),
		__( 'AQM Contact', 'aqm-contact-form' ),
		'manage_options',
		'aqm-contact-entries',
		'aqm_cf_entries_page',
		'dashicons-email-alt',
		30
	);
	add_submenu_page( 'aqm-contact-entries', __( 'Contact Entries', 'aqm-contact-form' ), __( 'Contact Entries', 'aqm-contact-form' ), 'manage_options', 'aqm-contact-entries', 'aqm_cf_entries_page' );
	add_submenu_page( 'aqm-contact-entries', __( 'Manage Event Types', 'aqm-contact-form' ), __( 'Event Types', 'aqm-contact-form' ), 'manage_options', 'aqm-event-types', 'aqm_cf_event_types_page' );
	add_submenu_page( 'aqm-contact-entries', __( 'Contact Form Settings', 'aqm-contact-form' ), __( 'Settings', 'aqm-contact-form' ), 'manage_options', 'aqm-contact-settings', 'aqm_cf_settings_page' );
}

/** Warn the admin when the mailer is silently failing. */
add_action( 'admin_notices', 'aqm_cf_mail_failure_notice' );

function aqm_cf_mail_failure_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$entry_id = (int) get_transient( 'aqm_cf_mail_failure' );
	if ( ! $entry_id ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'AQM Contact Form:', 'aqm-contact-form' ) . '</strong> ';
	echo esc_html__( 'WordPress could not send the notification email for a recent submission. The enquiry was saved — please check Contact Entries and consider installing an SMTP plugin.', 'aqm-contact-form' );
	echo ' <a href="' . esc_url( admin_url( 'admin.php?page=aqm-contact-entries&action=view&id=' . $entry_id ) ) . '">' . esc_html__( 'View entry', 'aqm-contact-form' ) . '</a></p></div>';
	delete_transient( 'aqm_cf_mail_failure' );
}

/* ═══════════════════════════════════════════════════════════════════════
   9. ADMIN — Contact Entries (list, view, bulk delete, CSV export)
   ═══════════════════════════════════════════════════════════════════════ */

function aqm_cf_entries_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'aqm-contact-form' ) );
	}

	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	if ( 'view' === $action ) {
		aqm_cf_entry_detail_page( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 );
		return;
	}

	global $wpdb;
	$table  = aqm_cf_entries_table();
	$notice = '';

	/* ---- Bulk delete, then redirect so a refresh cannot repeat it ---- */
	if ( isset( $_POST['aqm_bulk_nonce'] ) ) {
		check_admin_referer( 'aqm_cf_bulk_entries', 'aqm_bulk_nonce' );

		$ids = isset( $_POST['entry_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['entry_ids'] ) ) : array();
		$ids = array_values( array_filter( $ids ) );
		$act = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';

		$result = 'none';
		if ( 'delete' === $act && $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$deleted      = $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($placeholders)", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL

			if ( false === $deleted ) {
				error_log( 'AQM Contact Form: bulk delete failed — ' . $wpdb->last_error );
				$result = 'failed';
			} else {
				$result = (int) $deleted;
			}
		}

		// Preserve the current search/filter, but go back to page one: the
		// page the admin was on may no longer exist after a delete.
		$back = add_query_arg(
			array(
				'page'          => 'aqm-contact-entries',
				's'             => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'event_type_id' => isset( $_GET['event_type_id'] ) ? (int) $_GET['event_type_id'] : 0,
				'aqm_deleted'   => $result,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $back );
		exit;
	}

	if ( isset( $_GET['aqm_deleted'] ) ) {
		$result = sanitize_text_field( wp_unslash( $_GET['aqm_deleted'] ) );
		if ( 'failed' === $result ) {
			$notice = __( 'The entries could not be deleted — a database error was logged.', 'aqm-contact-form' );
		} elseif ( 'none' === $result ) {
			$notice = __( 'No entries were selected.', 'aqm-contact-form' );
		} else {
			$notice = sprintf(
				/* translators: %d: number of entries */
				_n( '%d entry deleted.', '%d entries deleted.', (int) $result, 'aqm-contact-form' ),
				(int) $result
			);
		}
	}

	/* ---- Filters & pagination ---- */
	$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$type_id  = isset( $_GET['event_type_id'] ) ? (int) $_GET['event_type_id'] : 0;
	$paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
	$per_page = 25;

	$where  = array( '1=1' );
	$params = array();

	if ( '' !== $search ) {
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR message LIKE %s)';
		array_push( $params, $like, $like, $like, $like );
	}
	if ( $type_id ) {
		$where[]  = 'event_type_id = %d';
		$params[] = $type_id;
	}
	$where_sql = implode( ' AND ', $where );

	$count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
	$total     = (int) ( $params
		? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL
		: $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	// Clamp the page AFTER counting, so a delete that shrinks the result set
	// cannot strand the admin on an empty "No entries found" page.
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	$paged       = min( $paged, $total_pages );
	$offset      = ( $paged - 1 ) * $per_page;

	$list_sql = "SELECT * FROM $table WHERE $where_sql ORDER BY submitted_at DESC, id DESC LIMIT %d OFFSET %d";
	$entries  = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$event_types = aqm_cf_get_event_types();

	$export_url = wp_nonce_url(
		add_query_arg(
			array(
				'action'        => 'aqm_cf_export',
				's'             => $search,
				'event_type_id' => $type_id,
			),
			admin_url( 'admin-post.php' )
		),
		'aqm_cf_export'
	);
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Contact Form Entries', 'aqm-contact-form' ); ?></h1>
		<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'aqm-contact-form' ); ?></a>
		<hr class="wp-header-end">

		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<input type="hidden" name="page" value="aqm-contact-entries">
			<label class="screen-reader-text" for="aqm-search"><?php esc_html_e( 'Search entries', 'aqm-contact-form' ); ?></label>
			<input type="search" id="aqm-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, message…', 'aqm-contact-form' ); ?>" style="min-width:260px">
			<label class="screen-reader-text" for="aqm-type-filter"><?php echo esc_html( sprintf( /* translators: %s: the dropdown's configured name */ __( 'Filter by %s', 'aqm-contact-form' ), aqm_cf_field_label() ) ); ?></label>
			<select name="event_type_id" id="aqm-type-filter">
				<option value="0"><?php esc_html_e( 'All types', 'aqm-contact-form' ); ?></option>
				<?php foreach ( $event_types as $type ) : ?>
					<option value="<?php echo esc_attr( $type->id ); ?>" <?php selected( $type_id, (int) $type->id ); ?>><?php echo esc_html( $type->label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'aqm-contact-form' ), 'secondary', '', false ); ?>
			<?php if ( '' !== $search || $type_id ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=aqm-contact-entries' ) ); ?>"><?php esc_html_e( 'Reset', 'aqm-contact-form' ); ?></a>
			<?php endif; ?>
			<span class="displaying-num" style="margin-left:auto">
				<?php
				printf(
					/* translators: %s: number of entries */
					esc_html( _n( '%s entry', '%s entries', $total, 'aqm-contact-form' ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</span>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'aqm_cf_bulk_entries', 'aqm_bulk_nonce' ); ?>
			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<label class="screen-reader-text" for="aqm-bulk"><?php esc_html_e( 'Bulk action', 'aqm-contact-form' ); ?></label>
					<select name="bulk_action" id="aqm-bulk">
						<option value=""><?php esc_html_e( 'Bulk actions', 'aqm-contact-form' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete permanently', 'aqm-contact-form' ); ?></option>
					</select>
					<button type="submit" class="button action" onclick="return confirm('<?php echo esc_js( __( 'Delete the selected entries? This cannot be undone.', 'aqm-contact-form' ) ); ?>');"><?php esc_html_e( 'Apply', 'aqm-contact-form' ); ?></button>
				</div>
			</div>

			<table class="widefat fixed striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" onclick="var b=this.checked;this.closest('form').querySelectorAll('input[name=\'entry_ids[]\']').forEach(function(c){c.checked=b;});" aria-label="<?php esc_attr_e( 'Select all entries', 'aqm-contact-form' ); ?>"></td>
						<th style="width:60px"><?php esc_html_e( 'ID', 'aqm-contact-form' ); ?></th>
						<th style="width:15%"><?php esc_html_e( 'Name', 'aqm-contact-form' ); ?></th>
						<th style="width:18%"><?php esc_html_e( 'Email', 'aqm-contact-form' ); ?></th>
						<th style="width:12%"><?php esc_html_e( 'Phone', 'aqm-contact-form' ); ?></th>
						<th style="width:15%"><?php echo esc_html( aqm_cf_field_label() ); ?></th>
						<th><?php esc_html_e( 'Message', 'aqm-contact-form' ); ?></th>
						<th style="width:140px"><?php esc_html_e( 'Date', 'aqm-contact-form' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( $entries ) : ?>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<th scope="row" class="check-column">
								<input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr( $entry->id ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: entry ID */ __( 'Select entry %d', 'aqm-contact-form' ), $entry->id ) ); ?>">
							</th>
							<td><?php echo esc_html( $entry->id ); ?></td>
							<td>
								<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=aqm-contact-entries&action=view&id=' . $entry->id ) ); ?>"><?php echo esc_html( $entry->name ); ?></a></strong>
							</td>
							<td><a href="mailto:<?php echo esc_attr( $entry->email ); ?>"><?php echo esc_html( $entry->email ); ?></a></td>
							<td><?php echo esc_html( $entry->phone ); ?></td>
							<td><?php echo esc_html( $entry->event_type ); ?></td>
							<td><?php echo esc_html( wp_trim_words( $entry->message, 18, '…' ) ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->submitted_at ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No entries found.', 'aqm-contact-form' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</form>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%', remove_query_arg( 'aqm_deleted' ) ),
								'format'    => '',
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
								'total'     => $total_pages,
								'current'   => $paged,
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

function aqm_cf_entry_detail_page( $id ) {
	global $wpdb;
	$table = aqm_cf_entries_table();
	$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Contact Entry', 'aqm-contact-form' ) . '</h1>';
	echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=aqm-contact-entries' ) ) . '">&larr; ' . esc_html__( 'Back to all entries', 'aqm-contact-form' ) . '</a></p>';

	if ( ! $entry ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'That entry no longer exists.', 'aqm-contact-form' ) . '</p></div></div>';
		return;
	}

	$rows = array(
		__( 'Name', 'aqm-contact-form' )       => $entry->name,
		__( 'Email', 'aqm-contact-form' )      => $entry->email,
		__( 'Telephone', 'aqm-contact-form' )  => $entry->phone ? $entry->phone : __( 'Not provided', 'aqm-contact-form' ),
		aqm_cf_field_label()                   => $entry->event_type,
		__( 'Submitted', 'aqm-contact-form' )  => mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->submitted_at ),
		__( 'Consent given', 'aqm-contact-form' ) => $entry->consent ? __( 'Yes', 'aqm-contact-form' ) : __( 'Not recorded', 'aqm-contact-form' ),
		__( 'IP address', 'aqm-contact-form' ) => $entry->ip_address ? $entry->ip_address : __( 'Not stored', 'aqm-contact-form' ),
	);

	echo '<table class="widefat striped" style="max-width:760px">';
	foreach ( $rows as $label => $value ) {
		echo '<tr><th style="width:180px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
	echo '</table>';

	echo '<h2>' . esc_html__( 'Message', 'aqm-contact-form' ) . '</h2>';
	echo '<div class="postbox" style="max-width:760px;padding:16px 20px;white-space:pre-wrap;">' . esc_html( $entry->message ) . '</div>';

	$subject = rawurlencode( sprintf( /* translators: %s: event type */ __( 'Re: your enquiry about %s', 'aqm-contact-form' ), $entry->event_type ) );
	echo '<p><a class="button button-primary" href="mailto:' . esc_attr( $entry->email ) . '?subject=' . esc_attr( $subject ) . '">' . esc_html__( 'Reply by email', 'aqm-contact-form' ) . '</a></p>';
	echo '</div>';
}

/* ---- CSV export ---- */
add_action( 'admin_post_aqm_cf_export', 'aqm_cf_export_csv' );

function aqm_cf_export_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to export entries.', 'aqm-contact-form' ) );
	}
	check_admin_referer( 'aqm_cf_export' );

	global $wpdb;
	$table = aqm_cf_entries_table();

	$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$type_id = isset( $_GET['event_type_id'] ) ? (int) $_GET['event_type_id'] : 0;

	$where  = array( '1=1' );
	$params = array();
	if ( '' !== $search ) {
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR message LIKE %s)';
		array_push( $params, $like, $like, $like, $like );
	}
	if ( $type_id ) {
		$where[]  = 'event_type_id = %d';
		$params[] = $type_id;
	}
	$sql = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . ' ORDER BY submitted_at DESC, id DESC';

	$entries = $params
		? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL
		: $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL

	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename=aqm-contact-entries-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel reads UTF-8 correctly.

	// The explicit empty escape character avoids PHP 8.4's deprecation notice
	// and stops backslashes being mangled in message text.
	fputcsv( $out, array( 'ID', 'Name', 'Email', 'Phone', 'Event Type', 'Message', 'Consent', 'IP Address', 'Submitted' ), ',', '"', '' );

	foreach ( $entries as $entry ) {
		fputcsv(
			$out,
			array_map(
				'aqm_cf_csv_safe',
				array(
					$entry->id,
					$entry->name,
					$entry->email,
					$entry->phone,
					$entry->event_type,
					$entry->message,
					$entry->consent ? 'yes' : 'no',
					$entry->ip_address,
					$entry->submitted_at,
				)
			),
			',',
			'"',
			''
		);
	}

	fclose( $out );
	exit;
}

/**
 * Neutralise spreadsheet formula injection (=, +, -, @, tab, CR).
 */
function aqm_cf_csv_safe( $value ) {
	$value = (string) $value;
	if ( '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
		return "'" . $value;
	}
	return $value;
}

/* ═══════════════════════════════════════════════════════════════════════
   10. ADMIN — Event Types (add / edit / delete, with PRG)
   ═══════════════════════════════════════════════════════════════════════ */

function aqm_cf_event_types_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'aqm-contact-form' ) );
	}

	global $wpdb;
	$table    = aqm_cf_event_types_table();
	$base_url = admin_url( 'admin.php?page=aqm-event-types' );

	/* ---- Delete ---- */
	if (
		isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) &&
		'delete' === sanitize_key( wp_unslash( $_GET['action'] ) )
	) {
		$id = (int) $_GET['id'];
		check_admin_referer( 'aqm_delete_event_' . $id );
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		aqm_cf_flush_event_type_cache();
		wp_safe_redirect( add_query_arg( 'aqm_msg', 'deleted', $base_url ) );
		exit;
	}

	/* ---- Add / edit ---- */
	if ( isset( $_POST['aqm_event_nonce'] ) ) {
		check_admin_referer( 'aqm_save_event_type', 'aqm_event_nonce' );

		$label   = isset( $_POST['aqm_event_label'] ) ? sanitize_text_field( wp_unslash( $_POST['aqm_event_label'] ) ) : '';
		$edit_id = isset( $_POST['aqm_edit_id'] ) ? (int) $_POST['aqm_edit_id'] : 0;
		$has_ord = isset( $_POST['aqm_event_order'] ) && '' !== trim( (string) wp_unslash( $_POST['aqm_event_order'] ) );
		$order   = $has_ord ? max( 0, (int) $_POST['aqm_event_order'] ) : null;

		if ( '' === $label ) {
			wp_safe_redirect( add_query_arg( 'aqm_msg', 'empty', $base_url ) );
			exit;
		}

		// Reject duplicates — two identically named types are confusing in reports.
		$duplicate = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE label = %s AND id <> %d", $label, $edit_id )
		);
		if ( $duplicate ) {
			wp_safe_redirect( add_query_arg( 'aqm_msg', 'duplicate', $base_url ) );
			exit;
		}

		if ( $edit_id ) {
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $edit_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->update(
				$table,
				array(
					'label'      => $label,
					'sort_order' => null === $order ? (int) ( $current->sort_order ?? 0 ) : $order,
				),
				array( 'id' => $edit_id ),
				array( '%s', '%d' ),
				array( '%d' )
			);
			$msg = 'updated';
		} else {
			$max = (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->insert(
				$table,
				array(
					'label'      => $label,
					'sort_order' => null === $order ? $max + 1 : $order,
				),
				array( '%s', '%d' )
			);
			$msg = 'added';
		}

		aqm_cf_flush_event_type_cache();
		wp_safe_redirect( add_query_arg( 'aqm_msg', $msg, $base_url ) );
		exit;
	}

	/* ---- Notices ---- */
	$notices = array(
		'added'     => array( 'success', __( 'Event type added.', 'aqm-contact-form' ) ),
		'updated'   => array( 'success', __( 'Event type updated.', 'aqm-contact-form' ) ),
		'deleted'   => array( 'success', __( 'Event type deleted.', 'aqm-contact-form' ) ),
		'empty'     => array( 'error', __( 'Event type name cannot be empty.', 'aqm-contact-form' ) ),
		'duplicate' => array( 'error', __( 'An event type with that name already exists.', 'aqm-contact-form' ) ),
	);
	$msg_key = isset( $_GET['aqm_msg'] ) ? sanitize_key( wp_unslash( $_GET['aqm_msg'] ) ) : '';

	/* ---- Record being edited ---- */
	$editing = null;
	if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
		$editing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $_GET['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	$event_types = aqm_cf_get_event_types();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Manage Event Types', 'aqm-contact-form' ); ?></h1>

		<?php if ( isset( $notices[ $msg_key ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notices[ $msg_key ][0] ); ?> is-dismissible">
				<p><?php echo esc_html( $notices[ $msg_key ][1] ); ?></p>
			</div>
		<?php endif; ?>

		<div style="display:flex;gap:40px;align-items:flex-start;margin-top:20px;flex-wrap:wrap;">

			<div style="flex:1;min-width:320px;">
				<h2 style="margin-top:0"><?php esc_html_e( 'Current Event Types', 'aqm-contact-form' ); ?></h2>
				<?php if ( $event_types ) : ?>
					<table class="widefat fixed striped">
						<thead>
							<tr>
								<th style="width:70px"><?php esc_html_e( 'Order', 'aqm-contact-form' ); ?></th>
								<th><?php esc_html_e( 'Event Type Name', 'aqm-contact-form' ); ?></th>
								<th style="width:90px"><?php esc_html_e( 'Entries', 'aqm-contact-form' ); ?></th>
								<th style="width:150px"><?php esc_html_e( 'Actions', 'aqm-contact-form' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						// One grouped query rather than one per row.
						$entries_table = aqm_cf_entries_table();
						$counts        = array();
						foreach ( (array) $wpdb->get_results( "SELECT event_type_id, COUNT(*) AS total FROM $entries_table GROUP BY event_type_id" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL
							$counts[ (int) $row->event_type_id ] = (int) $row->total;
						}
						foreach ( $event_types as $type ) :
							$used       = $counts[ (int) $type->id ] ?? 0;
							$edit_url   = add_query_arg(
								array(
									'action' => 'edit',
									'id'     => $type->id,
								),
								$base_url
							);
							$delete_url = wp_nonce_url(
								add_query_arg(
									array(
										'action' => 'delete',
										'id'     => $type->id,
									),
									$base_url
								),
								'aqm_delete_event_' . $type->id
							);
							?>
							<tr>
								<td><?php echo esc_html( $type->sort_order ); ?></td>
								<td><?php echo esc_html( $type->label ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $used ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'aqm-contact-form' ); ?></a>
									&nbsp;
									<a href="<?php echo esc_url( $delete_url ); ?>"
										class="button button-small"
										style="color:#b32d2e;border-color:#b32d2e;"
										onclick="return confirm('<?php echo esc_js( __( 'Delete this event type? Existing entries keep their saved label. This cannot be undone.', 'aqm-contact-form' ) ); ?>')"><?php esc_html_e( 'Delete', 'aqm-contact-form' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'Deleting a type removes it from the form only — past entries keep the label they were submitted with.', 'aqm-contact-form' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'No event types yet. Add one using the form.', 'aqm-contact-form' ); ?></p>
				<?php endif; ?>
			</div>

			<div style="flex:0 0 300px;">
				<h2 style="margin-top:0"><?php echo $editing ? esc_html__( 'Edit Event Type', 'aqm-contact-form' ) : esc_html__( 'Add New Event Type', 'aqm-contact-form' ); ?></h2>
				<div class="postbox" style="padding:16px 20px;">
					<form method="post" action="<?php echo esc_url( $base_url ); ?>">
						<?php wp_nonce_field( 'aqm_save_event_type', 'aqm_event_nonce' ); ?>
						<?php if ( $editing ) : ?>
							<input type="hidden" name="aqm_edit_id" value="<?php echo esc_attr( $editing->id ); ?>">
						<?php endif; ?>

						<p>
							<label for="aqm_event_label"><strong><?php esc_html_e( 'Name', 'aqm-contact-form' ); ?> <span style="color:#9a6c1f">*</span></strong></label><br>
							<input type="text" id="aqm_event_label" name="aqm_event_label"
								class="regular-text" maxlength="120"
								value="<?php echo esc_attr( $editing ? $editing->label : '' ); ?>"
								placeholder="<?php esc_attr_e( 'e.g. Prayer Gathering', 'aqm-contact-form' ); ?>"
								style="width:100%;margin-top:4px" required>
						</p>
						<p>
							<label for="aqm_event_order"><strong><?php esc_html_e( 'Sort Order', 'aqm-contact-form' ); ?></strong></label><br>
							<input type="number" id="aqm_event_order" name="aqm_event_order"
								value="<?php echo esc_attr( $editing ? $editing->sort_order : '' ); ?>"
								placeholder="<?php esc_attr_e( 'Auto', 'aqm-contact-form' ); ?>" min="1" style="width:80px;margin-top:4px">
							<br><span class="description"><?php esc_html_e( 'Lower numbers appear first. Leave blank to keep the current position.', 'aqm-contact-form' ); ?></span>
						</p>
						<p style="display:flex;gap:10px;align-items:center;margin-top:16px;">
							<?php submit_button( $editing ? __( 'Update', 'aqm-contact-form' ) : __( 'Add Event Type', 'aqm-contact-form' ), 'primary', 'submit', false ); ?>
							<?php if ( $editing ) : ?>
								<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'aqm-contact-form' ); ?></a>
							<?php endif; ?>
						</p>
					</form>
				</div>
			</div>

		</div>
	</div>
	<?php
}

/* ═══════════════════════════════════════════════════════════════════════
   11. ADMIN — Settings
   ═══════════════════════════════════════════════════════════════════════ */

function aqm_cf_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'aqm-contact-form' ) );
	}
	$s = aqm_cf_get_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Contact Form Settings', 'aqm-contact-form' ); ?></h1>
		<p class="description"><?php esc_html_e( 'Place the form on any page with the shortcode [aqm_contact_form].', 'aqm-contact-form' ); ?></p>

		<?php settings_errors( 'aqm_cf_settings' ); ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'aqm_cf_settings_group' ); ?>

			<h2><?php esc_html_e( 'The Form', 'aqm-contact-form' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aqm_field_label"><?php esc_html_e( 'Name of the dropdown', 'aqm-contact-form' ); ?></label></th>
					<td>
						<input type="text" id="aqm_field_label" class="regular-text" name="aqm_cf_settings[field_label]" value="<?php echo esc_attr( $s['field_label'] ); ?>">
						<p class="description"><?php esc_html_e( 'What the list of options is called on the form — “Type of Event”, “Session Type”, “Reason for Contact”, and so on. The options themselves are managed under Event Types.', 'aqm-contact-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aqm_success"><?php esc_html_e( 'Thank-you message', 'aqm-contact-form' ); ?></label></th>
					<td>
						<input type="text" id="aqm_success" class="large-text" name="aqm_cf_settings[success_message]" value="<?php echo esc_attr( $s['success_message'] ); ?>">
						<p class="description"><?php esc_html_e( 'Shown on the page after a successful submission. {name} is available.', 'aqm-contact-form' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Notifications', 'aqm-contact-form' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aqm_notify_email"><?php esc_html_e( 'Send enquiries to', 'aqm-contact-form' ); ?></label></th>
					<td><input type="email" id="aqm_notify_email" class="regular-text" name="aqm_cf_settings[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="aqm_notify_cc"><?php esc_html_e( 'Cc (optional)', 'aqm-contact-form' ); ?></label></th>
					<td>
						<input type="text" id="aqm_notify_cc" class="regular-text" name="aqm_cf_settings[notify_cc]" value="<?php echo esc_attr( $s['notify_cc'] ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated. Invalid addresses are dropped when you save.', 'aqm-contact-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-reply', 'aqm-contact-form' ); ?></th>
					<td>
						<label><input type="checkbox" name="aqm_cf_settings[autoreply]" value="1" <?php checked( 1, (int) $s['autoreply'] ); ?>> <?php esc_html_e( 'Send a confirmation email to the person who wrote in', 'aqm-contact-form' ); ?></label>
						<p style="margin-top:10px">
							<label for="aqm_ar_subject"><?php esc_html_e( 'Subject', 'aqm-contact-form' ); ?></label><br>
							<input type="text" id="aqm_ar_subject" class="large-text" name="aqm_cf_settings[autoreply_subject]" value="<?php echo esc_attr( $s['autoreply_subject'] ); ?>">
						</p>
						<p>
							<label for="aqm_ar_body"><?php esc_html_e( 'Message', 'aqm-contact-form' ); ?></label><br>
							<textarea id="aqm_ar_body" class="large-text" rows="8" name="aqm_cf_settings[autoreply_body]"><?php echo esc_textarea( $s['autoreply_body'] ); ?></textarea>
						</p>
						<p class="description"><?php esc_html_e( 'Placeholders: {name} {email} {event_type} {message} {site_name}', 'aqm-contact-form' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Spam Protection', 'aqm-contact-form' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Silent traps', 'aqm-contact-form' ); ?></th>
					<td>
						<label><input type="checkbox" name="aqm_cf_settings[spam_protection]" value="1" <?php checked( 1, (int) $s['spam_protection'] ); ?>> <?php esc_html_e( 'Enable honeypot and timing checks (no CAPTCHA, invisible to visitors)', 'aqm-contact-form' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aqm_rate"><?php esc_html_e( 'Submissions per hour', 'aqm-contact-form' ); ?></label></th>
					<td>
						<input type="number" id="aqm_rate" min="0" max="100" name="aqm_cf_settings[rate_limit]" value="<?php echo esc_attr( $s['rate_limit'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Per IP address. Set to 0 to disable rate limiting.', 'aqm-contact-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aqm_links"><?php esc_html_e( 'Maximum links in a message', 'aqm-contact-form' ); ?></label></th>
					<td>
						<input type="number" id="aqm_links" min="0" max="50" name="aqm_cf_settings[max_links]" value="<?php echo esc_attr( $s['max_links'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Set to 0 to allow any number of links.', 'aqm-contact-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aqm_proxy"><?php esc_html_e( 'Is this site behind a proxy?', 'aqm-contact-form' ); ?></label></th>
					<td>
						<select id="aqm_proxy" name="aqm_cf_settings[proxy_header]">
							<?php foreach ( aqm_cf_proxy_headers() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['proxy_header'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Rate limiting counts submissions per visitor IP. Behind Cloudflare or a load balancer the server sees the proxy’s address instead, so every visitor shares one allowance and genuine enquiries get blocked. Pick your proxy here to fix that.', 'aqm-contact-form' ); ?>
							<br>
							<strong><?php esc_html_e( 'Detected right now:', 'aqm-contact-form' ); ?></strong>
							<code><?php echo esc_html( aqm_cf_get_client_ip() ? aqm_cf_get_client_ip() : __( 'unknown', 'aqm-contact-form' ) ); ?></code>
							<?php esc_html_e( '— if that is not your own IP address, choose a different option above.', 'aqm-contact-form' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Privacy', 'aqm-contact-form' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Consent checkbox', 'aqm-contact-form' ); ?></th>
					<td>
						<label><input type="checkbox" name="aqm_cf_settings[consent_enabled]" value="1" <?php checked( 1, (int) $s['consent_enabled'] ); ?>> <?php esc_html_e( 'Require visitors to agree before submitting', 'aqm-contact-form' ); ?></label>
						<p style="margin-top:10px">
							<input type="text" class="large-text" name="aqm_cf_settings[consent_text]" value="<?php echo esc_attr( $s['consent_text'] ); ?>">
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'IP addresses', 'aqm-contact-form' ); ?></th>
					<td>
						<label><input type="checkbox" name="aqm_cf_settings[store_ip]" value="1" <?php checked( 1, (int) $s['store_ip'] ); ?>> <?php esc_html_e( 'Store the sender’s IP address with each entry', 'aqm-contact-form' ); ?></label><br>
						<label><input type="checkbox" name="aqm_cf_settings[anonymise_ip]" value="1" <?php checked( 1, (int) $s['anonymise_ip'] ); ?>> <?php esc_html_e( 'Anonymise it (mask the last portion)', 'aqm-contact-form' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'aqm-contact-form' ); ?></th>
					<td>
						<label><input type="checkbox" name="aqm_cf_settings[delete_on_uninstall]" value="1" <?php checked( 1, (int) $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all entries and settings if this plugin is deleted', 'aqm-contact-form' ); ?></label>
						<p class="description"><?php esc_html_e( 'Leave unticked to keep your enquiry history safe.', 'aqm-contact-form' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/* ═══════════════════════════════════════════════════════════════════════
   12. PRIVACY TOOLS — export & erase personal data
   ═══════════════════════════════════════════════════════════════════════ */

add_filter( 'wp_privacy_personal_data_exporters', 'aqm_cf_register_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', 'aqm_cf_register_eraser' );

function aqm_cf_register_exporter( $exporters ) {
	$exporters['aqm-contact-form'] = array(
		'exporter_friendly_name' => __( 'A. Q. Mufti Contact Form', 'aqm-contact-form' ),
		'callback'               => 'aqm_cf_export_personal_data',
	);
	return $exporters;
}

function aqm_cf_export_personal_data( $email, $page = 1 ) {
	global $wpdb;
	$table   = aqm_cf_entries_table();
	$entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	$export = array();
	foreach ( (array) $entries as $entry ) {
		$export[] = array(
			'group_id'    => 'aqm-contact-form',
			'group_label' => __( 'Contact form submissions', 'aqm-contact-form' ),
			'item_id'     => 'aqm-entry-' . $entry->id,
			'data'        => array(
				array(
					'name'  => __( 'Name', 'aqm-contact-form' ),
					'value' => $entry->name,
				),
				array(
					'name'  => __( 'Email', 'aqm-contact-form' ),
					'value' => $entry->email,
				),
				array(
					'name'  => __( 'Telephone', 'aqm-contact-form' ),
					'value' => $entry->phone,
				),
				array(
					'name'  => __( 'Event type', 'aqm-contact-form' ),
					'value' => $entry->event_type,
				),
				array(
					'name'  => __( 'Message', 'aqm-contact-form' ),
					'value' => $entry->message,
				),
				array(
					'name'  => __( 'Submitted', 'aqm-contact-form' ),
					'value' => $entry->submitted_at,
				),
			),
		);
	}

	return array(
		'data' => $export,
		'done' => true,
	);
}

function aqm_cf_register_eraser( $erasers ) {
	$erasers['aqm-contact-form'] = array(
		'eraser_friendly_name' => __( 'A. Q. Mufti Contact Form', 'aqm-contact-form' ),
		'callback'             => 'aqm_cf_erase_personal_data',
	);
	return $erasers;
}

function aqm_cf_erase_personal_data( $email, $page = 1 ) {
	global $wpdb;
	$removed = (int) $wpdb->delete( aqm_cf_entries_table(), array( 'email' => $email ), array( '%s' ) );

	return array(
		'items_removed'  => $removed > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}

/* ═══════════════════════════════════════════════════════════════════════
   13. SELF-UPDATE FROM GITHUB RELEASES

   WordPress only knows how to update plugins it can look up somewhere. These
   filters point it at this plugin's GitHub releases instead of wordpress.org,
   so updates appear in Dashboard → Updates like any other plugin.

   To release: tag a new version on GitHub, attach the built .zip to the
   release, and make sure the "Version:" header above matches the tag.
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * The latest published release, or null if GitHub could not be reached.
 *
 * Cached for 12 hours so we are not hitting the API on every admin page load;
 * failures are cached for 1 hour so an outage does not slow the dashboard.
 *
 * @param bool $force Ignore the cache and ask GitHub now.
 * @return array|null version, package, url, changelog, published
 */
function aqm_cf_github_release( $force = false ) {
	$cache_key = 'aqm_cf_gh_release';

	if ( ! $force ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/' . AQM_CF_GITHUB_REPO . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'aqm-contact-form/' . AQM_CF_VERSION,
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		set_transient( $cache_key, array(), HOUR_IN_SECONDS );
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
		set_transient( $cache_key, array(), HOUR_IN_SECONDS );
		return null;
	}

	// Prefer a .zip attached to the release. GitHub's automatic "zipball"
	// names its top-level folder after the commit, which would install the
	// plugin as a duplicate rather than updating it in place.
	$package = '';
	foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
		if ( ! empty( $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'] ?? '', -4 ) ) {
			$package = $asset['browser_download_url'];
			break;
		}
	}
	if ( ! $package ) {
		$package = $body['zipball_url'] ?? '';
	}

	$release = array(
		'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
		'package'   => $package,
		'url'       => $body['html_url'] ?? 'https://github.com/' . AQM_CF_GITHUB_REPO,
		'changelog' => (string) ( $body['body'] ?? '' ),
		'published' => (string) ( $body['published_at'] ?? '' ),
	);

	set_transient( $cache_key, $release, 12 * HOUR_IN_SECONDS );
	return $release;
}

/**
 * Tell WordPress an update is available.
 */
add_filter( 'site_transient_update_plugins', 'aqm_cf_inject_update' );

function aqm_cf_inject_update( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}

	$plugin  = plugin_basename( AQM_CF_FILE );
	$slug    = dirname( $plugin );
	$release = aqm_cf_github_release();

	if ( ! $release || ! $release['package'] ) {
		return $transient;
	}

	$item = (object) array(
		'id'           => 'github.com/' . AQM_CF_GITHUB_REPO,
		'slug'         => $slug,
		'plugin'       => $plugin,
		'new_version'  => $release['version'],
		'url'          => $release['url'],
		'package'      => $release['package'],
		'requires'     => '5.8',
		'requires_php' => '7.4',
		'icons'        => array(),
		'banners'      => array(),
		'tested'       => get_bloginfo( 'version' ),
	);

	if ( version_compare( $release['version'], AQM_CF_VERSION, '>' ) ) {
		$transient->response[ $plugin ] = $item;
	} else {
		// Listing it here (rather than omitting it) is what makes the
		// "Enable auto-updates" link appear on the Plugins screen.
		$transient->no_update[ $plugin ] = $item;
	}

	return $transient;
}

/**
 * Fill in the "View details" popup.
 */
add_filter( 'plugins_api', 'aqm_cf_plugin_details', 20, 3 );

function aqm_cf_plugin_details( $result, $action, $args ) {
	if ( 'plugin_information' !== $action ) {
		return $result;
	}
	if ( empty( $args->slug ) || dirname( plugin_basename( AQM_CF_FILE ) ) !== $args->slug ) {
		return $result;
	}

	$release = aqm_cf_github_release();
	if ( ! $release ) {
		return $result;
	}

	$changelog = $release['changelog']
		? wpautop( wp_kses_post( $release['changelog'] ) )
		: '<p>' . esc_html__( 'No release notes were provided.', 'aqm-contact-form' ) . '</p>';

	return (object) array(
		'name'          => 'A. Q. Mufti – Contact Form',
		'slug'          => $args->slug,
		'version'       => $release['version'],
		'author'        => '<a href="https://github.com/AQMufti">A. Q. Mufti</a>',
		'homepage'      => $release['url'],
		'download_link' => $release['package'],
		'requires'      => '5.8',
		'requires_php'  => '7.4',
		'last_updated'  => $release['published'],
		'sections'      => array(
			'description' => '<p>' . esc_html__( 'Contact form with email notification, database storage, admin-managed event types, spam protection and CSV export.', 'aqm-contact-form' ) . '</p>',
			'changelog'   => $changelog,
		),
	);
}

/**
 * Make sure the unpacked folder keeps the plugin's own name.
 *
 * Without this, a download whose folder is named after the release tag would
 * be installed alongside the existing copy instead of replacing it.
 */
add_filter( 'upgrader_source_selection', 'aqm_cf_correct_source_dir', 10, 4 );

function aqm_cf_correct_source_dir( $source, $remote_source, $upgrader, $hook_extra = null ) {
	global $wp_filesystem;

	if ( empty( $hook_extra['plugin'] ) || plugin_basename( AQM_CF_FILE ) !== $hook_extra['plugin'] ) {
		return $source;
	}
	if ( ! $wp_filesystem ) {
		return $source;
	}

	$desired = trailingslashit( $remote_source ) . dirname( plugin_basename( AQM_CF_FILE ) );

	if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
		return $source;
	}
	if ( $wp_filesystem->move( $source, $desired, true ) ) {
		return trailingslashit( $desired );
	}

	return new WP_Error(
		'aqm_cf_rename_failed',
		__( 'The update was downloaded but its folder could not be renamed. Please install it manually.', 'aqm-contact-form' )
	);
}

/** Drop the cached release info once an update has been installed. */
add_action( 'upgrader_process_complete', 'aqm_cf_clear_release_cache', 10, 0 );

function aqm_cf_clear_release_cache() {
	delete_transient( 'aqm_cf_gh_release' );
}

/** A "Check for updates" link on the Plugins screen. */
add_filter( 'plugin_row_meta', 'aqm_cf_plugin_row_meta', 10, 2 );

function aqm_cf_plugin_row_meta( $links, $file ) {
	if ( plugin_basename( AQM_CF_FILE ) !== $file || ! current_user_can( 'update_plugins' ) ) {
		return $links;
	}

	$links[] = '<a href="' . esc_url(
		wp_nonce_url( admin_url( 'admin-post.php?action=aqm_cf_check_update' ), 'aqm_cf_check_update' )
	) . '">' . esc_html__( 'Check for updates', 'aqm-contact-form' ) . '</a>';

	return $links;
}

add_action( 'admin_post_aqm_cf_check_update', 'aqm_cf_force_update_check' );

function aqm_cf_force_update_check() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		wp_die( esc_html__( 'You do not have permission to check for updates.', 'aqm-contact-form' ) );
	}
	check_admin_referer( 'aqm_cf_check_update' );

	$release = aqm_cf_github_release( true );
	delete_site_transient( 'update_plugins' );

	$status = 'unreachable';
	if ( $release ) {
		$status = version_compare( $release['version'], AQM_CF_VERSION, '>' ) ? 'available' : 'current';
	}

	wp_safe_redirect( add_query_arg( 'aqm_cf_update', $status, admin_url( 'plugins.php' ) ) );
	exit;
}

add_action( 'admin_notices', 'aqm_cf_update_check_notice' );

function aqm_cf_update_check_notice() {
	if ( empty( $_GET['aqm_cf_update'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$status  = sanitize_key( wp_unslash( $_GET['aqm_cf_update'] ) );
	$release = aqm_cf_github_release();

	if ( 'available' === $status && $release ) {
		$class   = 'notice-warning';
		$message = sprintf(
			/* translators: %s: version number */
			__( 'Version %s of the contact form is available. Refresh this page and use the update link on the plugin row.', 'aqm-contact-form' ),
			$release['version']
		);
	} elseif ( 'current' === $status ) {
		$class   = 'notice-success';
		$message = sprintf(
			/* translators: %s: version number */
			__( 'The contact form is up to date (version %s).', 'aqm-contact-form' ),
			AQM_CF_VERSION
		);
	} else {
		$class   = 'notice-error';
		$message = __( 'Could not reach GitHub to check for contact form updates. Please try again shortly.', 'aqm-contact-form' );
	}

	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}

/* ═══════════════════════════════════════════════════════════════════════
   14. UNINSTALL
   ═══════════════════════════════════════════════════════════════════════ */

register_uninstall_hook( __FILE__, 'aqm_cf_uninstall' );

function aqm_cf_uninstall() {
	$settings = (array) get_option( 'aqm_cf_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	global $wpdb;
	$entries = $wpdb->prefix . 'aqm_contact_entries';
	$types   = $wpdb->prefix . 'aqm_event_types';

	$wpdb->query( "DROP TABLE IF EXISTS $entries" ); // phpcs:ignore WordPress.DB.PreparedSQL
	$wpdb->query( "DROP TABLE IF EXISTS $types" );   // phpcs:ignore WordPress.DB.PreparedSQL

	delete_option( 'aqm_cf_settings' );
	delete_option( 'aqm_cf_db_version' );
	delete_option( 'aqm_cf_seeded' );
}
