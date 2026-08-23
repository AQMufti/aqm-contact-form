<?php
/**
 * Plugin Name:       A. Q. Mufti - Contact Form
 * Plugin URI:        https://github.com/AQMufti/aqm-contact-form
 * Description:       Multi-form builder with combobox fields. Each form has independent fields, dropdowns, editable comboboxes, CAPTCHA, spam protection and required/optional settings. Shortcode: [aqm_form id="N"]
 * Version:           7.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            A. Q. Mufti
 * Author URI:        https://github.com/AQMufti
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/AQMufti/aqm-contact-form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AQM_VERSION', '7.1.1' );
define( 'AQM_DB_VERSION', 8 );
define( 'AQM_FILE', __FILE__ );

if ( ! defined( 'AQM_GITHUB_REPO' ) ) {
	define( 'AQM_GITHUB_REPO', 'AQMufti/aqm-contact-form' );
}

/* ══════════════════════════════════════════════════════════════
   1. SCHEMA - install and versioned upgrades

   v7.0.0 ran SHOW COLUMNS on plugins_loaded for EVERY request,
   front end included. That is one guaranteed extra query on every
   page view forever. A stored version number does the same job free.
   ══════════════════════════════════════════════════════════════ */

register_activation_hook( __FILE__, 'aqm_install' );

function aqm_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'aqm_' . $name;
}

add_action( 'plugins_loaded', 'aqm_maybe_upgrade' );

function aqm_maybe_upgrade() {
	if ( (int) get_option( 'aqm_db_version', 0 ) < AQM_DB_VERSION ) {
		aqm_install();
	}
}

function aqm_install() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	$forms   = aqm_table( 'forms' );
	$fields  = aqm_table( 'form_fields' );
	$options = aqm_table( 'field_options' );
	$entries = aqm_table( 'contact_entries' );

	// dbDelta is a schema differ, not a CREATE statement. It needs no
	// IF NOT EXISTS, lowercase types, one field per line, and TWO spaces
	// after PRIMARY KEY. v7.0.0 broke all three, so it could create tables
	// but never correctly upgrade them.
	dbDelta(
		"CREATE TABLE $forms (
			id int(10) unsigned NOT NULL auto_increment,
			form_name varchar(120) NOT NULL,
			notify_email varchar(120) NOT NULL default '',
			notify_cc varchar(255) NOT NULL default '',
			email_subject varchar(200) NOT NULL default 'New Contact Form Submission',
			captcha_enabled tinyint(1) NOT NULL default 1,
			spam_protection tinyint(1) NOT NULL default 1,
			autoreply_enabled tinyint(1) NOT NULL default 0,
			autoreply_subject varchar(200) NOT NULL default 'We received your message',
			autoreply_body text NOT NULL,
			success_message varchar(255) NOT NULL default '',
			store_ip tinyint(1) NOT NULL default 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $charset;"
	);

	dbDelta(
		"CREATE TABLE $fields (
			id int(10) unsigned NOT NULL auto_increment,
			form_id int(10) unsigned NOT NULL,
			field_key varchar(80) NOT NULL,
			label varchar(120) NOT NULL,
			field_type varchar(30) NOT NULL default 'text',
			placeholder varchar(200) NOT NULL default '',
			required tinyint(1) NOT NULL default 1,
			enabled tinyint(1) NOT NULL default 1,
			sort_order int(11) NOT NULL default 0,
			PRIMARY KEY  (id),
			KEY form_id (form_id)
		) $charset;"
	);

	dbDelta(
		"CREATE TABLE $options (
			id int(10) unsigned NOT NULL auto_increment,
			field_id int(10) unsigned NOT NULL,
			label varchar(120) NOT NULL,
			sort_order int(11) NOT NULL default 0,
			PRIMARY KEY  (id),
			KEY field_id (field_id)
		) $charset;"
	);

	dbDelta(
		"CREATE TABLE $entries (
			id bigint(20) unsigned NOT NULL auto_increment,
			form_id int(10) unsigned NOT NULL default 0,
			form_name varchar(120) NOT NULL default '',
			form_data longtext NOT NULL,
			ip_address varchar(45) NOT NULL default '',
			submitted_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY submitted_at (submitted_at)
		) $charset;"
	);

	// Seed only on a genuinely fresh install. add_option() relies on the
	// unique index on option_name, so concurrent requests cannot double-seed.
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $forms" ); // phpcs:ignore
	if ( 0 === $count && add_option( 'aqm_seeded', 1, '', 'no' ) ) {
		aqm_seed_default_form();
	}

	update_option( 'aqm_db_version', AQM_DB_VERSION );
}

/* ══════════════════════════════════════════════════════════════
   2. SEED
   ══════════════════════════════════════════════════════════════ */

function aqm_default_autoreply_body() {
	return "Dear {name},\n\nThank you for getting in touch. We have received your message and will reply shortly.\n\nFor your records, here is what you sent:\n\n{submission}\n\nWarm regards,\n{site_name}";
}

function aqm_seed_default_form( $form_name = 'General Contact Form' ) {
	global $wpdb;

	$wpdb->insert(
		aqm_table( 'forms' ),
		array(
			'form_name'         => $form_name,
			'notify_email'      => get_option( 'admin_email' ),
			'email_subject'     => 'New Contact Form Submission',
			'captcha_enabled'   => 1,
			'spam_protection'   => 1,
			'autoreply_enabled' => 0,
			'autoreply_subject' => 'We received your message',
			'autoreply_body'    => aqm_default_autoreply_body(),
			'success_message'   => 'Thank you{comma_name}! Your message has been received. We will be in touch soon.',
			'store_ip'          => 1,
			'created_at'        => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
	);

	$form_id = (int) $wpdb->insert_id;
	aqm_seed_default_fields( $form_id );

	return $form_id;
}

function aqm_seed_default_fields( $form_id ) {
	global $wpdb;

	$fields = array(
		array( 'name', 'Full Name', 'text', 'Your full name', 1, 1, 1 ),
		array( 'email', 'Email Address', 'email', 'your@email.com', 1, 1, 2 ),
		array( 'phone', 'Telephone', 'tel', '(905) 555-0100', 0, 1, 3 ),
		array( 'event_type', 'Type of Event', 'combobox', '', 1, 1, 4 ),
		array( 'message', 'Message', 'textarea', 'Describe your inquiry...', 1, 1, 5 ),
	);

	foreach ( $fields as $f ) {
		$wpdb->insert(
			aqm_table( 'form_fields' ),
			array(
				'form_id'     => $form_id,
				'field_key'   => aqm_unique_key( $f[0], $form_id ),
				'label'       => $f[1],
				'field_type'  => $f[2],
				'placeholder' => $f[3],
				'required'    => $f[4],
				'enabled'     => $f[5],
				'sort_order'  => $f[6],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);

		if ( in_array( $f[2], array( 'select', 'combobox' ), true ) ) {
			$field_db_id = (int) $wpdb->insert_id;
			$opts        = array(
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
			foreach ( $opts as $i => $opt ) {
				$wpdb->insert(
					aqm_table( 'field_options' ),
					array(
						'field_id'   => $field_db_id,
						'label'      => $opt,
						'sort_order' => $i + 1,
					),
					array( '%d', '%s', '%d' )
				);
			}
		}
	}
}

/* ══════════════════════════════════════════════════════════════
   3. HELPERS
   ══════════════════════════════════════════════════════════════ */

function aqm_unique_key( $base, $form_id ) {
	global $wpdb;

	$key = preg_replace( '/_+/', '_', trim( preg_replace( '/[^a-z0-9_]/', '_', strtolower( $base ) ), '_' ) );
	if ( '' === $key ) {
		$key = 'field';
	}

	$table = aqm_table( 'form_fields' );
	$orig  = $key;
	$n     = 1;

	while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE field_key = %s AND form_id = %d", $key, $form_id ) ) ) { // phpcs:ignore
		$key = $orig . '_' . $n++;
	}

	return $key;
}

function aqm_get_forms() {
	global $wpdb;
	$table = aqm_table( 'forms' );
	return $wpdb->get_results( "SELECT * FROM $table ORDER BY id ASC" ); // phpcs:ignore
}

function aqm_get_form( $form_id ) {
	global $wpdb;
	$table = aqm_table( 'forms' );
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $form_id ) ); // phpcs:ignore
}

function aqm_get_fields( $form_id, $enabled_only = false ) {
	global $wpdb;
	$table = aqm_table( 'form_fields' );

	if ( $enabled_only ) {
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE form_id = %d AND enabled = 1 ORDER BY sort_order ASC, id ASC", $form_id ) ); // phpcs:ignore
	}
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE form_id = %d ORDER BY sort_order ASC, id ASC", $form_id ) ); // phpcs:ignore
}

function aqm_get_options( $field_id ) {
	global $wpdb;
	$table = aqm_table( 'field_options' );
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE field_id = %d ORDER BY sort_order ASC, id ASC", $field_id ) ); // phpcs:ignore
}

function aqm_validate_email( $email ) {
	$email = trim( $email );
	if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
		return false;
	}
	$parts  = explode( '@', $email, 2 );
	$domain = $parts[1] ?? '';
	if ( false === strpos( $domain, '.' ) ) {
		return false;
	}
	if ( false !== strpos( $email, '..' ) ) {
		return false;
	}
	foreach ( array( '.invalid', '.test', '.example', '.localhost' ) as $tld ) {
		if ( substr( strtolower( $domain ), -strlen( $tld ) ) === $tld ) {
			return false;
		}
	}
	return true;
}

/* ---- CAPTCHA: signed, stateless, no session. Kept from 7.0.0 ---- */

function aqm_captcha_secret() {
	return hash( 'sha256', wp_salt( 'auth' ) . 'aqm_captcha_v6' );
}

function aqm_captcha_fields( $form_id ) {
	$a   = wp_rand( 2, 9 );
	$b   = wp_rand( 1, 9 );
	$ans = $a + $b;
	$ts  = time();

	return array(
		'question' => "What is $a + $b ?",
		'token'    => hash_hmac( 'sha256', $ans . '|' . $ts . '|' . $form_id, aqm_captcha_secret() ),
		'ts'       => $ts,
	);
}

function aqm_verify_captcha( $answer, $form_id, $token, $ts ) {
	$ts = (int) $ts;
	if ( abs( time() - $ts ) > 1800 ) {
		return false;
	}
	$expected = hash_hmac( 'sha256', (int) $answer . '|' . $ts . '|' . $form_id, aqm_captcha_secret() );
	return hash_equals( $expected, (string) $token );
}

/* ---- Signed timestamp, for the silent timing trap ---- */

function aqm_timestamp_token() {
	$now = time();
	return $now . '.' . wp_hash( 'aqm_ts_' . $now );
}

function aqm_timestamp_age( $token ) {
	$parts = explode( '.', (string) $token, 2 );
	if ( 2 !== count( $parts ) ) {
		return false;
	}
	$time = (int) $parts[0];
	if ( ! hash_equals( wp_hash( 'aqm_ts_' . $time ), $parts[1] ) ) {
		return false;
	}
	return time() - $time;
}

/* ---- Client IP and rate limiting ---- */

function aqm_proxy_headers() {
	return array(
		''                      => 'None - the server sees visitors directly',
		'HTTP_CF_CONNECTING_IP' => 'Cloudflare',
		'HTTP_X_FORWARDED_FOR'  => 'Standard proxy / load balancer (X-Forwarded-For)',
		'HTTP_X_REAL_IP'        => 'Nginx proxy (X-Real-IP)',
		'HTTP_TRUE_CLIENT_IP'   => 'Akamai / Cloudflare Enterprise (True-Client-IP)',
	);
}

function aqm_get_client_ip() {
	$ip     = $_SERVER['REMOTE_ADDR'] ?? '';
	$header = (string) get_option( 'aqm_proxy_header', '' );
	$header = apply_filters( 'aqm_trusted_proxy_header', $header );

	if ( $header && ! empty( $_SERVER[ $header ] ) ) {
		$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
		$ip    = trim( $parts[0] );
	}

	$ip = filter_var( $ip, FILTER_VALIDATE_IP );
	return $ip ? $ip : '';
}

function aqm_rate_limit_key() {
	$ip = aqm_get_client_ip();
	// No usable IP means no rate limiting, rather than throwing every
	// anonymous visitor into one shared bucket and throttling them together.
	return $ip ? 'aqm_rl_' . md5( $ip ) : '';
}

function aqm_rate_limit_exceeded() {
	$limit = (int) get_option( 'aqm_rate_limit', 5 );
	$key   = aqm_rate_limit_key();
	if ( $limit < 1 || '' === $key ) {
		return false;
	}
	return (int) get_transient( $key ) >= $limit;
}

function aqm_record_submission() {
	$key = aqm_rate_limit_key();
	if ( '' === $key ) {
		return;
	}
	set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
}

/* ---- Flash messages, for Post/Redirect/Get ---- */

/**
 * Current front-end URL.
 *
 * wp_get_referer() cannot be used: it deliberately returns false when the
 * referer matches the request URI, which is exactly the case for a form
 * posting back to its own page.
 */
function aqm_current_url() {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

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

	if ( ! $host || ! in_array( $normalise( $host ), $known, true ) ) {
		return home_url( '/' );
	}

	return set_url_scheme( 'http://' . $host . $uri );
}

/**
 * Store the outcome and redirect. Never returns.
 */
function aqm_flash_and_redirect( array $payload ) {
	// Lowercase hex only: the token is read back through sanitize_key(),
	// which lowercases, so a mixed-case token would never match.
	$token = bin2hex( wp_generate_password( 10, false, false ) );
	set_transient( 'aqm_flash_' . $token, $payload, 5 * MINUTE_IN_SECONDS );

	$url    = remove_query_arg( 'aqm_sent', aqm_current_url() );
	$url    = add_query_arg( 'aqm_sent', $token, $url );
	$anchor = isset( $payload['form_id'] ) ? '#aqm-form-' . (int) $payload['form_id'] : '';

	wp_safe_redirect( $url . $anchor );
	exit;
}

function aqm_get_flash() {
	static $flash = null;
	if ( null !== $flash ) {
		return $flash;
	}

	$flash = array();
	if ( ! empty( $_GET['aqm_sent'] ) ) {
		$token   = sanitize_key( wp_unslash( $_GET['aqm_sent'] ) );
		$payload = get_transient( 'aqm_flash_' . $token );
		if ( is_array( $payload ) ) {
			$flash = $payload;
			delete_transient( 'aqm_flash_' . $token );
		}
	}
	return $flash;
}

/* ══════════════════════════════════════════════════════════════
   4. FRONT-END SUBMISSION

   Handled on template_redirect, before any output, so we can
   redirect afterwards. v7.0.0 processed inside the shortcode,
   which meant a browser refresh re-filed the whole submission.
   ══════════════════════════════════════════════════════════════ */

add_action( 'template_redirect', 'aqm_handle_submission' );

function aqm_handle_submission() {
	if ( empty( $_POST['aqm_form_id'] ) ) {
		return;
	}

	global $wpdb;

	$form_id = (int) $_POST['aqm_form_id'];
	$form    = aqm_get_form( $form_id );
	if ( ! $form ) {
		return;
	}

	$fields = aqm_get_fields( $form_id, true );

	/* ---- Collect, unslashing first ----------------------------------
	   WordPress adds slashes to every superglobal. Without wp_unslash()
	   a visitor called O'Brien is stored as O\'Brien.                  */
	$values = array();
	foreach ( $fields as $f ) {
		$name = 'aqm_f' . $form_id . '_' . $f->field_key;
		$raw  = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';

		if ( 'textarea' === $f->field_type ) {
			$values[ $f->field_key ] = sanitize_textarea_field( $raw );
		} elseif ( 'email' === $f->field_type ) {
			$values[ $f->field_key ] = sanitize_email( $raw );
		} else {
			$values[ $f->field_key ] = sanitize_text_field( $raw );
		}
	}

	$errors = array();

	/* ---- Nonce ---- */
	$nonce = isset( $_POST[ 'aqm_nonce_' . $form_id ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'aqm_nonce_' . $form_id ] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'aqm_submit_form_' . $form_id ) ) {
		aqm_flash_and_redirect(
			array(
				'form_id' => $form_id,
				'status'  => 'error',
				'general' => 'Your session expired before the form was sent. Your details are still below - please press Send Message once more.',
				'values'  => $values,
			)
		);
	}

	/* ---- Silent spam traps ---- */
	if ( $form->spam_protection ) {
		$honeypot = isset( $_POST['aqm_website'] ) ? trim( (string) wp_unslash( $_POST['aqm_website'] ) ) : '';
		$age      = aqm_timestamp_age( isset( $_POST['aqm_ts'] ) ? wp_unslash( $_POST['aqm_ts'] ) : '' );

		// Only a filled honeypot or a missing/forged timestamp is certain
		// enough to discard silently. Bots see "success" so they do not adapt.
		if ( '' !== $honeypot || false === $age || $age < 3 ) {
			aqm_flash_and_redirect(
				array(
					'form_id' => $form_id,
					'status'  => 'success',
					'general' => aqm_success_text( $form, $values ),
				)
			);
		}

		// A stale page is not a bot - ask rather than discard.
		if ( $age > DAY_IN_SECONDS ) {
			aqm_flash_and_redirect(
				array(
					'form_id' => $form_id,
					'status'  => 'error',
					'general' => 'This page had been open for a while. Your details are still below - please press Send Message once more.',
					'values'  => $values,
				)
			);
		}
	}

	if ( aqm_rate_limit_exceeded() ) {
		aqm_flash_and_redirect(
			array(
				'form_id' => $form_id,
				'status'  => 'error',
				'general' => 'You have sent several messages already. Please wait an hour before sending another, or telephone us directly.',
				'values'  => $values,
			)
		);
	}

	/* ---- CAPTCHA ---- */
	if ( $form->captcha_enabled ) {
		$answer = isset( $_POST[ 'aqm_captcha_' . $form_id ] ) ? trim( (string) wp_unslash( $_POST[ 'aqm_captcha_' . $form_id ] ) ) : '';
		$token  = isset( $_POST[ 'aqm_captcha_token_' . $form_id ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'aqm_captcha_token_' . $form_id ] ) ) : '';
		$ts     = isset( $_POST[ 'aqm_captcha_ts_' . $form_id ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'aqm_captcha_ts_' . $form_id ] ) ) : '';

		if ( ! aqm_verify_captcha( $answer, $form_id, $token, $ts ) ) {
			$errors['__captcha'] = 'That answer was not correct. Please try the sum again.';
		}
	}

	/* ---- Per-field validation ----------------------------------------
	   v7.0.0 stopped at the first problem and showed one generic message.
	   Every field is now checked, and each reports its own error.      */
	$stored = array();

	foreach ( $fields as $f ) {
		$key = $f->field_key;
		$val = $values[ $key ];

		if ( $f->required && '' === trim( (string) $val ) ) {
			$errors[ $key ] = 'Please complete "' . $f->label . '".';
			continue;
		}

		if ( 'email' === $f->field_type && '' !== trim( (string) $val ) && ! aqm_validate_email( $val ) ) {
			$errors[ $key ] = 'That email address does not look right - please check it.';
			continue;
		}

		if ( 'url' === $f->field_type && '' !== trim( (string) $val ) ) {
			$val = esc_url_raw( $val );
			if ( '' === $val ) {
				$errors[ $key ] = 'Please enter a valid web address, starting with http:// or https://';
				continue;
			}
		}

		if ( 'number' === $f->field_type && '' !== trim( (string) $val ) && ! is_numeric( $val ) ) {
			$errors[ $key ] = 'Please enter a number.';
			continue;
		}

		// A dropdown submits an option ID; resolve it to its label so a
		// tampered value cannot store arbitrary text.
		if ( 'select' === $f->field_type && '' !== $val ) {
			$table = aqm_table( 'field_options' );
			$opt   = $wpdb->get_row( $wpdb->prepare( "SELECT label FROM $table WHERE id = %d AND field_id = %d", (int) $val, $f->id ) ); // phpcs:ignore
			$val   = $opt ? $opt->label : '';
			if ( '' === $val ) {
				$errors[ $key ] = 'Please choose an option for "' . $f->label . '".';
				continue;
			}
		}

		if ( mb_strlen( (string) $val ) > 5000 ) {
			$errors[ $key ] = 'That is longer than 5,000 characters. Please shorten it a little.';
			continue;
		}

		$stored[ $key ] = $val;
	}

	if ( $errors ) {
		aqm_flash_and_redirect(
			array(
				'form_id' => $form_id,
				'status'  => 'error',
				'general' => 'Please correct the highlighted fields and send the form again.',
				'errors'  => $errors,
				'values'  => $values,
			)
		);
	}

	/* ---- Store ---- */
	$ip = $form->store_ip ? aqm_get_client_ip() : '';

	$inserted = $wpdb->insert(
		aqm_table( 'contact_entries' ),
		array(
			'form_id'      => $form_id,
			'form_name'    => $form->form_name,
			'form_data'    => wp_json_encode( $stored, JSON_UNESCAPED_UNICODE ),
			'ip_address'   => $ip,
			'submitted_at' => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		error_log( 'AQM Contact Form: failed to store entry - ' . $wpdb->last_error );
		aqm_flash_and_redirect(
			array(
				'form_id' => $form_id,
				'status'  => 'error',
				'general' => 'Something went wrong while saving your message. Please try again, or email us directly.',
				'values'  => $values,
			)
		);
	}

	$entry_id = (int) $wpdb->insert_id;
	aqm_record_submission();

	aqm_send_notification( $form, $fields, $stored, $entry_id );

	if ( $form->autoreply_enabled ) {
		aqm_send_autoreply( $form, $fields, $stored );
	}

	do_action( 'aqm_entry_saved', $entry_id, $form, $stored );

	aqm_flash_and_redirect(
		array(
			'form_id' => $form_id,
			'status'  => 'success',
			'general' => aqm_success_text( $form, $stored ),
		)
	);
}

/**
 * Best guess at the submitter's name, for greetings and Reply-To.
 */
function aqm_guess_name( array $data ) {
	foreach ( array( 'name', 'full_name', 'your_name', 'first_name' ) as $key ) {
		if ( ! empty( $data[ $key ] ) ) {
			return trim( str_replace( array( '<', '>', '"' ), '', (string) $data[ $key ] ) );
		}
	}
	return '';
}

function aqm_guess_email( array $data ) {
	foreach ( $data as $key => $value ) {
		if ( false !== strpos( $key, 'email' ) && is_email( (string) $value ) ) {
			return (string) $value;
		}
	}
	return '';
}

function aqm_success_text( $form, array $data ) {
	$name     = aqm_guess_name( $data );
	$template = $form->success_message ? $form->success_message : 'Thank you{comma_name}! Your message has been received. We will be in touch soon.';

	return str_replace(
		array( '{comma_name}', '{name}' ),
		array( $name ? ', ' . $name : '', $name ),
		$template
	);
}

/* ══════════════════════════════════════════════════════════════
   5. EMAIL
   ══════════════════════════════════════════════════════════════ */

function aqm_mail_from_address() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$host = preg_replace( '/^www\./i', '', (string) $host );
	return apply_filters( 'aqm_from_address', 'no-reply@' . $host );
}

function aqm_format_submission( $fields, array $data ) {
	$lines = array();
	foreach ( $fields as $f ) {
		$value   = $data[ $f->field_key ] ?? '';
		$lines[] = $f->label . ': ' . ( '' === $value ? 'Not provided' : $value );
	}
	return implode( "\n", $lines );
}

function aqm_send_notification( $form, $fields, array $data, $entry_id ) {
	$to = $form->notify_email ? $form->notify_email : get_option( 'admin_email' );
	if ( ! is_email( $to ) ) {
		return;
	}

	$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$headers   = array(
		'Content-Type: text/plain; charset=UTF-8',
		'From: ' . $site_name . ' <' . aqm_mail_from_address() . '>',
	);

	// sanitize_text_field already strips newlines, so header injection is not
	// possible; angle brackets are removed so the header stays well formed.
	$name  = aqm_guess_name( $data );
	$email = aqm_guess_email( $data );
	if ( $email ) {
		$headers[] = 'Reply-To: ' . ( $name ? $name . ' <' . $email . '>' : $email );
	}

	if ( ! empty( $form->notify_cc ) ) {
		$headers[] = 'Cc: ' . $form->notify_cc;
	}

	$body = 'Submission from: ' . $form->form_name . "\n\n"
		. aqm_format_submission( $fields, $data )
		. "\n\nSubmitted: " . current_time( 'mysql' )
		. "\nView in dashboard: " . admin_url( 'admin.php?page=aqm-submissions&form_id=' . (int) $form->id ) . "\n";

	$subject = $form->email_subject ? $form->email_subject : 'New Contact Form Submission';
	$sent    = wp_mail( $to, $subject, $body, $headers );

	// The entry is safely stored either way, but a silently failing mailer
	// is the commonest way enquiries get missed entirely.
	if ( ! $sent ) {
		error_log( 'AQM Contact Form: wp_mail() failed for entry #' . $entry_id );
		set_transient( 'aqm_mail_failure', (int) $entry_id, WEEK_IN_SECONDS );
	}
}

function aqm_send_autoreply( $form, $fields, array $data ) {
	$to = aqm_guess_email( $data );
	if ( ! $to ) {
		return;
	}

	$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$vars      = array(
		'{name}'       => aqm_guess_name( $data ),
		'{form_name}'  => $form->form_name,
		'{submission}' => aqm_format_submission( $fields, $data ),
		'{site_name}'  => $site_name,
	);

	$headers = array(
		'Content-Type: text/plain; charset=UTF-8',
		'From: ' . $site_name . ' <' . aqm_mail_from_address() . '>',
	);
	if ( is_email( $form->notify_email ) ) {
		$headers[] = 'Reply-To: ' . $form->notify_email;
	}

	wp_mail(
		$to,
		strtr( $form->autoreply_subject ? $form->autoreply_subject : 'We received your message', $vars ),
		strtr( $form->autoreply_body ? $form->autoreply_body : aqm_default_autoreply_body(), $vars ),
		$headers
	);
}

/* ══════════════════════════════════════════════════════════════
   6. FRONT-END ASSETS
   ══════════════════════════════════════════════════════════════ */

add_action( 'wp_enqueue_scripts', 'aqm_register_assets' );

function aqm_register_assets() {
	wp_register_style( 'aqm-form', false, array(), AQM_VERSION );
	wp_add_inline_style( 'aqm-form', aqm_styles() );

	$post = get_post();
	if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'aqm_form' ) ) {
		wp_enqueue_style( 'aqm-form' );
		aqm_mark_styles_done();
		aqm_no_cache();
	}
}

/**
 * The form carries a nonce, a CAPTCHA token and a signed timestamp, all of
 * which go stale inside a full-page cache. A cached copy would hand every
 * visitor an expired token and the form would refuse every submission.
 */
function aqm_no_cache() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	if ( ! headers_sent() ) {
		nocache_headers();
	}
}

function aqm_mark_styles_done( $set = true ) {
	static $done = false;
	if ( $set ) {
		$done = true;
	}
	return $done;
}

/**
 * v7.0.0 printed its CSS inside every shortcode render, so two forms on one
 * page shipped the stylesheet twice. This prints it once, wherever the first
 * form appears, and enqueues it properly when we have not missed the head.
 */
function aqm_style_fallback() {
	if ( aqm_mark_styles_done( false ) ) {
		return '';
	}
	aqm_mark_styles_done();
	return '<style id="aqm-form-fallback-css">' . aqm_styles() . '</style>';
}

function aqm_styles() {
	return <<<CSS
.aqm-form-wrap{--aqm-primary:#1a4b6e;--aqm-accent:#9a6c1f;--aqm-border:#d0c9bd;--aqm-text:#2c2c2c;--aqm-error:#c0392b;max-width:780px;margin:0 auto;font-family:Georgia,'Times New Roman',serif;color:var(--aqm-text)}
.aqm-form-wrap .aqm-alert{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:6px;margin-bottom:22px;font-size:15px;line-height:1.5}
.aqm-form-wrap .aqm-alert--success{background:#eaf5ec;border-left:4px solid #3a8a4a;color:#1e5c29}
.aqm-form-wrap .aqm-alert--error{background:#fdf0f0;border-left:4px solid var(--aqm-error);color:#7b1c1c}
.aqm-form-wrap .aqm-icon{font-weight:700;font-size:17px;flex-shrink:0}
.aqm-form-wrap .aqm-row{display:flex;gap:20px}
.aqm-form-wrap .aqm-row--half>*{flex:1 1 0;min-width:0}
@media(max-width:600px){.aqm-form-wrap .aqm-row{flex-direction:column;gap:0}}
.aqm-form-wrap .aqm-group{margin-bottom:18px}
.aqm-form-wrap .aqm-group>label{display:block;font-size:12px;font-weight:700;letter-spacing:.5px;margin-bottom:6px;color:var(--aqm-primary);text-transform:uppercase;font-family:Arial,sans-serif}
.aqm-form-wrap .aqm-opt{font-weight:400;text-transform:none;color:#767676;font-size:11px;letter-spacing:0}
.aqm-form-wrap .aqm-req{color:var(--aqm-accent);font-size:14px}
.aqm-form-wrap .aqm-hint{display:block;font-size:12px;color:#767676;margin-top:4px;font-family:Arial,sans-serif;font-style:italic}
.aqm-form-wrap .aqm-field-error{display:block;margin-top:6px;font-size:13px;color:#8c2f22;font-family:Arial,sans-serif}
.aqm-form-wrap input:not([type=checkbox]),.aqm-form-wrap select,.aqm-form-wrap textarea{width:100%;box-sizing:border-box;padding:10px 13px;border:1px solid var(--aqm-border);border-radius:6px;background:#fff;font-size:15px;font-family:Georgia,serif;color:var(--aqm-text);transition:border-color .2s,box-shadow .2s;box-shadow:0 2px 6px rgba(0,0,0,.06)}
.aqm-form-wrap input:focus,.aqm-form-wrap select:focus,.aqm-form-wrap textarea:focus{outline:2px solid transparent;border-color:var(--aqm-primary);box-shadow:0 0 0 3px rgba(26,75,110,.35)}
.aqm-form-wrap .aqm-group--invalid input,.aqm-form-wrap .aqm-group--invalid select,.aqm-form-wrap .aqm-group--invalid textarea{border-color:var(--aqm-error);box-shadow:0 0 0 3px rgba(192,57,43,.12)}
.aqm-form-wrap select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%231a4b6e' d='M6 8L0 0h12z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 13px center;padding-right:34px}
.aqm-form-wrap textarea{resize:vertical;min-height:120px}
.aqm-form-wrap .aqm-combobox-wrap{position:relative;width:100%}
.aqm-form-wrap .aqm-combobox-wrap input{width:100%;padding-right:38px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath fill='%231a4b6e' d='M7 9L0 0h14z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 13px center;cursor:text}
.aqm-form-wrap .aqm-combobox-hint{display:block;font-size:11px;color:#767676;margin-top:4px;font-family:Arial,sans-serif;font-style:italic}
.aqm-form-wrap .aqm-chk{display:flex;align-items:center;gap:10px;cursor:pointer;font-size:15px;font-family:Georgia,serif;text-transform:none;letter-spacing:0;font-weight:400;color:var(--aqm-text)}
.aqm-form-wrap .aqm-chk input{width:17px;height:17px;flex-shrink:0;cursor:pointer}
.aqm-form-wrap .aqm-captcha-group{background:#f8f6f0;border:1px solid var(--aqm-border);border-radius:6px;padding:14px 16px;width:100%}
.aqm-form-wrap .aqm-captcha-inner{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:2px}
.aqm-form-wrap .aqm-captcha-q{background:#fff;border:1px solid var(--aqm-border);border-radius:6px;padding:9px 14px;font-size:16px;font-weight:700;color:var(--aqm-primary);font-family:Georgia,serif}
.aqm-form-wrap .aqm-captcha-inner input{max-width:160px!important;width:160px!important;font-size:16px;text-align:center;font-weight:700}
.aqm-hp{position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important;overflow:hidden!important}
.aqm-form-wrap .aqm-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:8px;padding-top:14px;border-top:1px solid #eee}
.aqm-form-wrap .aqm-note{font-size:12px;color:#767676;margin:0;font-family:Arial,sans-serif}
.aqm-form-wrap .aqm-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 34px;background:var(--aqm-primary);color:#fff;border:none;border-radius:6px;font-size:15px;font-family:Arial,sans-serif;font-weight:600;letter-spacing:.4px;cursor:pointer;transition:background .18s,transform .1s;box-shadow:0 3px 10px rgba(26,75,110,.22)}
.aqm-form-wrap .aqm-btn:hover{background:#163d5a}
.aqm-form-wrap .aqm-btn:active{transform:translateY(1px)}
.aqm-form-wrap .aqm-btn:focus-visible{outline:3px solid #c8923a;outline-offset:2px}
@media(prefers-reduced-motion:reduce){.aqm-form-wrap input,.aqm-form-wrap select,.aqm-form-wrap textarea,.aqm-form-wrap .aqm-btn{transition:none}}
CSS;
}

/* ══════════════════════════════════════════════════════════════
   7. SHORTCODE  [aqm_form id="N"]
   ══════════════════════════════════════════════════════════════ */

add_shortcode( 'aqm_form', 'aqm_render_form' );

function aqm_render_form( $atts ) {
	$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'aqm_form' );
	$form_id = (int) $atts['id'];
	$form    = $form_id ? aqm_get_form( $form_id ) : null;

	if ( ! $form ) {
		// Visitors should never see internal diagnostics.
		if ( current_user_can( 'manage_options' ) ) {
			return '<p style="color:#c0392b;font-style:italic">AQM Contact Form: invalid form ID. Use <code>[aqm_form id="N"]</code>.</p>';
		}
		return '';
	}

	aqm_no_cache();

	if ( ! did_action( 'wp_head' ) ) {
		wp_enqueue_style( 'aqm-form' );
		aqm_mark_styles_done();
	}

	$fields = aqm_get_fields( $form_id, true );

	$flash  = aqm_get_flash();
	$mine   = ( ! empty( $flash ) && (int) ( $flash['form_id'] ?? 0 ) === $form_id );
	$status = $mine ? ( $flash['status'] ?? '' ) : '';
	$errors = ( $mine && isset( $flash['errors'] ) && is_array( $flash['errors'] ) ) ? $flash['errors'] : array();
	$values = ( $mine && isset( $flash['values'] ) && is_array( $flash['values'] ) ) ? $flash['values'] : array();

	$captcha = $form->captcha_enabled ? aqm_captcha_fields( $form_id ) : null;

	ob_start();
	?>
	<div class="aqm-form-wrap" id="aqm-form-<?php echo esc_attr( $form_id ); ?>">

		<div role="status" aria-live="polite">
			<?php if ( 'success' === $status ) : ?>
				<div class="aqm-alert aqm-alert--success" tabindex="-1" id="aqm-alert-<?php echo esc_attr( $form_id ); ?>">
					<span class="aqm-icon" aria-hidden="true">&#10003;</span>
					<span><?php echo esc_html( $flash['general'] ?? '' ); ?></span>
				</div>
			<?php elseif ( 'error' === $status ) : ?>
				<div class="aqm-alert aqm-alert--error" tabindex="-1" id="aqm-alert-<?php echo esc_attr( $form_id ); ?>">
					<span class="aqm-icon" aria-hidden="true">!</span>
					<span><?php echo esc_html( $flash['general'] ?? '' ); ?></span>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( 'success' === $status ) : ?>
			<p class="aqm-note">If you need to send another message, please refresh this page.</p>
		<?php elseif ( ! $fields ) : ?>
			<div class="aqm-alert aqm-alert--error">
				<span class="aqm-icon" aria-hidden="true">!</span>
				<span>This form is not available at the moment. Please contact us by telephone or email.</span>
			</div>
		<?php else : ?>

		<form class="aqm-form" method="post" action="">
			<?php wp_nonce_field( 'aqm_submit_form_' . $form_id, 'aqm_nonce_' . $form_id ); ?>
			<input type="hidden" name="aqm_form_id" value="<?php echo esc_attr( $form_id ); ?>">
			<input type="hidden" name="aqm_ts" value="<?php echo esc_attr( aqm_timestamp_token() ); ?>">

			<?php if ( $form->spam_protection ) : ?>
				<div class="aqm-hp" aria-hidden="true">
					<label for="aqm_website_<?php echo esc_attr( $form_id ); ?>">Leave this field empty</label>
					<input type="text" id="aqm_website_<?php echo esc_attr( $form_id ); ?>" name="aqm_website" value="" tabindex="-1" autocomplete="off">
				</div>
			<?php endif; ?>

			<?php
			// Pair narrow fields two per row; wide ones take the full width.
			$pairs = array();
			$i     = 0;
			$count = count( $fields );
			$wide  = array( 'textarea', 'checkbox', 'combobox' );

			while ( $i < $count ) {
				$a = $fields[ $i ];
				if ( in_array( $a->field_type, $wide, true ) ) {
					$pairs[] = array( $a );
					$i++;
					continue;
				}
				$b = $fields[ $i + 1 ] ?? null;
				if ( $b && ! in_array( $b->field_type, $wide, true ) ) {
					$pairs[] = array( $a, $b );
					$i      += 2;
				} else {
					$pairs[] = array( $a );
					$i++;
				}
			}

			foreach ( $pairs as $pair ) :
				$full = ( 1 === count( $pair ) );
				?>
				<div class="aqm-row <?php echo $full ? '' : 'aqm-row--half'; ?>">
				<?php
				foreach ( $pair as $f ) :
					$name     = 'aqm_f' . $form_id . '_' . $f->field_key;
					$id       = 'aqmf' . $form_id . '_' . $f->field_key;
					$value    = (string) ( $values[ $f->field_key ] ?? '' );
					$invalid  = isset( $errors[ $f->field_key ] );
					$err_id   = $id . '_error';
					$describe = $invalid ? ' aria-invalid="true" aria-describedby="' . esc_attr( $err_id ) . '"' : '';
					$required = $f->required ? ' required aria-required="true"' : '';
					$auto     = aqm_autocomplete_for( $f );
					?>
					<div class="aqm-group<?php echo $invalid ? ' aqm-group--invalid' : ''; ?>">
						<label for="<?php echo esc_attr( $id ); ?>">
							<?php echo esc_html( $f->label ); ?>
							<?php if ( $f->required ) : ?>
								<span class="aqm-req" aria-hidden="true">*</span>
							<?php else : ?>
								<span class="aqm-opt">(optional)</span>
							<?php endif; ?>
						</label>

						<?php if ( 'textarea' === $f->field_type ) : ?>
							<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
								rows="5" maxlength="5000"
								placeholder="<?php echo esc_attr( $f->placeholder ); ?>"
								<?php echo $required . $describe; // phpcs:ignore ?>><?php echo esc_textarea( $value ); ?></textarea>

						<?php elseif ( 'select' === $f->field_type ) : ?>
							<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
								<?php echo $required . $describe; // phpcs:ignore ?>>
								<option value="">- Please select -</option>
								<?php foreach ( aqm_get_options( $f->id ) as $opt ) : ?>
									<option value="<?php echo esc_attr( $opt->id ); ?>" <?php selected( $value, $opt->label ); ?>>
										<?php echo esc_html( $opt->label ); ?>
									</option>
								<?php endforeach; ?>
							</select>

						<?php elseif ( 'combobox' === $f->field_type ) : ?>
							<?php $list_id = 'aqm_dl_' . $form_id . '_' . $f->field_key; ?>
							<div class="aqm-combobox-wrap">
								<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
									list="<?php echo esc_attr( $list_id ); ?>" maxlength="200"
									placeholder="<?php echo esc_attr( $f->placeholder ? $f->placeholder : 'Type or select from list...' ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									autocomplete="off"
									<?php echo $required . $describe; // phpcs:ignore ?>>
								<datalist id="<?php echo esc_attr( $list_id ); ?>">
									<?php foreach ( aqm_get_options( $f->id ) as $opt ) : ?>
										<option value="<?php echo esc_attr( $opt->label ); ?>"></option>
									<?php endforeach; ?>
								</datalist>
								<span class="aqm-combobox-hint">Choose from the list or type your own value</span>
							</div>

						<?php elseif ( 'checkbox' === $f->field_type ) : ?>
							<label class="aqm-chk">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="Yes"
									<?php checked( 'Yes', $value ); ?>
									<?php echo $required . $describe; // phpcs:ignore ?>>
								<?php echo esc_html( $f->placeholder ? $f->placeholder : $f->label ); ?>
							</label>

						<?php else : ?>
							<input type="<?php echo esc_attr( $f->field_type ); ?>"
								id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
								maxlength="200"
								placeholder="<?php echo esc_attr( $f->placeholder ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
								<?php echo $auto ? 'autocomplete="' . esc_attr( $auto ) . '"' : ''; ?>
								<?php echo $required . $describe; // phpcs:ignore ?>>
							<?php if ( 'email' === $f->field_type && ! $invalid ) : ?>
								<span class="aqm-hint">We will never share your email address.</span>
							<?php endif; ?>
						<?php endif; ?>

						<?php if ( $invalid ) : ?>
							<span class="aqm-field-error" id="<?php echo esc_attr( $err_id ); ?>"><?php echo esc_html( $errors[ $f->field_key ] ); ?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( $captcha ) : ?>
				<?php $cap_invalid = isset( $errors['__captcha'] ); ?>
				<div class="aqm-row">
					<div class="aqm-group aqm-captcha-group<?php echo $cap_invalid ? ' aqm-group--invalid' : ''; ?>">
						<label for="aqm_captcha_<?php echo esc_attr( $form_id ); ?>">Security Check <span class="aqm-req" aria-hidden="true">*</span></label>
						<div class="aqm-captcha-inner">
							<div class="aqm-captcha-q" aria-hidden="true"><?php echo esc_html( $captcha['question'] ); ?></div>
							<input type="number" id="aqm_captcha_<?php echo esc_attr( $form_id ); ?>"
								name="aqm_captcha_<?php echo esc_attr( $form_id ); ?>"
								aria-label="<?php echo esc_attr( $captcha['question'] ); ?>"
								placeholder="Answer" min="0" max="99" autocomplete="off" required aria-required="true"
								<?php echo $cap_invalid ? 'aria-invalid="true" aria-describedby="aqm_captcha_err_' . esc_attr( $form_id ) . '"' : ''; ?>>
						</div>
						<input type="hidden" name="aqm_captcha_token_<?php echo esc_attr( $form_id ); ?>" value="<?php echo esc_attr( $captcha['token'] ); ?>">
						<input type="hidden" name="aqm_captcha_ts_<?php echo esc_attr( $form_id ); ?>" value="<?php echo esc_attr( $captcha['ts'] ); ?>">
						<?php if ( $cap_invalid ) : ?>
							<span class="aqm-field-error" id="aqm_captcha_err_<?php echo esc_attr( $form_id ); ?>"><?php echo esc_html( $errors['__captcha'] ); ?></span>
						<?php else : ?>
							<span class="aqm-hint">Solve the simple sum to show you are not a robot.</span>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="aqm-footer">
				<p class="aqm-note"><span class="aqm-req" aria-hidden="true">*</span> Required fields</p>
				<button type="submit" class="aqm-btn">Send Message</button>
			</div>
		</form>
		<?php endif; ?>
	</div>

	<?php if ( $status ) : ?>
	<script>
	(function () {
		var box = document.getElementById('aqm-alert-<?php echo (int) $form_id; ?>');
		if (!box) { return; }
		box.focus();
		var wrap = document.getElementById('aqm-form-<?php echo (int) $form_id; ?>');
		var bad = wrap && wrap.querySelector('.aqm-group--invalid input, .aqm-group--invalid select, .aqm-group--invalid textarea');
		if (bad) { bad.focus({ preventScroll: true }); }
	})();
	</script>
	<?php endif; ?>
	<?php
	return aqm_style_fallback() . ob_get_clean();
}

function aqm_autocomplete_for( $field ) {
	$map = array(
		'email' => 'email',
		'tel'   => 'tel',
		'url'   => 'url',
	);
	if ( isset( $map[ $field->field_type ] ) ) {
		return $map[ $field->field_type ];
	}
	if ( false !== strpos( $field->field_key, 'name' ) ) {
		return 'name';
	}
	return '';
}

/* ══════════════════════════════════════════════════════════════
   8. ADMIN - menu, assets, notices
   ══════════════════════════════════════════════════════════════ */

add_action( 'admin_menu', 'aqm_admin_menu' );

function aqm_admin_menu() {
	add_menu_page( 'AQM Contact Forms', 'AQM Contact', 'manage_options', 'aqm-forms', 'aqm_admin_forms_page', 'dashicons-feedback', 30 );
	add_submenu_page( 'aqm-forms', 'All Forms', 'All Forms', 'manage_options', 'aqm-forms', 'aqm_admin_forms_page' );
	add_submenu_page( 'aqm-forms', 'Form Builder', 'Form Builder', 'manage_options', 'aqm-form-builder', 'aqm_admin_builder_page' );
	add_submenu_page( 'aqm-forms', 'Submissions', 'Submissions', 'manage_options', 'aqm-submissions', 'aqm_admin_submissions_page' );
	add_submenu_page( 'aqm-forms', 'Global Settings', 'Settings', 'manage_options', 'aqm-settings', 'aqm_admin_settings_page' );
}

add_action( 'admin_notices', 'aqm_mail_failure_notice' );

function aqm_mail_failure_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! get_transient( 'aqm_mail_failure' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>AQM Contact Form:</strong> WordPress could not send the notification email for a recent submission. The enquiry was saved - please check Submissions, and consider installing an SMTP plugin. ';
	echo '<a href="' . esc_url( admin_url( 'admin.php?page=aqm-submissions' ) ) . '">View submissions</a></p></div>';
	delete_transient( 'aqm_mail_failure' );
}

/**
 * Admin notices travel as short codes in the URL after a redirect, never as
 * raw HTML, so nothing user-supplied is ever echoed unescaped.
 */
function aqm_notice_text( $code ) {
	$map = array(
		'form_created'    => array( 'success', 'Form created with default fields.' ),
		'form_deleted'    => array( 'success', 'Form deleted.' ),
		'form_duplicated' => array( 'success', 'Form duplicated.' ),
		'form_name_empty' => array( 'error', 'Please enter a form name.' ),
		'settings_saved'  => array( 'success', 'Settings saved.' ),
		'field_saved'     => array( 'success', 'Field saved.' ),
		'field_deleted'   => array( 'success', 'Field deleted.' ),
		'field_shown'     => array( 'success', 'Field is now visible on the form.' ),
		'field_hidden'    => array( 'success', 'Field is now hidden from the form.' ),
		'field_required'  => array( 'success', 'Field marked as required.' ),
		'field_optional'  => array( 'success', 'Field marked as optional.' ),
		'field_empty'     => array( 'error', 'The field label cannot be empty.' ),
		'option_saved'    => array( 'success', 'Option saved.' ),
		'option_deleted'  => array( 'success', 'Option deleted.' ),
		'option_empty'    => array( 'error', 'The option label cannot be empty.' ),
		'entries_deleted' => array( 'success', 'Selected submissions deleted.' ),
		'delete_failed'   => array( 'error', 'The submissions could not be deleted - a database error was logged.' ),
		'nothing_picked'  => array( 'error', 'Nothing was selected.' ),
	);
	return $map[ $code ] ?? null;
}

function aqm_render_notice() {
	if ( empty( $_GET['aqm_msg'] ) ) {
		return;
	}
	$notice = aqm_notice_text( sanitize_key( wp_unslash( $_GET['aqm_msg'] ) ) );
	if ( ! $notice ) {
		return;
	}
	echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . esc_html( $notice[1] ) . '</p></div>';
}

add_action( 'admin_enqueue_scripts', 'aqm_admin_scripts' );

function aqm_admin_scripts( $hook ) {
	if ( false === strpos( $hook, 'aqm' ) ) {
		return;
	}

	wp_enqueue_script( 'jquery-ui-sortable' );
	wp_add_inline_script(
		'jquery-ui-sortable',
		'jQuery(function($){
			var nonce=' . wp_json_encode( wp_create_nonce( 'aqm_admin_nonce' ) ) . ';
			var formId=' . (int) ( $_GET['form_id'] ?? 0 ) . ';
			function flash($el,msg){$el.text(msg).css("color","#3a8a4a");setTimeout(function(){$el.text("");},2500);}
			function bind(sel,action,child,status){
				if(!$(sel).length){return;}
				$(sel).sortable({handle:".aqm-drag-handle",axis:"y",update:function(){
					var order=[];
					$(sel+" "+child).each(function(){order.push($(this).data("id"));});
					$.post(ajaxurl,{action:action,nonce:nonce,form_id:formId,order:order},function(r){
						flash($(status), r && r.success ? "Order saved" : "Could not save order");
					});
				}});
			}
			bind("#aqm-fields-sortable","aqm_save_field_order","tr","#aqm-order-status");
			bind("#aqm-options-sortable","aqm_save_option_order","li","#aqm-opt-order-status");
		});'
	);

	wp_add_inline_style(
		'wp-admin',
		'.aqm-drag-handle{cursor:grab;color:#767676;padding:0 10px;font-size:15px;user-select:none}
		.aqm-drag-handle:hover{color:#333}
		.ui-sortable-helper{background:#fff!important;box-shadow:0 4px 16px rgba(0,0,0,.2)!important;display:table}
		#aqm-options-sortable{list-style:none;margin:0;padding:0}
		#aqm-options-sortable li{display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid #ddd;border-radius:4px;margin-bottom:6px;background:#fafafa}
		.aqm-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer;text-decoration:none;transition:opacity .15s;border:1px solid transparent}
		.aqm-badge:hover{opacity:.75;text-decoration:none}
		.aqm-badge-on{background:#eaf5ec;color:#1e5c29;border-color:#b5debb}
		.aqm-badge-off{background:#f5f5f5;color:#666;border-color:#ddd}
		.aqm-badge-req{background:#fff3cd;color:#856404;border-color:#ffc107}
		.aqm-badge-opt{background:#e8f4fd;color:#0c5fa5;border-color:#b8d9f5}
		.aqm-box{background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;margin-bottom:20px}
		.aqm-type-badge{background:#e8f0fe;color:#1a56db;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
		.aqm-type-badge-combobox{background:#f0e8fe;color:#6b21d6;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
		.aqm-save-status{font-size:12px;color:#3a8a4a;margin-left:12px;font-style:italic}
		tr.aqm-disabled{opacity:.5}
		.aqm-form-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px 22px;margin-bottom:16px;display:flex;align-items:flex-start;gap:16px}
		.aqm-form-card-body{flex:1}
		.aqm-form-card h3{margin:0 0 6px;font-size:16px}
		.aqm-form-card p{margin:0;color:#555;font-size:13px}
		.aqm-form-card-actions{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
		.aqm-form-id-badge{background:#1a4b6e;color:#fff;border-radius:6px;padding:6px 14px;font-size:22px;font-weight:700;min-width:44px;text-align:center;flex-shrink:0}
		.aqm-shortcode-box{background:#f0f4f8;border:1px solid #c5d8ea;border-radius:4px;padding:6px 12px;font-family:monospace;font-size:13px;color:#1a4b6e;display:inline-block;cursor:pointer}
		.aqm-tbl-actions{display:flex;gap:5px;flex-wrap:wrap}'
	);
}

/* ---- AJAX reordering ---- */

add_action( 'wp_ajax_aqm_save_field_order', 'aqm_ajax_field_order' );

function aqm_ajax_field_order() {
	check_ajax_referer( 'aqm_admin_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	global $wpdb;
	$form_id = (int) ( $_POST['form_id'] ?? 0 );
	$order   = array_values( array_filter( array_map( 'intval', (array) ( $_POST['order'] ?? array() ) ) ) );

	foreach ( $order as $pos => $id ) {
		// Scoped to the form, so a stray ID cannot reorder another form's fields.
		$wpdb->update(
			aqm_table( 'form_fields' ),
			array( 'sort_order' => $pos + 1 ),
			array(
				'id'      => $id,
				'form_id' => $form_id,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);
	}

	wp_send_json_success();
}

add_action( 'wp_ajax_aqm_save_option_order', 'aqm_ajax_option_order' );

function aqm_ajax_option_order() {
	check_ajax_referer( 'aqm_admin_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Forbidden', 403 );
	}

	global $wpdb;
	$order = array_values( array_filter( array_map( 'intval', (array) ( $_POST['order'] ?? array() ) ) ) );

	foreach ( $order as $pos => $id ) {
		$wpdb->update( aqm_table( 'field_options' ), array( 'sort_order' => $pos + 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	wp_send_json_success();
}

/* ══════════════════════════════════════════════════════════════
   9. ADMIN PAGE - All Forms
   ══════════════════════════════════════════════════════════════ */

function aqm_admin_forms_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You do not have permission to view this page.' );
	}

	global $wpdb;
	$base = admin_url( 'admin.php?page=aqm-forms' );

	/* ---- Create. Redirects, so a refresh cannot create a second form ---- */
	if ( isset( $_POST['aqm_new_form_nonce'] ) ) {
		check_admin_referer( 'aqm_create_form', 'aqm_new_form_nonce' );

		$name = isset( $_POST['new_form_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_form_name'] ) ) : '';
		if ( '' === $name ) {
			wp_safe_redirect( add_query_arg( 'aqm_msg', 'form_name_empty', $base ) );
			exit;
		}

		$new_id = aqm_seed_default_form( $name );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'aqm-form-builder',
					'form_id' => $new_id,
					'aqm_msg' => 'form_created',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

	/* ---- Delete ---- */
	if ( 'delete_form' === $action && isset( $_GET['id'] ) ) {
		$fid = (int) $_GET['id'];
		check_admin_referer( 'aqm_del_form_' . $fid );

		$field_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . aqm_table( 'form_fields' ) . ' WHERE form_id = %d', $fid ) ); // phpcs:ignore
		foreach ( $field_ids as $fld ) {
			$wpdb->delete( aqm_table( 'field_options' ), array( 'field_id' => (int) $fld ), array( '%d' ) );
		}
		$wpdb->delete( aqm_table( 'form_fields' ), array( 'form_id' => $fid ), array( '%d' ) );
		$wpdb->delete( aqm_table( 'contact_entries' ), array( 'form_id' => $fid ), array( '%d' ) );
		$wpdb->delete( aqm_table( 'forms' ), array( 'id' => $fid ), array( '%d' ) );

		wp_safe_redirect( add_query_arg( 'aqm_msg', 'form_deleted', $base ) );
		exit;
	}

	/* ---- Duplicate ---- */
	if ( 'duplicate_form' === $action && isset( $_GET['id'] ) ) {
		$fid = (int) $_GET['id'];
		check_admin_referer( 'aqm_dup_form_' . $fid );

		$src = aqm_get_form( $fid );
		if ( $src ) {
			$wpdb->insert(
				aqm_table( 'forms' ),
				array(
					'form_name'         => $src->form_name . ' (Copy)',
					'notify_email'      => $src->notify_email,
					'notify_cc'         => $src->notify_cc,
					'email_subject'     => $src->email_subject,
					'captcha_enabled'   => $src->captcha_enabled,
					'spam_protection'   => $src->spam_protection,
					'autoreply_enabled' => $src->autoreply_enabled,
					'autoreply_subject' => $src->autoreply_subject,
					'autoreply_body'    => $src->autoreply_body,
					'success_message'   => $src->success_message,
					'store_ip'          => $src->store_ip,
					'created_at'        => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
			);
			$new_id = (int) $wpdb->insert_id;

			foreach ( aqm_get_fields( $src->id ) as $sf ) {
				$wpdb->insert(
					aqm_table( 'form_fields' ),
					array(
						'form_id'     => $new_id,
						'field_key'   => $sf->field_key,
						'label'       => $sf->label,
						'field_type'  => $sf->field_type,
						'placeholder' => $sf->placeholder,
						'required'    => $sf->required,
						'enabled'     => $sf->enabled,
						'sort_order'  => $sf->sort_order,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
				);
				$new_field_id = (int) $wpdb->insert_id;

				if ( in_array( $sf->field_type, array( 'select', 'combobox' ), true ) ) {
					foreach ( aqm_get_options( $sf->id ) as $opt ) {
						$wpdb->insert(
							aqm_table( 'field_options' ),
							array(
								'field_id'   => $new_field_id,
								'label'      => $opt->label,
								'sort_order' => $opt->sort_order,
							),
							array( '%d', '%s', '%d' )
						);
					}
				}
			}
		}

		wp_safe_redirect( add_query_arg( 'aqm_msg', 'form_duplicated', $base ) );
		exit;
	}

	$forms = aqm_get_forms();
	?>
	<div class="wrap">
		<h1 style="display:flex;align-items:center;gap:16px">
			All Forms
			<span style="font-size:13px;font-weight:400;color:#555"><?php echo esc_html( count( $forms ) ); ?> form(s) created</span>
		</h1>

		<?php aqm_render_notice(); ?>

		<div class="aqm-box" style="max-width:500px;background:#f9f9f9">
			<h3 style="margin-top:0">Create a New Form</h3>
			<form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
				<?php wp_nonce_field( 'aqm_create_form', 'aqm_new_form_nonce' ); ?>
				<label class="screen-reader-text" for="new_form_name">Form name</label>
				<input type="text" id="new_form_name" name="new_form_name" class="regular-text"
					placeholder="e.g. Event Registration, Volunteer Signup..." style="flex:1;min-width:220px" maxlength="120" required>
				<?php submit_button( 'Create Form', 'primary', 'submit', false ); ?>
			</form>
			<p class="description" style="margin-top:8px">Each new form starts with default fields. Customise them in the Form Builder.</p>
		</div>

		<?php if ( $forms ) : ?>
			<?php
			foreach ( $forms as $form ) :
				$builder_url = admin_url( 'admin.php?page=aqm-form-builder&form_id=' . $form->id );
				$subs_url    = admin_url( 'admin.php?page=aqm-submissions&form_id=' . $form->id );
				$del_url     = wp_nonce_url( add_query_arg( array( 'action' => 'delete_form', 'id' => $form->id ), $base ), 'aqm_del_form_' . $form->id );
				$dup_url     = wp_nonce_url( add_query_arg( array( 'action' => 'duplicate_form', 'id' => $form->id ), $base ), 'aqm_dup_form_' . $form->id );
				$field_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . aqm_table( 'form_fields' ) . ' WHERE form_id = %d', $form->id ) ); // phpcs:ignore
				$entry_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . aqm_table( 'contact_entries' ) . ' WHERE form_id = %d', $form->id ) ); // phpcs:ignore
				$shortcode   = '[aqm_form id="' . $form->id . '"]';
				?>
				<div class="aqm-form-card">
					<div class="aqm-form-id-badge"><?php echo esc_html( $form->id ); ?></div>
					<div class="aqm-form-card-body">
						<h3><?php echo esc_html( $form->form_name ); ?></h3>
						<p>
							<?php echo esc_html( $field_count ); ?> field(s) &nbsp;&middot;&nbsp;
							<?php echo esc_html( $entry_count ); ?> submission(s) &nbsp;&middot;&nbsp;
							CAPTCHA: <?php echo $form->captcha_enabled ? '<strong style="color:#1e5c29">ON</strong>' : '<span style="color:#666">OFF</span>'; ?> &nbsp;&middot;&nbsp;
							Notify: <em><?php echo esc_html( $form->notify_email ? $form->notify_email : 'not set' ); ?></em>
						</p>
						<p style="margin-top:8px">
							Shortcode:
							<span class="aqm-shortcode-box" title="Click to copy"
								onclick="navigator.clipboard.writeText('<?php echo esc_js( $shortcode ); ?>');var me=this;me.textContent='Copied!';setTimeout(function(){me.textContent='<?php echo esc_js( $shortcode ); ?>';},1500)">
								<?php echo esc_html( $shortcode ); ?>
							</span>
							<span style="font-size:12px;color:#666;margin-left:8px">paste this on any page or post</span>
						</p>
						<div class="aqm-form-card-actions">
							<a href="<?php echo esc_url( $builder_url ); ?>" class="button button-primary">Form Builder</a>
							<a href="<?php echo esc_url( $subs_url ); ?>" class="button">View Submissions</a>
							<a href="<?php echo esc_url( $dup_url ); ?>" class="button">Duplicate</a>
							<a href="<?php echo esc_url( $del_url ); ?>" class="button" style="color:#b32d2e;border-color:#b32d2e"
								onclick="return confirm('Delete the form &quot;<?php echo esc_js( $form->form_name ); ?>&quot; and ALL its submissions? This cannot be undone.')">Delete</a>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		<?php else : ?>
			<p>No forms yet. Create one above.</p>
		<?php endif; ?>
	</div>
	<?php
}

/* ══════════════════════════════════════════════════════════════
   10. ADMIN PAGE - Form Builder
   ══════════════════════════════════════════════════════════════ */

function aqm_field_types() {
	return array(
		'text'     => 'Text',
		'email'    => 'Email (validated)',
		'tel'      => 'Telephone',
		'number'   => 'Number',
		'url'      => 'Website URL',
		'date'     => 'Date Picker',
		'select'   => 'Dropdown (pick only)',
		'combobox' => 'Combobox (pick OR type freely)',
		'textarea' => 'Text Area',
		'checkbox' => 'Checkbox',
	);
}

function aqm_admin_builder_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You do not have permission to view this page.' );
	}

	global $wpdb;

	$form_id = (int) ( $_GET['form_id'] ?? 0 );
	$form    = $form_id ? aqm_get_form( $form_id ) : null;

	if ( ! $form ) {
		echo '<div class="wrap"><div class="notice notice-error"><p>No form selected. <a href="' . esc_url( admin_url( 'admin.php?page=aqm-forms' ) ) . '">Back to All Forms</a></p></div></div>';
		return;
	}

	$ftable = aqm_table( 'form_fields' );
	$otable = aqm_table( 'field_options' );
	$base   = admin_url( 'admin.php?page=aqm-form-builder&form_id=' . $form_id );

	$redirect = static function ( $code, $extra = array() ) use ( $base ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'aqm_msg' => $code ), $extra ), $base ) );
		exit;
	};

	/* ---- Save form settings ---- */
	if ( isset( $_POST['aqm_settings_nonce'] ) ) {
		check_admin_referer( 'aqm_save_settings_' . $form_id, 'aqm_settings_nonce' );

		$email = isset( $_POST['notify_email'] ) ? sanitize_email( wp_unslash( $_POST['notify_email'] ) ) : '';

		$cc_raw = isset( $_POST['notify_cc'] ) ? sanitize_text_field( wp_unslash( $_POST['notify_cc'] ) ) : '';
		$cc     = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $cc_raw ) ) ) as $candidate ) {
			$candidate = sanitize_email( $candidate );
			if ( is_email( $candidate ) ) {
				$cc[] = $candidate;
			}
		}

		$wpdb->update(
			aqm_table( 'forms' ),
			array(
				'form_name'         => isset( $_POST['form_name'] ) ? sanitize_text_field( wp_unslash( $_POST['form_name'] ) ) : $form->form_name,
				'notify_email'      => is_email( $email ) ? $email : get_option( 'admin_email' ),
				'notify_cc'         => implode( ', ', $cc ),
				'email_subject'     => isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '',
				'success_message'   => isset( $_POST['success_message'] ) ? sanitize_text_field( wp_unslash( $_POST['success_message'] ) ) : '',
				'autoreply_subject' => isset( $_POST['autoreply_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['autoreply_subject'] ) ) : '',
				'autoreply_body'    => isset( $_POST['autoreply_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['autoreply_body'] ) ) : '',
				'captcha_enabled'   => empty( $_POST['captcha_enabled'] ) ? 0 : 1,
				'spam_protection'   => empty( $_POST['spam_protection'] ) ? 0 : 1,
				'store_ip'          => empty( $_POST['store_ip'] ) ? 0 : 1,
				'autoreply_enabled' => empty( $_POST['autoreply_enabled'] ) ? 0 : 1,
			),
			array( 'id' => $form_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ),
			array( '%d' )
		);

		$redirect( 'settings_saved' );
	}

	/* ---- Field and option actions ---- */
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

	if ( 'delete_field' === $action && isset( $_GET['id'] ) ) {
		$fld = (int) $_GET['id'];
		check_admin_referer( 'aqm_del_field_' . $fld );
		$wpdb->delete( $ftable, array( 'id' => $fld, 'form_id' => $form_id ), array( '%d', '%d' ) );
		$wpdb->delete( $otable, array( 'field_id' => $fld ), array( '%d' ) );
		$redirect( 'field_deleted' );
	}

	if ( 'toggle_field' === $action && isset( $_GET['id'] ) ) {
		$fld = (int) $_GET['id'];
		check_admin_referer( 'aqm_tog_fld_' . $fld );
		$cur = (int) $wpdb->get_var( $wpdb->prepare( "SELECT enabled FROM $ftable WHERE id = %d AND form_id = %d", $fld, $form_id ) ); // phpcs:ignore
		$wpdb->update( $ftable, array( 'enabled' => $cur ? 0 : 1 ), array( 'id' => $fld, 'form_id' => $form_id ), array( '%d' ), array( '%d', '%d' ) );
		$redirect( $cur ? 'field_hidden' : 'field_shown' );
	}

	if ( 'toggle_req' === $action && isset( $_GET['id'] ) ) {
		$fld = (int) $_GET['id'];
		check_admin_referer( 'aqm_tog_req_' . $fld );
		$cur = (int) $wpdb->get_var( $wpdb->prepare( "SELECT required FROM $ftable WHERE id = %d AND form_id = %d", $fld, $form_id ) ); // phpcs:ignore
		$wpdb->update( $ftable, array( 'required' => $cur ? 0 : 1 ), array( 'id' => $fld, 'form_id' => $form_id ), array( '%d' ), array( '%d', '%d' ) );
		$redirect( $cur ? 'field_optional' : 'field_required' );
	}

	if ( 'delete_option' === $action && isset( $_GET['id'] ) ) {
		$oid = (int) $_GET['id'];
		check_admin_referer( 'aqm_del_opt_' . $oid );
		$show = (int) ( $_GET['options_for'] ?? 0 );
		$wpdb->delete( $otable, array( 'id' => $oid ), array( '%d' ) );
		$redirect( 'option_deleted', array( 'options_for' => $show ) );
	}

	/* ---- Save field ---- */
	if ( isset( $_POST['aqm_field_nonce'] ) ) {
		check_admin_referer( 'aqm_save_field_' . $form_id, 'aqm_field_nonce' );

		$label = isset( $_POST['aqm_field_label'] ) ? sanitize_text_field( wp_unslash( $_POST['aqm_field_label'] ) ) : '';
		$type  = isset( $_POST['aqm_field_type'] ) ? sanitize_key( wp_unslash( $_POST['aqm_field_type'] ) ) : 'text';
		$ph    = isset( $_POST['aqm_field_placeholder'] ) ? sanitize_text_field( wp_unslash( $_POST['aqm_field_placeholder'] ) ) : '';
		$req   = empty( $_POST['aqm_field_required'] ) ? 0 : 1;
		$edit  = (int) ( $_POST['aqm_edit_id'] ?? 0 );

		// Only field types this plugin can actually render.
		if ( ! array_key_exists( $type, aqm_field_types() ) ) {
			$type = 'text';
		}

		if ( '' === $label ) {
			$redirect( 'field_empty' );
		}

		if ( $edit ) {
			$wpdb->update(
				$ftable,
				array(
					'label'       => $label,
					'field_type'  => $type,
					'placeholder' => $ph,
					'required'    => $req,
				),
				array( 'id' => $edit, 'form_id' => $form_id ),
				array( '%s', '%s', '%s', '%d' ),
				array( '%d', '%d' )
			);
		} else {
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM $ftable WHERE form_id = %d", $form_id ) ); // phpcs:ignore
			$wpdb->insert(
				$ftable,
				array(
					'form_id'     => $form_id,
					'field_key'   => aqm_unique_key( $label, $form_id ),
					'label'       => $label,
					'field_type'  => $type,
					'placeholder' => $ph,
					'required'    => $req,
					'enabled'     => 1,
					'sort_order'  => $max + 1,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
			);
		}

		$redirect( 'field_saved' );
	}

	/* ---- Save option ---- */
	if ( isset( $_POST['aqm_option_nonce'] ) ) {
		check_admin_referer( 'aqm_save_option_' . $form_id, 'aqm_option_nonce' );

		$label = isset( $_POST['aqm_opt_label'] ) ? sanitize_text_field( wp_unslash( $_POST['aqm_opt_label'] ) ) : '';
		$fld   = (int) ( $_POST['aqm_opt_field_id'] ?? 0 );
		$oid   = (int) ( $_POST['aqm_opt_edit_id'] ?? 0 );

		if ( '' === $label ) {
			$redirect( 'option_empty', array( 'options_for' => $fld ) );
		}

		if ( $oid ) {
			$wpdb->update( $otable, array( 'label' => $label ), array( 'id' => $oid ), array( '%s' ), array( '%d' ) );
		} else {
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM $otable WHERE field_id = %d", $fld ) ); // phpcs:ignore
			$wpdb->insert( $otable, array( 'field_id' => $fld, 'label' => $label, 'sort_order' => $max + 1 ), array( '%d', '%s', '%d' ) );
		}

		$redirect( 'option_saved', array( 'options_for' => $fld ) );
	}

	/* ---- View state ---- */
	$show_opts_for = (int) ( $_GET['options_for'] ?? 0 );
	$all_fields    = aqm_get_fields( $form_id );

	$editing_field = null;
	if ( 'edit_field' === $action && isset( $_GET['id'] ) ) {
		$editing_field = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ftable WHERE id = %d AND form_id = %d", (int) $_GET['id'], $form_id ) ); // phpcs:ignore
	}

	$editing_opt = null;
	if ( 'edit_option' === $action && isset( $_GET['id'] ) ) {
		$editing_opt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $otable WHERE id = %d", (int) $_GET['id'] ) ); // phpcs:ignore
		if ( $editing_opt ) {
			$show_opts_for = (int) $editing_opt->field_id;
		}
	}
	?>
	<div class="wrap">
		<h1>
			Form Builder &mdash;
			<em style="font-weight:400;color:#555"><?php echo esc_html( $form->form_name ); ?></em>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aqm-forms' ) ); ?>" class="page-title-action">All Forms</a>
			<span class="aqm-save-status" id="aqm-order-status"></span>
		</h1>

		<p style="background:#e8f4fd;border:1px solid #b8d9f5;border-radius:4px;padding:8px 14px;display:inline-block;font-family:monospace;font-size:14px;color:#1a4b6e">
			Shortcode: <strong>[aqm_form id="<?php echo esc_html( $form_id ); ?>"]</strong>
		</p>

		<?php aqm_render_notice(); ?>

		<div style="display:flex;gap:28px;align-items:flex-start;flex-wrap:wrap;margin-top:16px">

			<div style="flex:1;min-width:520px">

				<details class="aqm-box" style="padding:0">
					<summary style="padding:14px 20px;cursor:pointer;font-weight:600;font-size:14px">
						Form Settings <span style="font-weight:400;color:#666;font-size:12px">(notification email, subject, auto-reply, CAPTCHA)</span>
					</summary>
					<div style="padding:0 20px 20px">
						<form method="post">
							<?php wp_nonce_field( 'aqm_save_settings_' . $form_id, 'aqm_settings_nonce' ); ?>
							<table class="form-table" style="max-width:620px">
								<tr>
									<th style="width:170px"><label for="fs_name">Form Name</label></th>
									<td><input type="text" id="fs_name" name="form_name" value="<?php echo esc_attr( $form->form_name ); ?>" maxlength="120" required style="width:100%"></td>
								</tr>
								<tr>
									<th><label for="fs_email">Notification Email</label></th>
									<td>
										<input type="email" id="fs_email" name="notify_email" value="<?php echo esc_attr( $form->notify_email ); ?>" style="width:100%">
										<p class="description">Submissions for this form are sent here.</p>
									</td>
								</tr>
								<tr>
									<th><label for="fs_cc">Cc (optional)</label></th>
									<td>
										<input type="text" id="fs_cc" name="notify_cc" value="<?php echo esc_attr( $form->notify_cc ); ?>" style="width:100%">
										<p class="description">Comma-separated. Invalid addresses are dropped when you save.</p>
									</td>
								</tr>
								<tr>
									<th><label for="fs_subject">Email Subject</label></th>
									<td><input type="text" id="fs_subject" name="email_subject" value="<?php echo esc_attr( $form->email_subject ); ?>" maxlength="200" style="width:100%"></td>
								</tr>
								<tr>
									<th><label for="fs_success">Thank-you message</label></th>
									<td>
										<input type="text" id="fs_success" name="success_message" value="<?php echo esc_attr( $form->success_message ); ?>" maxlength="255" style="width:100%">
										<p class="description">Shown after a successful submission. <code>{name}</code> and <code>{comma_name}</code> are available.</p>
									</td>
								</tr>
								<tr>
									<th>Auto-reply</th>
									<td>
										<label><input type="checkbox" name="autoreply_enabled" value="1" <?php checked( 1, (int) $form->autoreply_enabled ); ?>> Send a confirmation email to the person who wrote in</label>
										<p style="margin-top:10px">
											<label for="fs_ar_subject">Subject</label><br>
											<input type="text" id="fs_ar_subject" name="autoreply_subject" value="<?php echo esc_attr( $form->autoreply_subject ); ?>" maxlength="200" style="width:100%">
										</p>
										<p>
											<label for="fs_ar_body">Message</label><br>
											<textarea id="fs_ar_body" name="autoreply_body" rows="7" style="width:100%"><?php echo esc_textarea( $form->autoreply_body ); ?></textarea>
										</p>
										<p class="description">Placeholders: <code>{name}</code> <code>{form_name}</code> <code>{submission}</code> <code>{site_name}</code>. Sent to the first valid email field on the form.</p>
									</td>
								</tr>
								<tr>
									<th>Protection</th>
									<td>
										<label><input type="checkbox" name="captcha_enabled" value="1" <?php checked( 1, (int) $form->captcha_enabled ); ?>> Show the maths CAPTCHA</label><br>
										<label><input type="checkbox" name="spam_protection" value="1" <?php checked( 1, (int) $form->spam_protection ); ?>> Silent traps (honeypot and timing check, invisible to visitors)</label><br>
										<label><input type="checkbox" name="store_ip" value="1" <?php checked( 1, (int) $form->store_ip ); ?>> Store the sender's IP address with each submission</label>
									</td>
								</tr>
							</table>
							<?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?>
						</form>
					</div>
				</details>

				<div class="aqm-box">
					<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.5px;color:#444">
						Fields <span style="font-weight:400;text-transform:none;color:#666;font-size:12px">drag to reorder</span>
					</h2>
					<table class="widefat" style="border:none">
						<thead>
							<tr>
								<th style="width:28px"><span class="screen-reader-text">Reorder</span></th>
								<th>Label / Key</th>
								<th style="width:85px">Type</th>
								<th style="width:72px">Visible</th>
								<th style="width:88px">Required</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody id="aqm-fields-sortable">
						<?php
						foreach ( $all_fields as $f ) :
							$eu = add_query_arg( array( 'action' => 'edit_field', 'id' => $f->id ), $base );
							$du = wp_nonce_url( add_query_arg( array( 'action' => 'delete_field', 'id' => $f->id ), $base ), 'aqm_del_field_' . $f->id );
							$tu = wp_nonce_url( add_query_arg( array( 'action' => 'toggle_field', 'id' => $f->id ), $base ), 'aqm_tog_fld_' . $f->id );
							$ru = wp_nonce_url( add_query_arg( array( 'action' => 'toggle_req', 'id' => $f->id ), $base ), 'aqm_tog_req_' . $f->id );
							$ou = add_query_arg( 'options_for', $f->id, $base );
							?>
							<tr data-id="<?php echo esc_attr( $f->id ); ?>" class="<?php echo $f->enabled ? '' : 'aqm-disabled'; ?>">
								<td><span class="aqm-drag-handle" title="Drag to reorder" aria-hidden="true">&#9776;</span></td>
								<td>
									<strong><?php echo esc_html( $f->label ); ?></strong><br>
									<code style="font-size:11px;color:#767676"><?php echo esc_html( $f->field_key ); ?></code>
								</td>
								<td>
									<span class="<?php echo 'combobox' === $f->field_type ? 'aqm-type-badge-combobox' : 'aqm-type-badge'; ?>"><?php echo esc_html( $f->field_type ); ?></span>
								</td>
								<td>
									<a href="<?php echo esc_url( $tu ); ?>" class="aqm-badge <?php echo $f->enabled ? 'aqm-badge-on' : 'aqm-badge-off'; ?>"><?php echo $f->enabled ? 'ON' : 'OFF'; ?></a>
								</td>
								<td>
									<a href="<?php echo esc_url( $ru ); ?>" class="aqm-badge <?php echo $f->required ? 'aqm-badge-req' : 'aqm-badge-opt'; ?>"><?php echo $f->required ? 'Req' : 'Opt'; ?></a>
								</td>
								<td>
									<div class="aqm-tbl-actions">
										<a href="<?php echo esc_url( $eu ); ?>" class="button button-small">Edit</a>
										<?php if ( in_array( $f->field_type, array( 'select', 'combobox' ), true ) ) : ?>
											<a href="<?php echo esc_url( $ou ); ?>" class="button button-small" style="color:#0073aa;border-color:#0073aa">Options</a>
										<?php endif; ?>
										<a href="<?php echo esc_url( $du ); ?>" class="button button-small" style="color:#b32d2e;border-color:#b32d2e"
											onclick="return confirm('Delete the field &quot;<?php echo esc_js( $f->label ); ?>&quot;?')">Del</a>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php
				if ( $show_opts_for ) :
					$opt_field = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ftable WHERE id = %d AND form_id = %d", $show_opts_for, $form_id ) ); // phpcs:ignore
					if ( $opt_field ) :
						$options = aqm_get_options( $show_opts_for );
						?>
						<div class="aqm-box" style="border-color:#0073aa">
							<h2 style="margin-top:0;font-size:14px;color:#0073aa;text-transform:uppercase;letter-spacing:.5px">
								<?php echo 'combobox' === $opt_field->field_type ? 'Combobox Suggestions' : 'Dropdown Options'; ?>
								&mdash; <em><?php echo esc_html( $opt_field->label ); ?></em>
								<span class="aqm-save-status" id="aqm-opt-order-status"></span>
							</h2>

							<?php if ( 'combobox' === $opt_field->field_type ) : ?>
								<div style="background:#f0e8fe;border:1px solid #c4a8f5;border-radius:4px;padding:8px 14px;margin-bottom:14px;font-size:13px;color:#4a1990">
									<strong>Combobox mode:</strong> these appear as suggestions. Visitors can pick one <em>or</em> type their own value.
								</div>
							<?php endif; ?>

							<ul id="aqm-options-sortable">
							<?php
							foreach ( $options as $opt ) :
								$eou = add_query_arg( array( 'action' => 'edit_option', 'id' => $opt->id, 'options_for' => $show_opts_for ), $base );
								$dou = wp_nonce_url( add_query_arg( array( 'action' => 'delete_option', 'id' => $opt->id, 'options_for' => $show_opts_for ), $base ), 'aqm_del_opt_' . $opt->id );
								?>
								<li data-id="<?php echo esc_attr( $opt->id ); ?>">
									<span class="aqm-drag-handle" aria-hidden="true">&#9776;</span>
									<span style="flex:1"><?php echo esc_html( $opt->label ); ?></span>
									<a href="<?php echo esc_url( $eou ); ?>" class="button button-small">Edit</a>
									<a href="<?php echo esc_url( $dou ); ?>" class="button button-small" style="color:#b32d2e;border-color:#b32d2e"
										onclick="return confirm('Delete this option?')">Del</a>
								</li>
							<?php endforeach; ?>
							</ul>

							<div style="margin-top:14px;padding-top:14px;border-top:1px solid #dde">
								<strong><?php echo $editing_opt ? 'Edit Option' : 'Add Option'; ?></strong>
								<form method="post" style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap">
									<?php wp_nonce_field( 'aqm_save_option_' . $form_id, 'aqm_option_nonce' ); ?>
									<input type="hidden" name="aqm_opt_field_id" value="<?php echo esc_attr( $show_opts_for ); ?>">
									<?php if ( $editing_opt ) : ?>
										<input type="hidden" name="aqm_opt_edit_id" value="<?php echo esc_attr( $editing_opt->id ); ?>">
									<?php endif; ?>
									<label class="screen-reader-text" for="aqm_opt_label">Option label</label>
									<input type="text" id="aqm_opt_label" name="aqm_opt_label" maxlength="120"
										value="<?php echo esc_attr( $editing_opt ? $editing_opt->label : '' ); ?>"
										placeholder="Option label..." style="flex:1;min-width:180px" required>
									<?php submit_button( $editing_opt ? 'Update' : 'Add Option', 'primary', 'submit', false ); ?>
									<?php if ( $editing_opt ) : ?>
										<a href="<?php echo esc_url( add_query_arg( 'options_for', $show_opts_for, $base ) ); ?>" class="button">Cancel</a>
									<?php endif; ?>
								</form>
							</div>
						</div>
						<?php
					endif;
				endif;
				?>

			</div>

			<div style="flex:0 0 265px">
				<div class="aqm-box">
					<h2 style="margin-top:0;font-size:14px;text-transform:uppercase;letter-spacing:.5px;color:#444">
						<?php echo $editing_field ? 'Edit Field' : 'Add Field'; ?>
					</h2>
					<form method="post">
						<?php wp_nonce_field( 'aqm_save_field_' . $form_id, 'aqm_field_nonce' ); ?>
						<?php if ( $editing_field ) : ?>
							<input type="hidden" name="aqm_edit_id" value="<?php echo esc_attr( $editing_field->id ); ?>">
						<?php endif; ?>
						<p>
							<label for="aqm_field_label"><strong>Label <span style="color:#9a6c1f">*</span></strong></label>
							<input type="text" id="aqm_field_label" name="aqm_field_label" maxlength="120"
								value="<?php echo esc_attr( $editing_field ? $editing_field->label : '' ); ?>"
								placeholder="e.g. Company Name" style="width:100%;margin-top:4px" required>
						</p>
						<p>
							<label for="aqm_field_type"><strong>Field Type</strong></label>
							<select id="aqm_field_type" name="aqm_field_type" style="width:100%;margin-top:4px">
								<?php
								$current = $editing_field ? $editing_field->field_type : 'text';
								foreach ( aqm_field_types() as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="aqm_field_placeholder"><strong>Placeholder</strong></label>
							<input type="text" id="aqm_field_placeholder" name="aqm_field_placeholder" maxlength="200"
								value="<?php echo esc_attr( $editing_field ? $editing_field->placeholder : '' ); ?>"
								placeholder="Hint text..." style="width:100%;margin-top:4px">
						</p>
						<p>
							<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600">
								<input type="checkbox" name="aqm_field_required" value="1" <?php checked( $editing_field ? (int) $editing_field->required : 1, 1 ); ?>>
								Mark as Required
							</label>
						</p>
						<p style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
							<?php submit_button( $editing_field ? 'Update' : 'Add Field', 'primary', 'submit', false ); ?>
							<?php if ( $editing_field ) : ?>
								<a href="<?php echo esc_url( $base ); ?>" class="button">Cancel</a>
							<?php endif; ?>
						</p>
					</form>
				</div>

				<div class="aqm-box" style="font-size:12px;background:#fafafa">
					<?php foreach ( aqm_field_types() as $type => $label ) : ?>
						<div style="display:flex;gap:8px;margin-bottom:5px;align-items:center">
							<span class="aqm-type-badge" style="min-width:60px;text-align:center"><?php echo esc_html( $type ); ?></span>
							<span style="color:#555"><?php echo esc_html( $label ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

		</div>
	</div>
	<?php
}

/* ══════════════════════════════════════════════════════════════
   11. ADMIN PAGE - Submissions
   ══════════════════════════════════════════════════════════════ */

function aqm_admin_submissions_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You do not have permission to view this page.' );
	}

	global $wpdb;

	$entries_table = aqm_table( 'contact_entries' );
	$base          = admin_url( 'admin.php?page=aqm-submissions' );
	$form_id       = (int) ( $_GET['form_id'] ?? 0 );
	$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

	/* ---- Bulk delete, then redirect so a refresh cannot repeat it ---- */
	if ( isset( $_POST['aqm_bulk_nonce'] ) ) {
		check_admin_referer( 'aqm_bulk_entries', 'aqm_bulk_nonce' );

		$ids  = array_values( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['entry_ids'] ?? array() ) ) ) );
		$act  = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$code = 'nothing_picked';

		if ( 'delete' === $act && $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$deleted      = $wpdb->query( $wpdb->prepare( "DELETE FROM $entries_table WHERE id IN ($placeholders)", $ids ) ); // phpcs:ignore

			if ( false === $deleted ) {
				error_log( 'AQM Contact Form: bulk delete failed - ' . $wpdb->last_error );
				$code = 'delete_failed';
			} else {
				$code = 'entries_deleted';
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'aqm-submissions',
					'form_id' => $form_id,
					's'       => $search,
					'aqm_msg' => $code,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$forms = aqm_get_forms();

	/* ---- Query ---- */
	$where  = array( '1=1' );
	$params = array();

	if ( $form_id ) {
		$where[]  = 'form_id = %d';
		$params[] = $form_id;
	}
	if ( '' !== $search ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where[]  = '(form_data LIKE %s OR form_name LIKE %s)';
		$params[] = $like;
		$params[] = $like;
	}
	$where_sql = implode( ' AND ', $where );

	$count_sql = "SELECT COUNT(*) FROM $entries_table WHERE $where_sql";
	$total     = (int) ( $params
		? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore
		: $wpdb->get_var( $count_sql ) ); // phpcs:ignore

	// v7.0.0 selected every row with no LIMIT. Fine at fifty submissions,
	// a blank screen at twenty thousand.
	$per_page    = 25;
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	$paged       = min( max( 1, (int) ( $_GET['paged'] ?? 1 ) ), $total_pages );
	$offset      = ( $paged - 1 ) * $per_page;

	$entries = $wpdb->get_results( // phpcs:ignore
		$wpdb->prepare(
			"SELECT * FROM $entries_table WHERE $where_sql ORDER BY submitted_at DESC, id DESC LIMIT %d OFFSET %d",
			array_merge( $params, array( $per_page, $offset ) )
		)
	);

	$fields = $form_id ? aqm_get_fields( $form_id ) : array();

	$export_url = wp_nonce_url(
		add_query_arg(
			array(
				'action'  => 'aqm_export_entries',
				'form_id' => $form_id,
				's'       => $search,
			),
			admin_url( 'admin-post.php' )
		),
		'aqm_export_entries'
	);
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">Submissions</h1>
		<?php if ( $form_id ) : ?>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">Export CSV</a>
		<?php endif; ?>
		<hr class="wp-header-end">

		<?php aqm_render_notice(); ?>

		<div style="margin:16px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
			<strong>Filter by form:</strong>
			<a href="<?php echo esc_url( $base ); ?>" class="button <?php echo ! $form_id ? 'button-primary' : ''; ?>">All Forms</a>
			<?php foreach ( $forms as $f ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'form_id', $f->id, $base ) ); ?>" class="button <?php echo $form_id === (int) $f->id ? 'button-primary' : ''; ?>">
					<?php echo esc_html( $f->form_name ); ?>
				</a>
			<?php endforeach; ?>
		</div>

		<form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
			<input type="hidden" name="page" value="aqm-submissions">
			<input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">
			<label class="screen-reader-text" for="aqm-search">Search submissions</label>
			<input type="search" id="aqm-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search submitted data..." style="min-width:260px">
			<?php submit_button( 'Search', 'secondary', '', false ); ?>
			<?php if ( '' !== $search ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'form_id', $form_id, $base ) ); ?>">Reset</a>
			<?php endif; ?>
			<span class="displaying-num" style="margin-left:auto"><?php echo esc_html( number_format_i18n( $total ) ); ?> submission(s)</span>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'aqm_bulk_entries', 'aqm_bulk_nonce' ); ?>
			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<label class="screen-reader-text" for="aqm-bulk">Bulk action</label>
					<select name="bulk_action" id="aqm-bulk">
						<option value="">Bulk actions</option>
						<option value="delete">Delete permanently</option>
					</select>
					<button type="submit" class="button action" onclick="return confirm('Delete the selected submissions? This cannot be undone.');">Apply</button>
				</div>
			</div>

			<div style="overflow-x:auto">
			<table class="widefat striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" aria-label="Select all"
							onclick="var b=this.checked;this.closest('form').querySelectorAll('input[name=\'entry_ids[]\']').forEach(function(c){c.checked=b;});"></td>
						<th style="width:50px">#</th>
						<?php if ( ! $form_id ) : ?><th>Form</th><?php endif; ?>
						<?php if ( $fields ) : ?>
							<?php foreach ( $fields as $f ) : ?>
								<th><?php echo esc_html( $f->label ); ?></th>
							<?php endforeach; ?>
						<?php else : ?>
							<th>Submitted Data</th>
						<?php endif; ?>
						<th style="width:150px">Date</th>
						<th style="width:110px">IP</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( $entries ) : ?>
					<?php
					foreach ( $entries as $e ) :
						$data = json_decode( $e->form_data, true );
						$data = is_array( $data ) ? $data : array();
						?>
						<tr>
							<th scope="row" class="check-column">
								<input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr( $e->id ); ?>" aria-label="Select submission <?php echo esc_attr( $e->id ); ?>">
							</th>
							<td><?php echo esc_html( $e->id ); ?></td>
							<?php if ( ! $form_id ) : ?>
								<td style="font-size:12px;color:#555"><?php echo esc_html( $e->form_name ); ?></td>
							<?php endif; ?>
							<?php if ( $fields ) : ?>
								<?php foreach ( $fields as $f ) : ?>
									<td><?php echo esc_html( $data[ $f->field_key ] ?? '-' ); ?></td>
								<?php endforeach; ?>
							<?php else : ?>
								<td style="font-size:12px;max-width:400px;white-space:pre-wrap"><?php
									foreach ( $data as $k => $v ) {
										echo esc_html( $k . ': ' . $v ) . "\n";
									}
								?></td>
							<?php endif; ?>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $e->submitted_at ) ); ?></td>
							<td style="font-size:11px;color:#767676"><?php echo esc_html( $e->ip_address ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="20">No submissions found.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			</div>
		</form>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%', remove_query_arg( 'aqm_msg' ) ),
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

/* ---- CSV export ---- */

add_action( 'admin_post_aqm_export_entries', 'aqm_export_entries' );

function aqm_export_entries() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You do not have permission to export submissions.' );
	}
	check_admin_referer( 'aqm_export_entries' );

	global $wpdb;

	$form_id = (int) ( $_GET['form_id'] ?? 0 );
	$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$form    = $form_id ? aqm_get_form( $form_id ) : null;

	if ( ! $form ) {
		wp_die( 'Choose a form before exporting.' );
	}

	$fields = aqm_get_fields( $form_id );
	$table  = aqm_table( 'contact_entries' );

	$params = array( $form_id );
	$sql    = "SELECT * FROM $table WHERE form_id = %d";
	if ( '' !== $search ) {
		$sql     .= ' AND form_data LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $search ) . '%';
	}
	$sql .= ' ORDER BY submitted_at DESC, id DESC';

	$entries = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore

	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename=aqm-' . sanitize_title( $form->form_name ) . '-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM, so Excel reads UTF-8 correctly.

	$header = array( 'ID' );
	foreach ( $fields as $f ) {
		$header[] = $f->label;
	}
	$header[] = 'Submitted';
	$header[] = 'IP Address';

	// The empty escape character avoids PHP 8.4's deprecation notice and
	// stops backslashes being mangled in message text.
	fputcsv( $out, array_map( 'aqm_csv_safe', $header ), ',', '"', '' );

	foreach ( $entries as $e ) {
		$data = json_decode( $e->form_data, true );
		$data = is_array( $data ) ? $data : array();

		$row = array( $e->id );
		foreach ( $fields as $f ) {
			$row[] = $data[ $f->field_key ] ?? '';
		}
		$row[] = $e->submitted_at;
		$row[] = $e->ip_address;

		fputcsv( $out, array_map( 'aqm_csv_safe', $row ), ',', '"', '' );
	}

	fclose( $out );
	exit;
}

/**
 * Neutralise spreadsheet formula injection. Without this, someone can submit
 * a message beginning "=" and have it execute when you open the export.
 */
function aqm_csv_safe( $value ) {
	$value = (string) $value;
	if ( '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
		return "'" . $value;
	}
	return $value;
}

/* ══════════════════════════════════════════════════════════════
   12. ADMIN PAGE - Global settings
   ══════════════════════════════════════════════════════════════ */

function aqm_admin_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You do not have permission to view this page.' );
	}

	if ( isset( $_POST['aqm_global_nonce'] ) ) {
		check_admin_referer( 'aqm_save_global', 'aqm_global_nonce' );

		$proxy = isset( $_POST['proxy_header'] ) ? sanitize_text_field( wp_unslash( $_POST['proxy_header'] ) ) : '';
		update_option( 'aqm_proxy_header', array_key_exists( $proxy, aqm_proxy_headers() ) ? $proxy : '' );
		update_option( 'aqm_rate_limit', max( 0, min( 100, (int) ( $_POST['rate_limit'] ?? 5 ) ) ) );
		update_option( 'aqm_delete_on_uninstall', empty( $_POST['delete_on_uninstall'] ) ? 0 : 1 );

		wp_safe_redirect( add_query_arg( 'aqm_msg', 'settings_saved', admin_url( 'admin.php?page=aqm-settings' ) ) );
		exit;
	}

	$proxy_header = (string) get_option( 'aqm_proxy_header', '' );
	$rate_limit   = (int) get_option( 'aqm_rate_limit', 5 );
	$delete_all   = (int) get_option( 'aqm_delete_on_uninstall', 0 );
	$detected_ip  = aqm_get_client_ip();
	?>
	<div class="wrap">
		<h1>Global Settings</h1>
		<p class="description">These apply to every form. Per-form options live in the Form Builder.</p>

		<?php aqm_render_notice(); ?>

		<form method="post">
			<?php wp_nonce_field( 'aqm_save_global', 'aqm_global_nonce' ); ?>

			<h2>Spam Protection</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rate_limit">Submissions per hour</label></th>
					<td>
						<input type="number" id="rate_limit" name="rate_limit" min="0" max="100" value="<?php echo esc_attr( $rate_limit ); ?>" class="small-text">
						<p class="description">Per IP address, across all forms. Set to 0 to disable rate limiting.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="proxy_header">Is this site behind a proxy?</label></th>
					<td>
						<select id="proxy_header" name="proxy_header">
							<?php foreach ( aqm_proxy_headers() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $proxy_header, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							Behind Cloudflare or a load balancer the server sees the proxy's address, so every visitor shares one allowance and genuine enquiries get blocked.
							<br><strong>Detected right now:</strong> <code><?php echo esc_html( $detected_ip ? $detected_ip : 'unknown' ); ?></code>
							&mdash; if that is not your own IP address, choose a different option above.
						</p>
					</td>
				</tr>
			</table>

			<h2>Uninstall</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Data</th>
					<td>
						<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( 1, $delete_all ); ?>> Delete all forms and submissions if this plugin is deleted</label>
						<p class="description">Leave unticked to keep your history safe.</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/* ══════════════════════════════════════════════════════════════
   13. SELF-UPDATE FROM GITHUB RELEASES

   To release: bump the Version header AND AQM_VERSION to match,
   tag the release v<version> on GitHub, and attach the built .zip.
   ══════════════════════════════════════════════════════════════ */

function aqm_github_release( $force = false ) {
	$key = 'aqm_gh_release';

	if ( ! $force ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/' . AQM_GITHUB_REPO . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'aqm-contact-form/' . AQM_VERSION,
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		set_transient( $key, array(), HOUR_IN_SECONDS );
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
		set_transient( $key, array(), HOUR_IN_SECONDS );
		return null;
	}

	// Prefer an attached .zip. GitHub's automatic zipball names its folder
	// after the commit, which would install a duplicate rather than update.
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
		'url'       => $body['html_url'] ?? 'https://github.com/' . AQM_GITHUB_REPO,
		'changelog' => (string) ( $body['body'] ?? '' ),
		'published' => (string) ( $body['published_at'] ?? '' ),
	);

	set_transient( $key, $release, 12 * HOUR_IN_SECONDS );
	return $release;
}

add_filter( 'site_transient_update_plugins', 'aqm_inject_update' );

function aqm_inject_update( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}

	$plugin  = plugin_basename( AQM_FILE );
	$release = aqm_github_release();

	if ( ! $release || ! $release['package'] ) {
		return $transient;
	}

	$item = (object) array(
		'id'           => 'github.com/' . AQM_GITHUB_REPO,
		'slug'         => dirname( $plugin ),
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

	if ( version_compare( $release['version'], AQM_VERSION, '>' ) ) {
		$transient->response[ $plugin ] = $item;
	} else {
		// Listing it here is what makes "Enable auto-updates" appear.
		$transient->no_update[ $plugin ] = $item;
	}

	return $transient;
}

add_filter( 'plugins_api', 'aqm_plugin_details', 20, 3 );

function aqm_plugin_details( $result, $action, $args ) {
	if ( 'plugin_information' !== $action ) {
		return $result;
	}
	if ( empty( $args->slug ) || dirname( plugin_basename( AQM_FILE ) ) !== $args->slug ) {
		return $result;
	}

	$release = aqm_github_release();
	if ( ! $release ) {
		return $result;
	}

	return (object) array(
		'name'          => 'A. Q. Mufti - Contact Form',
		'slug'          => $args->slug,
		'version'       => $release['version'],
		'author'        => '<a href="https://github.com/AQMufti">A. Q. Mufti</a>',
		'homepage'      => $release['url'],
		'download_link' => $release['package'],
		'requires'      => '5.8',
		'requires_php'  => '7.4',
		'last_updated'  => $release['published'],
		'sections'      => array(
			'description' => '<p>Multi-form builder with combobox fields, CAPTCHA, spam protection and CSV export.</p>',
			'changelog'   => $release['changelog'] ? wpautop( wp_kses_post( $release['changelog'] ) ) : '<p>No release notes were provided.</p>',
		),
	);
}

/*
 * NOTE: v7.1.0 carried an upgrader_source_selection filter that renamed the
 * unpacked folder to match the installed one. On an upload-overwrite it moved
 * the folder inside itself, producing aqm-contact-form/aqm-contact-form/ and
 * a plugin WordPress could not activate. It has been removed: every release
 * ships a ZIP whose top-level folder is already aqm-contact-form, so the
 * safety net was solving a problem that does not occur, and caused one.
 */

add_action( 'upgrader_process_complete', 'aqm_clear_release_cache', 10, 0 );

function aqm_clear_release_cache() {
	delete_transient( 'aqm_gh_release' );
}

add_filter( 'plugin_row_meta', 'aqm_plugin_row_meta', 10, 2 );

function aqm_plugin_row_meta( $links, $file ) {
	if ( plugin_basename( AQM_FILE ) !== $file || ! current_user_can( 'update_plugins' ) ) {
		return $links;
	}
	$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aqm_check_update' ), 'aqm_check_update' ) ) . '">Check for updates</a>';
	return $links;
}

add_action( 'admin_post_aqm_check_update', 'aqm_force_update_check' );

function aqm_force_update_check() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		wp_die( 'You do not have permission to check for updates.' );
	}
	check_admin_referer( 'aqm_check_update' );

	$release = aqm_github_release( true );
	delete_site_transient( 'update_plugins' );

	$status = 'unreachable';
	if ( $release ) {
		$status = version_compare( $release['version'], AQM_VERSION, '>' ) ? 'available' : 'current';
	}

	wp_safe_redirect( add_query_arg( 'aqm_update', $status, admin_url( 'plugins.php' ) ) );
	exit;
}

add_action( 'admin_notices', 'aqm_update_check_notice' );

function aqm_update_check_notice() {
	if ( empty( $_GET['aqm_update'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$status  = sanitize_key( wp_unslash( $_GET['aqm_update'] ) );
	$release = aqm_github_release();

	if ( 'available' === $status && $release ) {
		$class   = 'notice-warning';
		$message = 'Version ' . $release['version'] . ' of the contact form is available. Refresh this page and use the update link on the plugin row.';
	} elseif ( 'current' === $status ) {
		$class   = 'notice-success';
		$message = 'The contact form is up to date (version ' . AQM_VERSION . ').';
	} else {
		$class   = 'notice-error';
		$message = 'Could not reach GitHub to check for contact form updates. Please try again shortly.';
	}

	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}

/* ══════════════════════════════════════════════════════════════
   14. UNINSTALL
   ══════════════════════════════════════════════════════════════ */

register_uninstall_hook( __FILE__, 'aqm_uninstall' );

function aqm_uninstall() {
	if ( ! get_option( 'aqm_delete_on_uninstall' ) ) {
		return;
	}

	global $wpdb;

	foreach ( array( 'contact_entries', 'field_options', 'form_fields', 'forms' ) as $name ) {
		$table = $wpdb->prefix . 'aqm_' . $name;
		$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore
	}

	delete_option( 'aqm_db_version' );
	delete_option( 'aqm_seeded' );
	delete_option( 'aqm_proxy_header' );
	delete_option( 'aqm_rate_limit' );
	delete_option( 'aqm_delete_on_uninstall' );
}
