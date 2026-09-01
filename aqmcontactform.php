<?php
/**
 * Plugin Name:       A. Q. Mufti - Contact Form
 * Plugin URI:        https://github.com/AQMufti/aqm-contact-form
 * Description:       Multi-form builder. Each form has independent fields, dropdowns, editable comboboxes, multi-pick checkbox groups, per-field help text, default values, CAPTCHA, spam protection and required/optional settings. Shortcode: [aqm_form id="N"]
 * Version:           7.8.2
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

define( 'AQM_VERSION', '7.8.2' );
define( 'AQM_DB_VERSION', 11 );
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
			form_intro text NOT NULL,
			captcha_enabled tinyint(1) NOT NULL default 1,
			spam_protection tinyint(1) NOT NULL default 1,
			autoreply_enabled tinyint(1) NOT NULL default 1,
			autoreply_subject varchar(200) NOT NULL default 'We received your message',
			autoreply_body text NOT NULL,
			success_message varchar(255) NOT NULL default '',
			store_ip tinyint(1) NOT NULL default 0,
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
			placeholder text NOT NULL,
			help_text text NOT NULL,
			default_value varchar(200) NOT NULL default '',
			required tinyint(1) NOT NULL default 1,
			enabled tinyint(1) NOT NULL default 1,
			sort_order int(11) NOT NULL default 0,
			num_whole tinyint(1) NOT NULL default 1,
			num_min varchar(20) NOT NULL default '',
			num_max varchar(20) NOT NULL default '',
			num_default varchar(20) NOT NULL default '',
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
			'notify_email'      => '',
			'email_subject'     => 'New Contact Form Submission',
			'form_intro'        => '',
			'captcha_enabled'   => 1,
			'spam_protection'   => 1,
			'autoreply_enabled' => 1,
			'autoreply_subject' => 'We received your message',
			'autoreply_body'    => aqm_default_autoreply_body(),
			'success_message'   => 'Thank you{comma_name}! Your message has been received. We will be in touch soon.',
			'store_ip'          => 0,
			'created_at'        => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
	);

	$form_id = (int) $wpdb->insert_id;
	aqm_seed_default_fields( $form_id );

	return $form_id;
}

function aqm_seed_default_fields( $form_id ) {
	global $wpdb;

	/* Each seed carries its own option list, so a new field type never means
	   editing a second hard-coded block somewhere below.

	   'on' => 0 seeds a field HIDDEN. Dietary requirements and Allergies are
	   wanted on registration forms and nowhere else, and most forms are not
	   registrations - a quote request or a job enquiry should not open with
	   two catering questions. Seeding them hidden means the lists are always
	   THERE, already filled in, one click on the OFF badge away from being
	   used, and invisible to visitors until someone asks for them. */
	$fields = array(
		array(
			'key' => 'name', 'label' => 'Full Name', 'type' => 'text',
			'ph' => 'Your full name', 'req' => 1, 'on' => 1, 'help' => '',
		),
		array(
			'key' => 'email', 'label' => 'Email Address', 'type' => 'email',
			'ph' => 'your@email.com', 'req' => 1, 'on' => 1,
			'help' => 'So we can reply to you.',
		),
		array(
			'key' => 'phone', 'label' => 'Telephone', 'type' => 'tel',
			'ph' => '(905) 555-0100', 'req' => 0, 'on' => 1,
			'help' => 'Only if you would rather we called.',
		),
		array(
			'key' => 'event_type', 'label' => 'Type of Event', 'type' => 'combobox',
			'ph' => '', 'req' => 1, 'on' => 1,
			'help' => 'Pick the closest match, or type your own.',
			'opts' => array(
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
			),
		),
		array(
			'key' => 'dietary_requirements', 'label' => 'Dietary requirements',
			'type' => 'combobox', 'ph' => '', 'req' => 0, 'on' => 0,
			'help' => '',
			'opts' => array(
				'Halal',
				'Kosher',
				'Vegetarian',
				'Vegan',
				'Gluten free',
				'Dairy free',
				'No pork',
				'No beef',
				'None',
			),
		),
		array(
			'key' => 'allergies', 'label' => 'Food allergies',
			'type' => 'multiselect', 'ph' => '', 'req' => 0, 'on' => 0,
			'help' => 'Tick everything that applies. Add anything else in the box below.',
			'opts' => array(
				'Peanut',
				'Tree nut',
				'Shellfish',
				'Fish',
				'Egg',
				'Dairy',
				'Soy',
				'Sesame',
				'Wheat or gluten',
				'None',
			),
		),
		array(
			'key' => 'message', 'label' => 'Message', 'type' => 'textarea',
			'ph' => 'Describe your inquiry...', 'req' => 1, 'on' => 1, 'help' => '',
		),
	);

	$sort = 0;
	foreach ( $fields as $f ) {
		$sort++;
		$wpdb->insert(
			aqm_table( 'form_fields' ),
			array(
				'form_id'     => $form_id,
				'field_key'   => aqm_unique_key( $f['key'], $form_id ),
				'label'       => $f['label'],
				'field_type'  => $f['type'],
				'placeholder' => $f['ph'],
				'help_text'   => $f['help'],
				'required'    => $f['req'],
				'enabled'     => $f['on'],
				'sort_order'  => $sort,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);

		if ( empty( $f['opts'] ) ) {
			continue;
		}

		$field_db_id = (int) $wpdb->insert_id;
		foreach ( $f['opts'] as $i => $opt ) {
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


/* ══════════════════════════════════════════════════════════════
   NUMBER FIELDS - whole numbers, range, default

   A count of people cannot be 3.5. Before 7.3.0 the only check on a
   number field was is_numeric(), so "0", "-5" and "3.7" all passed and a
   registration for three and a half guests would reach the caterer.

   Every number field now carries four settings. "Whole numbers only" is
   ON by default, because on most forms a number is a count.

   THE LABEL IS ONLY A SUGGESTION. aqm_guess_whole_number() reads the
   label to pre-set the CHECKBOX when a field is created. It is never
   consulted when a submission is validated. That distinction matters: a
   guess that writes into a control you can see and change is auditable,
   while one evaluated silently at submit time is not. When it guesses
   wrong you untick a box - you do not debug a word list.
   ══════════════════════════════════════════════════════════════ */

/** Words that mean the number can carry a fraction. */
function aqm_fraction_words() {
	return array(
		'average', 'avg', 'mean', 'median', 'rate', 'ratio', 'percent', 'percentage',
		'amount', 'price', 'cost', 'fee', 'salary', 'budget', 'donation', 'discount',
		'tax', 'balance', 'dollar', 'dollars', 'weight', 'height', 'width', 'length',
		'distance', 'temperature', 'score', 'gpa', 'factor', 'index', 'dose', 'dosage',
		'kg', 'lb', 'lbs', 'gram', 'grams', 'km', 'mile', 'miles', 'litre', 'litres',
		'liter', 'liters', 'gallon', 'gallons', 'hour', 'hours', 'hrs', 'minute',
		'minutes', 'per',
	);
}

/**
 * Phrases that mean it is a count even when a fraction word is also present.
 * "Total number of guests" is a count; "total" on its own is not.
 */
function aqm_count_phrases() {
	return array( 'how many', 'number of', 'no. of', '# of' );
}

/**
 * Guess whether a number field should accept whole numbers only.
 *
 * Default TRUE. A fraction word turns it off; a counting phrase turns it back
 * on, being the more specific signal.
 *
 * @param string $label Field label.
 * @return int 1 or 0.
 */
function aqm_guess_whole_number( $label ) {
	$t = aqm_norm_label( $label );

	foreach ( aqm_count_phrases() as $phrase ) {
		if ( false !== strpos( $t, trim( aqm_norm_label( $phrase ) ) ) ) {
			return 1;
		}
	}

	if ( false !== strpos( $t, '%' ) ) {
		return 0;
	}

	foreach ( aqm_fraction_words() as $word ) {
		// The surrounding spaces ARE the word boundary. Without them "per"
		// matches "person" and every count field silently allows decimals.
		if ( false !== strpos( $t, ' ' . $word . ' ' ) ) {
			return 0;
		}
	}

	return 1;
}

/**
 * Lower-case, and reduce anything that is not a letter, digit or % to a single
 * space, padded at both ends. The builder's JavaScript normalises identically,
 * so the checkbox it pre-sets always agrees with what this function decides.
 *
 * @param string $text Raw text.
 * @return string
 */
/**
 * Check a submitted number against the field's settings.
 *
 * The min/max/step attributes on the input are a courtesy to the visitor.
 * They are not validation - anything at all can be POSTed - so this is where
 * it is actually enforced. Kept separate from aqm_handle_submission() so it
 * can be tested on its own.
 *
 * @param object $f   Field row.
 * @param string $val Raw submitted value.
 * @return array{error:string,value:string}
 */
function aqm_validate_number( $f, $val ) {
	$raw  = trim( (string) $val );
	$fail = static function ( $msg ) {
		return array( 'error' => $msg, 'value' => '' );
	};

	if ( ! is_numeric( $raw ) ) {
		return $fail( 'Please enter a number for "' . $f->label . '".' );
	}

	$num   = $raw + 0;
	$whole = 1 === (int) aqm_num_opt( $f, 'num_whole', 1 );

	if ( $whole && floor( $num ) != $num ) { // phpcs:ignore
		return $fail( 'Please enter a whole number for "' . $f->label . '" - no decimals.' );
	}

	$min = aqm_num_opt( $f, 'num_min', '' );
	$max = aqm_num_opt( $f, 'num_max', '' );

	// The editor drops an impossible range on save, but a row can also arrive
	// by other means. A maximum below the minimum can never be satisfied, so
	// ignore it rather than reject everything the visitor types.
	if ( '' !== $min && '' !== $max && is_numeric( $min ) && is_numeric( $max ) && $min + 0 > $max + 0 ) {
		$max = '';
	}

	if ( '' !== $min && is_numeric( $min ) && $num < $min + 0 ) {
		return $fail( 'Please enter ' . $min . ' or more for "' . $f->label . '".' );
	}
	if ( '' !== $max && is_numeric( $max ) && $num > $max + 0 ) {
		return $fail( 'Please enter ' . $max . ' or fewer for "' . $f->label . '".' );
	}

	// Normalise. is_numeric() accepts " 5" and "1e3"; neither is what anyone
	// means by a seat count, and both would look strange in a CSV export.
	return array( 'error' => '', 'value' => $whole ? (string) (int) $num : (string) ( $num + 0 ) );
}

function aqm_norm_label( $text ) {
	$text = strtolower( wp_strip_all_tags( (string) $text ) );
	return ' ' . trim( preg_replace( '/[^a-z0-9%]+/', ' ', $text ) ) . ' ';
}

/**
 * Read a number setting off a field row, tolerating rows that predate the
 * 7.3.0 columns.
 *
 * @param object $f    Field row.
 * @param string $prop Property name.
 * @param mixed  $else Fallback.
 * @return mixed
 */
function aqm_num_opt( $f, $prop, $else = '' ) {
	return aqm_col( $f, $prop, $else );
}

/**
 * Read a column that may not exist yet.
 *
 * dbDelta runs on the next admin page load, not on activation, so between an
 * upgrade and that load a row object can be missing its newest columns. Every
 * read of help_text, default_value, form_intro and the num_* set goes through
 * here, so a half-upgraded site renders instead of throwing a warning.
 *
 * @param object $row  Row object from $wpdb.
 * @param string $prop Column name.
 * @param mixed  $else Value to use when the column is absent or null.
 * @return mixed
 */
function aqm_col( $row, $prop, $else = '' ) {
	if ( ! is_object( $row ) || ! isset( $row->$prop ) ) {
		return $else;
	}
	return $row->$prop;
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

		if ( 'multiselect' === $f->field_type ) {
			// Arrives as an array of ticked labels, plus one free-text box for
			// anything not on the list. It stays an ARRAY all the way through
			// validation: joining now and splitting later would come apart the
			// moment an option label contains a comma. sanitize_text_field()
			// would also raise a TypeError if handed the array directly.
			$picked = is_array( $raw ) ? $raw : array();
			$picked = array_slice( $picked, 0, 50 );
			$picked = array_map( 'sanitize_text_field', array_map( 'strval', $picked ) );

			$other = isset( $_POST[ $name . '_other' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name . '_other' ] ) ) : ''; // phpcs:ignore
			if ( '' !== trim( $other ) ) {
				$picked[] = trim( $other );
			}
			$values[ $f->field_key ] = $picked;
		} elseif ( 'textarea' === $f->field_type ) {
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

		if ( $f->required && aqm_value_is_blank( $val ) ) {
			if ( 'multiselect' === $f->field_type ) {
				$errors[ $key ] = 'Please choose at least one option for "' . $f->label . '".';
			} elseif ( 'checkbox' === $f->field_type ) {
				// A tick box is not "completed". Consent boxes are the common
				// case here and the wording has to make sense on one.
				$errors[ $key ] = 'Please tick the box to continue.';
			} else {
				$errors[ $key ] = 'Please complete "' . $f->label . '".';
			}
			continue;
		}

		// Tick boxes are resolved against the real option list so a tampered
		// value cannot store arbitrary text - except through the free-text box,
		// which exists precisely to allow it.
		if ( 'multiselect' === $f->field_type ) {
			$allowed = array();
			foreach ( aqm_get_options( $f->id ) as $opt ) {
				$allowed[ strtolower( $opt->label ) ] = $opt->label;
			}

			$clean = array();
			foreach ( (array) $val as $one ) {
				$one = trim( (string) $one );
				if ( '' === $one ) {
					continue;
				}
				if ( mb_strlen( $one ) > 200 ) {
					$one = mb_substr( $one, 0, 200 );
				}
				$lower = strtolower( $one );
				// Restore the stored capitalisation for anything on the list,
				// so "peanut" and "Peanut" never both appear in a report.
				$clean[ $lower ] = isset( $allowed[ $lower ] ) ? $allowed[ $lower ] : $one;
			}
			$val = array_values( $clean );
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

		if ( 'number' === $f->field_type && '' !== trim( (string) $val ) ) {
			$checked = aqm_validate_number( $f, $val );
			if ( '' !== $checked['error'] ) {
				$errors[ $key ] = $checked['error'];
				continue;
			}
			$val = $checked['value'];
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

		if ( ! is_array( $val ) && mb_strlen( (string) $val ) > 5000 ) {
			$errors[ $key ] = 'That is longer than 5,000 characters. Please shorten it a little.';
			continue;
		}

		// Joined only now, at the very last moment. Everything downstream -
		// the emails, the CSV export, the Submissions screen, the recap - keeps
		// receiving a plain string and needs no knowledge of this type.
		$stored[ $key ] = is_array( $val ) ? implode( ', ', $val ) : $val;
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
			'summary' => aqm_recap_pairs( $fields, $stored ),
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

/**
 * The name an email appears to come FROM.
 *
 * Defaults to the site title, which is right for most sites. It is separate
 * from the address, and separate from the {site_name} token, because they
 * answer different questions: the token means "which website is this", the
 * From name means "who is writing to me". A site whose title is a domain,
 * or a trading name, or anything a person would not expect to see in their
 * inbox, wants those to differ.
 *
 *     add_filter( 'aqm_from_name', fn() => 'Jane Smith' );
 */
function aqm_mail_from_name() {
	$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	return apply_filters( 'aqm_from_name', $name );
}

/**
 * The grey line of guidance under a field.
 *
 * One place decides what a visitor reads, so a combobox, a number with a
 * range, and a plain field with help text all behave the same way. Author's
 * help text always comes first; anything generated is appended, never
 * substituted, so writing your own wording never hides the range.
 *
 * @param object $f Field row.
 * @return string Plain text, may be empty.
 */
/**
 * Is this answer empty?
 *
 * A multiselect's value is an array right up until it is stored, so the
 * required-field test cannot simply trim() it - PHP 8 raises a TypeError when
 * an array is cast to string.
 *
 * @param mixed $val Collected value.
 * @return bool
 */
function aqm_value_is_blank( $val ) {
	if ( is_array( $val ) ) {
		foreach ( $val as $one ) {
			if ( '' !== trim( (string) $one ) ) {
				return false;
			}
		}
		return true;
	}
	return '' === trim( (string) $val );
}

/* ── Consent links ──────────────────────────────────────────────────────────
   Consent wording nearly always has to point somewhere: at a privacy policy,
   and at how to stop hearing from you. Hard-coding either would tie this
   plugin to one site, so both are tokens the author writes into ordinary
   text, resolved at render time.

     {privacy_policy}  the site's WordPress privacy policy page
     {optout}          the opt-out page set under AQM Contact -> Settings

   The privacy policy deliberately comes from WordPress core rather than a
   field of our own. Every WordPress site already has that setting under
   Settings -> Privacy, core can generate a starter policy, and other plugins
   read the same value - so there is nothing here to keep in sync.

   Both tokens degrade to plain words when nothing is configured, so a form
   never shows a broken link or an empty href.

   SECURITY: the author's text is escaped FIRST, then the tokens - which
   survive escaping unchanged - are swapped for markup. That way an admin can
   produce exactly these two links and no other HTML.
*/

function aqm_privacy_url() {
	return function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';
}

function aqm_optout_url() {
	return (string) get_option( 'aqm_optout_url', '' );
}

function aqm_consent_tokens() {
	return array(
		'{privacy_policy}' => array( aqm_privacy_url(), 'Privacy Policy' ),
		'{optout}'         => array( aqm_optout_url(), 'opt out' ),
	);
}

/**
 * Escape author text, then resolve the consent tokens.
 *
 * @param string $text  Author-written text.
 * @param bool   $plain True for email bodies, which are text/plain - a URL in
 *                      brackets is readable there; an anchor tag is not.
 * @return string
 */
function aqm_linkify( $text, $plain = false ) {
	$out = $plain ? (string) $text : esc_html( (string) $text );

	foreach ( aqm_consent_tokens() as $token => $pair ) {
		list( $url, $label ) = $pair;

		if ( $plain ) {
			$replacement = $url ? $label . ' (' . $url . ')' : $label;
		} elseif ( '' !== $url ) {
			$replacement = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
				. esc_html( $label ) . '</a>';
		} else {
			$replacement = esc_html( $label );
		}

		$out = str_replace( $token, $replacement, $out );
	}

	return $out;
}

/**
 * Does this text promise a privacy policy the site does not publish?
 *
 * A real case: a live contact form asked people to agree to a "Privacy
 * Policy" while every privacy URL on the site returned 404. Nobody noticed
 * because nothing checks. This does.
 */
function aqm_promises_missing_privacy( $text ) {
	$text = (string) $text;
	if ( '' === trim( $text ) ) {
		return false;
	}
	$mentions = ( false !== stripos( $text, 'privacy policy' ) )
		|| ( false !== strpos( $text, '{privacy_policy}' ) );

	return $mentions && '' === aqm_privacy_url();
}


function aqm_field_hint( $f ) {
	$parts = array();
	$help  = trim( (string) aqm_col( $f, 'help_text' ) );

	if ( '' !== $help ) {
		$parts[] = $help;
	}

	// A combobox is the one type whose behaviour is not obvious from looking
	// at it - the list is a set of suggestions, not a closed set of answers.
	// Say so, unless the author has already explained it in their own words.
	if ( 'combobox' === $f->field_type && '' === $help ) {
		$parts[] = $f->required
			? 'Choose one from the list, or type your own.'
			: 'Choose one from the list, or type your own. Leave blank if it does not apply.';
	}

	if ( 'multiselect' === $f->field_type && '' === $help ) {
		$parts[] = $f->required
			? 'Tick everything that applies, and add anything not listed in the box.'
			: 'Tick everything that applies, or add your own in the box. Leave blank if none apply.';
	}

	// Say the range in words. Without this the visitor meets the browser's
	// own "Value must be less than or equal to 20" only AFTER getting it
	// wrong - this prevents the error instead of explaining it.
	if ( 'number' === $f->field_type ) {
		$min   = (string) aqm_num_opt( $f, 'num_min', '' );
		$max   = (string) aqm_num_opt( $f, 'num_max', '' );
		$range = '';
		if ( '' !== $min && '' !== $max ) {
			$range = 'Between ' . $min . ' and ' . $max . '.';
		} elseif ( '' !== $min ) {
			$range = $min . ' or more.';
		} elseif ( '' !== $max ) {
			$range = 'Up to ' . $max . '.';
		}
		// Skip it when the author's own wording already carries the numbers,
		// so "Between 1 and 20" is never printed twice in different words.
		if ( '' !== $range && false === stripos( $help, trim( $range, '.' ) ) ) {
			$parts[] = $range;
		}
	}

	return implode( ' ', $parts );
}

/**
 * Label/value pairs to show the visitor after a successful send.
 *
 * Blank answers are dropped - a list of "Not provided" tells nobody anything.
 * This is what turns "your message has been received" into "we have you down
 * for 3 people", which is the difference between a person trusting the form
 * and a person emailing to ask whether it worked.
 *
 * @param array $fields Field rows.
 * @param array $data   Stored values, keyed by field key.
 * @return array<int,array{0:string,1:string}>
 */
function aqm_recap_pairs( $fields, array $data ) {
	$pairs = array();
	foreach ( $fields as $f ) {
		$value = trim( (string) ( $data[ $f->field_key ] ?? '' ) );
		if ( '' === $value ) {
			continue;
		}
		$pairs[] = array( (string) $f->label, $value );
	}
	return $pairs;
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
		'From: ' . aqm_mail_from_name() . ' <' . aqm_mail_from_address() . '>',
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
		'From: ' . aqm_mail_from_name() . ' <' . aqm_mail_from_address() . '>',
	);
	if ( is_email( $form->notify_email ) ) {
		$headers[] = 'Reply-To: ' . $form->notify_email;
	}

	wp_mail(
		$to,
		strtr( $form->autoreply_subject ? $form->autoreply_subject : 'We received your message', $vars ),
		aqm_linkify( strtr( $form->autoreply_body ? $form->autoreply_body : aqm_default_autoreply_body(), $vars ), true ),
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
.aqm-form-wrap .aqm-help{display:block;font-size:12.5px;line-height:1.45;color:#5f6b64;margin-top:5px;font-family:Arial,sans-serif}
.aqm-form-wrap .aqm-counter{display:block;font-size:11.5px;color:#8a8a8a;margin-top:4px;text-align:right;font-family:Arial,sans-serif}
.aqm-form-wrap .aqm-counter--over{color:#b32d2e;font-weight:700}
.aqm-form-wrap .aqm-intro{margin:0 0 20px;padding:14px 18px;background:#f6f8f4;border-left:3px solid #1a4b6e;border-radius:4px;font-size:14.5px;line-height:1.6;color:#3c4a41;font-family:Arial,sans-serif}
.aqm-form-wrap .aqm-intro p{margin:0 0 8px}
.aqm-form-wrap .aqm-intro p:last-child{margin-bottom:0}
.aqm-form-wrap .aqm-recap{margin:14px 0 0;border:1px solid #dbe3dd;border-radius:4px;padding:14px 18px;background:#fbfcfb;font-family:Arial,sans-serif}
.aqm-form-wrap .aqm-recap h4{margin:0 0 8px;font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#5f6b64}
.aqm-form-wrap .aqm-recap dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:4px 16px;font-size:13.5px}
.aqm-form-wrap .aqm-recap dt{color:#767676}
.aqm-form-wrap .aqm-recap dd{margin:0;color:#2b352e;font-weight:600;word-break:break-word}
@media(max-width:480px){.aqm-form-wrap .aqm-recap dl{grid-template-columns:1fr;gap:0 0}
.aqm-form-wrap .aqm-recap dd{margin-bottom:8px}}
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
.aqm-form-wrap .aqm-multi{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:6px 18px;padding:12px 14px;border:1px solid var(--aqm-border);border-radius:6px;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,.06)}
.aqm-form-wrap .aqm-multi .aqm-chk{padding:5px 2px;min-height:34px}
.aqm-form-wrap .aqm-group--invalid .aqm-multi{border-color:var(--aqm-error);box-shadow:0 0 0 3px rgba(192,57,43,.12)}
.aqm-form-wrap input.aqm-multi-other{margin-top:8px}
@media(max-width:480px){.aqm-form-wrap .aqm-multi{grid-template-columns:1fr}}
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

		<?php
		if ( 'success' === $status ) :
			$recap = ( $mine && isset( $flash['summary'] ) && is_array( $flash['summary'] ) ) ? $flash['summary'] : array();
			?>
			<?php if ( $recap ) : ?>
				<div class="aqm-recap">
					<h4>What you sent</h4>
					<dl>
						<?php foreach ( $recap as $row ) : ?>
							<dt><?php echo esc_html( $row[0] ); ?></dt>
							<dd><?php echo esc_html( $row[1] ); ?></dd>
						<?php endforeach; ?>
					</dl>
				</div>
			<?php endif; ?>
			<p class="aqm-note" style="margin-top:14px">If you need to send another message, please refresh this page.</p>
		<?php elseif ( ! $fields ) : ?>
			<div class="aqm-alert aqm-alert--error">
				<span class="aqm-icon" aria-hidden="true">!</span>
				<span>This form is not available at the moment. Please contact us by telephone or email.</span>
			</div>
		<?php else : ?>

		<?php
		// Sanitised with sanitize_textarea_field() on the way in, so it holds
		// no markup. wpautop() on already-escaped text gives paragraphs
		// without giving anyone a way in.
		$intro = trim( (string) aqm_col( $form, 'form_intro' ) );
		if ( '' !== $intro ) :
			?>
			<div class="aqm-intro"><?php echo wp_kses_post( wpautop( aqm_linkify( $intro ) ) ); ?></div>
		<?php endif; ?>

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
			$wide  = array( 'textarea', 'checkbox', 'combobox', 'multiselect' );

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
					// A multiselect keeps an array here on the way back from a
					// validation error. Casting it would warn and print "Array".
					$raw_val  = $values[ $f->field_key ] ?? '';
					$picked   = is_array( $raw_val ) ? array_map( 'strval', $raw_val ) : array();
					$value    = is_array( $raw_val ) ? '' : (string) $raw_val;
					$invalid  = isset( $errors[ $f->field_key ] );
					$err_id   = $id . '_error';
					$hint     = aqm_field_hint( $f );
					$hint_id  = $id . '_hint';

					// A screen reader has to hear the guidance as well as the
					// error. 7.3.0 pointed aria-describedby at the error alone,
					// so help text would have been announced to nobody.
					$ids = array();
					if ( '' !== $hint ) {
						$ids[] = $hint_id;
					}
					if ( $invalid ) {
						$ids[] = $err_id;
					}
					$describe  = $ids ? ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"' : '';
					$describe .= $invalid ? ' aria-invalid="true"' : '';

					$required = $f->required ? ' required aria-required="true"' : '';
					$auto     = aqm_autocomplete_for( $f );

					// A default value belongs to a FRESH form. On the way back
					// from a validation error $values holds what the visitor
					// actually typed, and re-injecting a default there would
					// silently undo their deletion.
					$prefill = '';
					if ( ! $mine ) {
						$prefill = (string) aqm_col( $f, 'default_value' );
						if ( 'number' === $f->field_type ) {
							$prefill = (string) aqm_num_opt( $f, 'num_default', $prefill );
						}
					}
					if ( '' === $value && '' !== $prefill && ! in_array( $f->field_type, array( 'select', 'checkbox', 'multiselect' ), true ) ) {
						$value = $prefill;
					}
					?>
					<?php
					// A group of tick boxes has no single control to label, so
					// "for" would point at an id that does not exist. Name the
					// label instead and let the group reference it.
					$is_group = ( 'multiselect' === $f->field_type );
					?>
					<div class="aqm-group<?php echo $invalid ? ' aqm-group--invalid' : ''; ?>">
						<label <?php echo $is_group
							? 'id="' . esc_attr( $id ) . '_label"'
							: 'for="' . esc_attr( $id ) . '"'; // phpcs:ignore
						?>>
							<?php echo esc_html( $f->label ); ?>
							<?php if ( $f->required ) : ?>
								<span class="aqm-req" aria-hidden="true">*</span>
							<?php else : ?>
								<span class="aqm-opt">(optional)</span>
							<?php endif; ?>
						</label>

						<?php if ( 'textarea' === $f->field_type ) : ?>
							<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
								rows="5" maxlength="5000" data-aqm-counter="5000"
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
							</div>

						<?php elseif ( 'multiselect' === $f->field_type ) : ?>
							<?php $opts = aqm_get_options( $f->id ); ?>
							<div class="aqm-multi" role="group"
								aria-labelledby="<?php echo esc_attr( $id ); ?>_label"
								<?php echo $describe; // phpcs:ignore ?>>
								<?php foreach ( $opts as $oi => $opt ) : ?>
									<label class="aqm-chk">
										<input type="checkbox"
											name="<?php echo esc_attr( $name ); ?>[]"
											value="<?php echo esc_attr( $opt->label ); ?>"
											<?php checked( true, in_array( $opt->label, $picked, true ) ); ?>>
										<?php echo esc_html( $opt->label ); ?>
									</label>
								<?php endforeach; ?>
							</div>
							<?php
							// Anything the visitor typed rather than ticked. It
							// comes back in $picked as an entry matching no
							// option, so it belongs in this box, not lost.
							$known = array();
							foreach ( $opts as $opt ) {
								$known[] = $opt->label;
							}
							$typed = implode( ', ', array_diff( $picked, $known ) );
							?>
							<input type="text" class="aqm-multi-other"
								id="<?php echo esc_attr( $id ); ?>"
								name="<?php echo esc_attr( $name ); ?>_other"
								maxlength="200" autocomplete="off"
								value="<?php echo esc_attr( $typed ); ?>"
								placeholder="<?php echo esc_attr( $f->placeholder ? $f->placeholder : 'Something else? Type it here' ); ?>"
								aria-label="<?php echo esc_attr( $f->label ); ?> &mdash; anything not listed above">

						<?php elseif ( 'checkbox' === $f->field_type ) : ?>
							<label class="aqm-chk">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="Yes"
									<?php checked( 'Yes', $value ); ?>
									<?php echo $required . $describe; // phpcs:ignore ?>>
								<?php echo wp_kses_post( aqm_linkify( $f->placeholder ? $f->placeholder : $f->label ) ); ?>
							</label>

						<?php else : ?>
							<?php
							// Number fields carry their range and step. Purely a
							// convenience - aqm_handle_submission() does the enforcing.
							$numattr = '';
							if ( 'number' === $f->field_type ) {
								$nmin = aqm_num_opt( $f, 'num_min', '' );
								$nmax = aqm_num_opt( $f, 'num_max', '' );
								if ( 1 === (int) aqm_num_opt( $f, 'num_whole', 1 ) ) {
									$numattr .= ' step="1" inputmode="numeric"';
								}
								if ( '' !== $nmin ) {
									$numattr .= ' min="' . esc_attr( $nmin ) . '"';
								}
								if ( '' !== $nmax ) {
									$numattr .= ' max="' . esc_attr( $nmax ) . '"';
								}
							}
							?>
							<input type="<?php echo esc_attr( $f->field_type ); ?>"
								id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
								<?php echo $numattr; // phpcs:ignore ?>
								maxlength="200"
								placeholder="<?php echo esc_attr( $f->placeholder ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
								<?php echo $auto ? 'autocomplete="' . esc_attr( $auto ) . '"' : ''; ?>
								<?php echo $required . $describe; // phpcs:ignore ?>>
							<?php if ( 'email' === $f->field_type && ! $invalid && '' === $hint ) : ?>
								<span class="aqm-hint">We will never share your email address.</span>
							<?php endif; ?>
						<?php endif; ?>

						<?php if ( '' !== $hint ) : ?>
							<span class="aqm-help" id="<?php echo esc_attr( $hint_id ); ?>"><?php echo wp_kses_post( aqm_linkify( $hint ) ); ?></span>
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

	<script>
	/* Character counter. Progressive enhancement only - maxlength already
	   stops the typing; this explains WHY it stopped. */
	(function () {
		var wrap = document.getElementById('aqm-form-<?php echo (int) $form_id; ?>');
		if (!wrap) { return; }
		var boxes = wrap.querySelectorAll('textarea[data-aqm-counter]');
		Array.prototype.forEach.call(boxes, function (box) {
			var max = parseInt(box.getAttribute('data-aqm-counter'), 10) || 0;
			if (!max) { return; }
			var out = document.createElement('span');
			out.className = 'aqm-counter';
			out.setAttribute('aria-hidden', 'true');
			box.parentNode.insertBefore(out, box.nextSibling);
			function tick() {
				var left = max - box.value.length;
				out.textContent = left > 300 ? '' : left + ' characters left';
				out.className = 'aqm-counter' + (left <= 0 ? ' aqm-counter--over' : '');
			}
			box.addEventListener('input', tick);
			tick();
		});
	})();
	</script>

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
/**
 * Turn one pasted block into a list of option labels.
 *
 * Separators are the newline and the three characters nobody puts inside a
 * label: the middot, the pipe and the semicolon. THE COMMA IS DELIBERATELY
 * NOT ONE OF THEM - plenty of perfectly ordinary labels contain one ("Yes,
 * with a guest"), and a splitter that guesses would destroy them silently.
 * Anyone who wants comma-separated entry can press Enter instead.
 *
 * @param string $raw Pasted text.
 * @return string[] Trimmed, de-duplicated, in the order given.
 */
function aqm_split_options( $raw ) {
	$parts = preg_split( '/[\r\n\x{00B7}|;]+/u', (string) $raw );
	if ( ! is_array( $parts ) ) {
		return array();
	}

	$out  = array();
	$seen = array();
	foreach ( $parts as $part ) {
		// A list pasted from a document often arrives with bullets or dashes
		// still attached; strip those rather than storing "- Second choice".
		$one = sanitize_text_field( $part );
		$one = preg_replace( '/^[\s\x{2022}\x{00B7}\-\x{2013}\x{2014}]+|[\s\x{2022}\x{00B7}\-\x{2013}\x{2014}]+$/u', '', $one );
		$one = trim( (string) $one );
		if ( '' === $one ) {
			continue;
		}
		if ( function_exists( 'mb_substr' ) ) {
			$one = mb_substr( $one, 0, 120 );
		} else {
			$one = substr( $one, 0, 120 );
		}
		$key = strtolower( $one );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$out[]        = $one;
	}
	return $out;
}

function aqm_notice_text( $code ) {
	$map = array(
		'form_created'      => array( 'success', 'Form created with default fields.' ),
		'form_deleted'      => array( 'success', 'Form deleted.' ),
		'form_duplicated'   => array( 'success', 'Form duplicated.' ),
		'form_name_empty'   => array( 'error', 'Please enter a form name.' ),
		'settings_saved'    => array( 'success', 'Settings saved.' ),
		'field_saved'       => array( 'success', 'Field saved.' ),
		'field_deleted'     => array( 'success', 'Field deleted.' ),
		'field_shown'       => array( 'success', 'Field is now visible on the form.' ),
		'field_hidden'      => array( 'success', 'Field is now hidden from the form.' ),
		'field_required'    => array( 'success', 'Field marked as required.' ),
		'field_optional'    => array( 'success', 'Field marked as optional.' ),
		'field_empty'       => array( 'error', 'The field label cannot be empty.' ),
		'option_saved'      => array( 'success', 'Option saved.' ),
		'option_deleted'    => array( 'success', 'Option deleted.' ),
		'option_empty'      => array( 'error', 'The option label cannot be empty.' ),
		'options_added'     => array( 'success', 'Options added.' ),
		'options_duplicate' => array( 'error', 'Nothing was added - every one of those is already on the list.' ),
		'entries_deleted'   => array( 'success', 'Selected submissions deleted.' ),
		'ips_purged'        => array( 'success', 'Stored IP addresses cleared. No submissions were deleted.' ),
		'delete_failed'     => array( 'error', 'The submissions could not be deleted - a database error was logged.' ),
		'nothing_picked'    => array( 'error', 'Nothing was selected.' ),
	);
	return $map[ $code ] ?? null;
}

function aqm_render_notice() {
	if ( empty( $_GET['aqm_msg'] ) ) {
		return;
	}
	$code   = sanitize_key( wp_unslash( $_GET['aqm_msg'] ) );
	$notice = aqm_notice_text( $code );
	if ( ! $notice ) {
		return;
	}

	$text = $notice[1];
	if ( 'options_added' === $code ) {
		$n    = max( 1, (int) ( $_GET['aqm_n'] ?? 1 ) );
		$text = 1 === $n ? 'One option added.' : sprintf( '%d options added.', $n );
	}

	echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
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

			/* Number options: shown only for Number fields, with "whole numbers
			   only" pre-set from the label. The guess stops the moment the user
			   touches the box - after that the choice is theirs. Normalising the
			   same way aqm_norm_label() does keeps the two in agreement. */
			var FRAC=' . wp_json_encode( array_values( aqm_fraction_words() ) ) . ',
			    CNT=' . wp_json_encode( array_values( aqm_count_phrases() ) ) . ';
			var $t=$("#aqm_field_type"),$box=$("#aqm-num-opts"),
			    $whole=$("#aqm_num_whole"),$lab=$("#aqm_field_label");
			if($t.length&&$box.length){
				var touched=false;
				$whole.on("change",function(){touched=true;});
				function norm(s){return " "+String(s||"").toLowerCase().replace(/[^a-z0-9%]+/g," ").replace(/^ +| +$/g,"")+" ";}
				function guess(){
					var t=norm($lab.val()),i;
					for(i=0;i<CNT.length;i++){if(t.indexOf(norm(CNT[i]).replace(/^ +| +$/g,""))>-1)return true;}
					if(t.indexOf("%")>-1)return false;
					for(i=0;i<FRAC.length;i++){if(t.indexOf(" "+FRAC[i]+" ")>-1)return false;}
					return true;
				}
				function sync(){
					var isNum=$t.val()==="number";
					$box.toggle(isNum);
					if(isNum&&!touched){$whole.prop("checked",guess());}
				}
				$t.on("change",sync);$lab.on("input",sync);sync();
			}

			/* The type list on the right was decoration that looked like a
			   control. Make it do the thing it looks like it does. */
			var $pick=$(".aqm-type-pick");
			if($t.length&&$pick.length){
				function markType(){
					var v=$t.val();
					$pick.each(function(){$(this).toggleClass("is-on",$(this).attr("data-type")===v);});
				}
				$pick.on("click",function(){
					$t.val($(this).attr("data-type")).trigger("change");
					markType();
					$t.trigger("focus");
				});
				$t.on("change",markType);
				markType();
			}

			/* "Placeholder" means something different on a checkbox: it is the
			   wording BESIDE the box, not a hint inside it. One label on two
			   jobs is how the confusion starts, so relabel it. */
			var $phL=$("#aqm_ph_label"),$phH=$("#aqm_ph_help"),
			    $ph=$("#aqm_field_placeholder"),$def=$("#aqm-default-wrap");
			if($t.length&&$phL.length){
				function syncPh(){
					var v=$t.val();
					if(v==="checkbox"){
						$phL.text("Text beside the tick box");
						$phH.html("What the visitor reads next to the checkbox. Leave blank to reuse the label.");
						$ph.attr("placeholder","e.g. Yes, add me to the mailing list");
					}else{
						$phL.text("Placeholder");
						$phH.html("Grey example text inside the box. It vanishes as soon as they type and is <strong>never submitted</strong>. It is not a default value.");
						$ph.attr("placeholder","e.g. nut allergy");
					}
					/* A default has no meaning where the visitor picks from a
					   list or ticks a box, and Number keeps its own Default in
					   the box above. Hide it rather than let it be set and
					   silently ignored. */
					$def.toggle(v!=="checkbox"&&v!=="select"&&v!=="multiselect"&&v!=="number");
				}
				$t.on("change",syncPh);syncPh();
			}
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
		.aqm-type-pick{display:flex;gap:8px;margin-bottom:5px;align-items:center;width:100%;text-align:left;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:5px 8px;cursor:pointer;font-size:12px;line-height:1.4}
		.aqm-type-pick:hover{background:#f0f6fc;border-color:#0073aa}
		.aqm-type-pick.is-on{border-color:#1a56db;background:#f5f8ff;box-shadow:0 0 0 2px rgba(26,86,219,.18)}
		.aqm-type-pick:focus{outline:2px solid #1a56db;outline-offset:1px}
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
					'form_intro'        => aqm_col( $src, 'form_intro' ),
					'captcha_enabled'   => $src->captcha_enabled,
					'spam_protection'   => $src->spam_protection,
					'autoreply_enabled' => $src->autoreply_enabled,
					'autoreply_subject' => $src->autoreply_subject,
					'autoreply_body'    => $src->autoreply_body,
					'success_message'   => $src->success_message,
					'store_ip'          => $src->store_ip,
					'created_at'        => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
			);
			$new_id = (int) $wpdb->insert_id;

			foreach ( aqm_get_fields( $src->id ) as $sf ) {
				$wpdb->insert(
					aqm_table( 'form_fields' ),
					// 7.3.0 copied only the first eight columns, so duplicating a
					// form quietly reset every number field's whole/min/max/default
					// back to the defaults. Copy the whole field.
					array(
						'form_id'       => $new_id,
						'field_key'     => $sf->field_key,
						'label'         => $sf->label,
						'field_type'    => $sf->field_type,
						'placeholder'   => $sf->placeholder,
						'help_text'     => aqm_col( $sf, 'help_text' ),
						'default_value' => aqm_col( $sf, 'default_value' ),
						'required'      => $sf->required,
						'enabled'       => $sf->enabled,
						'sort_order'    => $sf->sort_order,
						'num_whole'     => (int) aqm_col( $sf, 'num_whole', 1 ),
						'num_min'       => aqm_col( $sf, 'num_min' ),
						'num_max'       => aqm_col( $sf, 'num_max' ),
						'num_default'   => aqm_col( $sf, 'num_default' ),
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
				);
				$new_field_id = (int) $wpdb->insert_id;

				if ( in_array( $sf->field_type, aqm_option_types(), true ) ) {
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
		'text'        => 'Text',
		'email'       => 'Email (validated)',
		'tel'         => 'Telephone',
		'number'      => 'Number',
		'url'         => 'Website URL',
		'date'        => 'Date Picker',
		'select'      => 'Dropdown (pick only)',
		'combobox'    => 'Combobox (pick OR type freely)',
		'multiselect' => 'Checkboxes (pick several)',
		'textarea'    => 'Text Area',
		'checkbox'    => 'Checkbox',
	);
}

/**
 * Field types whose answers come from the options table.
 *
 * One list, so adding a type never means hunting for the six places that
 * decide whether an Options button appears, whether options are copied on
 * duplicate, and whether a submitted value is checked against them.
 *
 * @return string[]
 */
function aqm_option_types() {
	return array( 'select', 'combobox', 'multiselect' );
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
				'notify_email'      => is_email( $email ) ? $email : '',
				'notify_cc'         => implode( ', ', $cc ),
				'email_subject'     => isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '',
				'form_intro'        => isset( $_POST['form_intro'] ) ? sanitize_textarea_field( wp_unslash( $_POST['form_intro'] ) ) : '',
				'success_message'   => isset( $_POST['success_message'] ) ? sanitize_text_field( wp_unslash( $_POST['success_message'] ) ) : '',
				'autoreply_subject' => isset( $_POST['autoreply_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['autoreply_subject'] ) ) : '',
				'autoreply_body'    => isset( $_POST['autoreply_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['autoreply_body'] ) ) : '',
				'captcha_enabled'   => empty( $_POST['captcha_enabled'] ) ? 0 : 1,
				'spam_protection'   => empty( $_POST['spam_protection'] ) ? 0 : 1,
				'store_ip'          => empty( $_POST['store_ip'] ) ? 0 : 1,
				'autoreply_enabled' => empty( $_POST['autoreply_enabled'] ) ? 0 : 1,
			),
			array( 'id' => $form_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ),
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
		$help  = isset( $_POST['aqm_field_help'] ) ? sanitize_text_field( wp_unslash( $_POST['aqm_field_help'] ) ) : '';
		$defv  = isset( $_POST['aqm_field_default'] ) ? sanitize_text_field( wp_unslash( $_POST['aqm_field_default'] ) ) : '';
		$req   = empty( $_POST['aqm_field_required'] ) ? 0 : 1;
		$edit  = (int) ( $_POST['aqm_edit_id'] ?? 0 );

		// Number settings. The editor only shows these for Number fields, but a
		// field can be switched to Number from another type, in which case the
		// block was never rendered - fall back to the label guess rather than
		// silently leaving a count able to accept 3.5.
		$has_num = isset( $_POST['aqm_num_submitted'] );
		$whole   = $has_num ? ( empty( $_POST['aqm_num_whole'] ) ? 0 : 1 ) : aqm_guess_whole_number( $label );
		$numclean = static function ( $k ) {
			$v = isset( $_POST[ $k ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ) : ''; // phpcs:ignore
			return is_numeric( $v ) ? $v : '';
		};
		$nmin = $numclean( 'aqm_num_min' );
		$nmax = $numclean( 'aqm_num_max' );
		$ndef = $numclean( 'aqm_num_default' );

		// A minimum above the maximum can never be satisfied - drop the max.
		if ( '' !== $nmin && '' !== $nmax && $nmin + 0 > $nmax + 0 ) {
			$nmax = '';
		}

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
					'label'         => $label,
					'field_type'    => $type,
					'placeholder'   => $ph,
					'help_text'     => $help,
					'default_value' => $defv,
					'required'      => $req,
					'num_whole'     => $whole,
					'num_min'       => $nmin,
					'num_max'       => $nmax,
					'num_default'   => $ndef,
				),
				array( 'id' => $edit, 'form_id' => $form_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ),
				array( '%d', '%d' )
			);
		} else {
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM $ftable WHERE form_id = %d", $form_id ) ); // phpcs:ignore
			$wpdb->insert(
				$ftable,
				array(
					'form_id'       => $form_id,
					'field_key'     => aqm_unique_key( $label, $form_id ),
					'label'         => $label,
					'field_type'    => $type,
					'placeholder'   => $ph,
					'help_text'     => $help,
					'default_value' => $defv,
					'required'      => $req,
					'enabled'       => 1,
					'sort_order'    => $max + 1,
					'num_whole'     => $whole,
					'num_min'       => $nmin,
					'num_max'       => $nmax,
					'num_default'   => $ndef,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
			);
		}

		$redirect( 'field_saved' );
	}

	/* ---- Save option ---- */
	if ( isset( $_POST['aqm_option_nonce'] ) ) {
		check_admin_referer( 'aqm_save_option_' . $form_id, 'aqm_option_nonce' );

		// sanitize_textarea_field(), not sanitize_text_field(): the latter
		// flattens newlines, which are the separator that matters most here.
		$raw = isset( $_POST['aqm_opt_label'] ) ? sanitize_textarea_field( wp_unslash( $_POST['aqm_opt_label'] ) ) : '';
		$fld = (int) ( $_POST['aqm_opt_field_id'] ?? 0 );
		$oid = (int) ( $_POST['aqm_opt_edit_id'] ?? 0 );

		if ( '' === trim( $raw ) ) {
			$redirect( 'option_empty', array( 'options_for' => $fld ) );
		}

		if ( $oid ) {
			// Editing one option edits exactly one option. Splitting here would
			// turn a correction into a silent multiplication.
			$wpdb->update( $otable, array( 'label' => sanitize_text_field( $raw ) ), array( 'id' => $oid ), array( '%s' ), array( '%d' ) );
			$redirect( 'option_saved', array( 'options_for' => $fld ) );
		}

		$labels = aqm_split_options( $raw );
		if ( ! $labels ) {
			$redirect( 'option_empty', array( 'options_for' => $fld ) );
		}

		// Skip anything already on the list, so pasting a corrected list over
		// a partial one tops it up instead of doubling it.
		$existing = array();
		foreach ( aqm_get_options( $fld ) as $have ) {
			$existing[ strtolower( $have->label ) ] = true;
		}

		$max   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM $otable WHERE field_id = %d", $fld ) ); // phpcs:ignore
		$added = 0;
		foreach ( $labels as $one ) {
			if ( isset( $existing[ strtolower( $one ) ] ) ) {
				continue;
			}
			$existing[ strtolower( $one ) ] = true;
			$max++;
			$added++;
			$wpdb->insert( $otable, array( 'field_id' => $fld, 'label' => $one, 'sort_order' => $max ), array( '%d', '%s', '%d' ) );
		}

		if ( ! $added ) {
			$redirect( 'options_duplicate', array( 'options_for' => $fld ) );
		}

		$redirect( 'options_added', array( 'options_for' => $fld, 'aqm_n' => $added ) );
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
										<input type="email" id="fs_email" name="notify_email" value="<?php echo esc_attr( $form->notify_email ); ?>" placeholder="info@youremail.com" style="width:100%">
										<p class="description">Submissions for this form are sent here. <strong>Leave this blank and they go to the site administrator instead</strong> - always set it deliberately.</p>
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
									<th><label for="fs_intro">Introduction</label></th>
									<td>
										<textarea id="fs_intro" name="form_intro" rows="3" maxlength="1200" style="width:100%"><?php echo esc_textarea( aqm_col( $form, 'form_intro' ) ); ?></textarea>
										<p class="description">Shown above the fields. Put what a person needs before they start &mdash; date, place, price, deadline. Blank lines make paragraphs. Leave empty for no introduction.</p>
									</td>
								</tr>
								<tr>
									<th>Consent &amp; privacy</th>
									<td>
										<?php $fb_privacy = aqm_privacy_url(); ?>
										<p>Write <code>{privacy_policy}</code> or <code>{optout}</code> into any field&#8217;s help text, the words beside a tick box, the introduction above, or an auto-reply. Each becomes a link when the page exists, and plain words when it does not &mdash; so a form never shows a broken link.</p>

										<?php if ( '' !== $fb_privacy ) : ?>
											<p><span style="color:#1e7b34">&#10003;</span> <strong>Privacy policy published:</strong>
												<a href="<?php echo esc_url( $fb_privacy ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $fb_privacy ); ?></a>
											</p>
										<?php else : ?>
											<p style="color:#8a1f11"><strong>&#9888; No privacy policy is published.</strong> If your consent wording mentions one, it is promising a page nobody can read.</p>
											<p><strong>To create one:</strong> go to <a href="<?php echo esc_url( admin_url( 'options-privacy.php' ) ); ?>">Settings &rarr; Privacy</a>. WordPress can generate a draft for you. Replace the &#8220;Suggested text&#8221; placeholders with what <em>you</em> actually do, then <strong>publish it</strong> &mdash; a draft does not count &mdash; and make sure it is the page selected on that screen.</p>
										<?php endif; ?>

										<p><strong>Keeping it current.</strong> A privacy policy is not a one-off. Review it whenever any of these change:</p>
										<ul style="list-style:disc;margin-left:20px">
											<li>the information you collect &mdash; every new field on a form is a new thing you hold</li>
											<li>who else sees it: a CRM, a mailing tool, an analytics or chat plugin</li>
											<li>how long you keep submissions, and who can read them</li>
											<li>how someone asks for their data, or asks you to delete it</li>
											<li>the promises in your consent wording &mdash; if the form says it, the policy has to back it</li>
										</ul>
										<p class="description">Worth a look once a year even when nothing has changed, and a note of the date you last reviewed it. The opt-out page is set under <a href="<?php echo esc_url( admin_url( 'admin.php?page=aqm-settings' ) ); ?>">AQM Contact &rarr; Settings</a>.</p>
									</td>
								</tr>
								<tr>
									<th><label for="fs_success">Thank-you message</label></th>
									<td>
										<input type="text" id="fs_success" name="success_message" value="<?php echo esc_attr( $form->success_message ); ?>" maxlength="255" style="width:100%">
										<p class="description">Shown after a successful submission. <code>{name}</code> and <code>{comma_name}</code> are available. What they filled in is listed underneath it automatically.</p>
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
									<?php
									// Show what the visitor actually reads, so the whole
									// form's wording can be reviewed without opening
									// nine field editors one at a time.
									$row_hint = aqm_field_hint( $f );
									if ( aqm_promises_missing_privacy( $row_hint . ' ' . aqm_col( $f, 'placeholder' ) ) ) :
										?>
										<span style="display:block;margin-top:3px;font-size:12px;color:#8a1f11;max-width:52ch">
											&#9888; This mentions a privacy policy, but none is published.
											<a href="<?php echo esc_url( admin_url( 'options-privacy.php' ) ); ?>">Set one under Settings &rarr; Privacy</a>.
										</span>
										<?php
									endif;
									if ( '' !== $row_hint ) :
										?>
										<span style="display:block;margin-top:3px;font-size:12px;color:#5f6b64;max-width:46ch">&#8627; <?php echo esc_html( $row_hint ); ?></span>
									<?php endif; ?>
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
										<?php if ( in_array( $f->field_type, aqm_option_types(), true ) ) : ?>
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
								<?php
								$panel_titles = array(
									'combobox'    => 'Combobox Suggestions',
									'multiselect' => 'Tick-box Options',
									'select'      => 'Dropdown Options',
								);
								echo esc_html( $panel_titles[ $opt_field->field_type ] ?? 'Options' );
								?>
								&mdash; <em><?php echo esc_html( $opt_field->label ); ?></em>
								<span class="aqm-save-status" id="aqm-opt-order-status"></span>
							</h2>

							<?php if ( 'combobox' === $opt_field->field_type ) : ?>
								<div style="background:#f0e8fe;border:1px solid #c4a8f5;border-radius:4px;padding:8px 14px;margin-bottom:14px;font-size:13px;color:#4a1990">
									<strong>Combobox mode:</strong> these are suggestions, not a closed list. The field opens
									<strong>empty</strong> &mdash; nothing here is filled in or submitted unless the visitor picks it
									or types something of their own. Leaving it blank is allowed on an optional field.
								</div>
							<?php elseif ( 'multiselect' === $opt_field->field_type ) : ?>
								<div style="background:#eaf5ec;border:1px solid #b5debb;border-radius:4px;padding:8px 14px;margin-bottom:14px;font-size:13px;color:#1e5c29">
									<strong>Tick-box mode:</strong> every one of these becomes a checkbox and the visitor can tick
									<strong>as many as apply</strong>. A free-text box appears underneath for anything not on the list,
									so the list does not have to be exhaustive.
								</div>
							<?php else : ?>
								<div style="background:#e8f0fe;border:1px solid #b8d0f5;border-radius:4px;padding:8px 14px;margin-bottom:14px;font-size:13px;color:#12376b">
									<strong>Dropdown mode:</strong> the visitor must pick one of these. They cannot type anything else.
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
								<strong><?php echo $editing_opt ? 'Edit Option' : 'Add Options'; ?></strong>
								<form method="post" style="display:flex;gap:10px;align-items:flex-start;margin-top:8px;flex-wrap:wrap">
									<?php wp_nonce_field( 'aqm_save_option_' . $form_id, 'aqm_option_nonce' ); ?>
									<input type="hidden" name="aqm_opt_field_id" value="<?php echo esc_attr( $show_opts_for ); ?>">
									<?php if ( $editing_opt ) : ?>
										<input type="hidden" name="aqm_opt_edit_id" value="<?php echo esc_attr( $editing_opt->id ); ?>">
									<?php endif; ?>
									<label class="screen-reader-text" for="aqm_opt_label">Option label</label>
									<?php if ( $editing_opt ) : ?>
										<input type="text" id="aqm_opt_label" name="aqm_opt_label" maxlength="120"
											value="<?php echo esc_attr( $editing_opt->label ); ?>"
											placeholder="Option label..." style="flex:1;min-width:180px" required>
										<?php submit_button( 'Update', 'primary', 'submit', false ); ?>
										<a href="<?php echo esc_url( add_query_arg( 'options_for', $show_opts_for, $base ) ); ?>" class="button">Cancel</a>
									<?php else : ?>
										<span style="flex:1;min-width:280px">
											<textarea id="aqm_opt_label" name="aqm_opt_label" rows="4" style="width:100%"
												placeholder="First choice&#10;Second choice&#10;Third choice" required></textarea>
											<span class="description" style="display:block;margin-top:4px">
												<strong>One per line</strong>, and you can add several at once. A middot <code>&middot;</code>,
												a pipe <code>|</code> or a semicolon <code>;</code> also separate them.
												<strong>Commas do not</strong>, so a label such as &ldquo;Yes, with a guest&rdquo; stays whole.
												Bullets and leading dashes are stripped, and anything already on the list is skipped.
											</span>
										</span>
										<?php submit_button( 'Add Options', 'primary', 'submit', false ); ?>
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
							<label for="aqm_field_help"><strong>Help text</strong></label>
							<input type="text" id="aqm_field_help" name="aqm_field_help" maxlength="2000"
								value="<?php echo esc_attr( $editing_field ? aqm_col( $editing_field, 'help_text' ) : '' ); ?>"
								placeholder="Why you are asking, or what to put" style="width:100%;margin-top:4px">
							<span class="description" style="display:block;margin-top:3px">A grey line under the field. This is where an explanation belongs &mdash; not in the label.</span>
						</p>
						<p>
							<label for="aqm_field_placeholder"><strong id="aqm_ph_label">Placeholder</strong></label>
							<input type="text" id="aqm_field_placeholder" name="aqm_field_placeholder" maxlength="2000"
								value="<?php echo esc_attr( $editing_field ? $editing_field->placeholder : '' ); ?>"
								placeholder="e.g. nut allergy" style="width:100%;margin-top:4px">
							<span class="description" style="display:block;margin-top:3px" id="aqm_ph_help">Grey example text inside the box. It disappears as soon as they type and is <strong>never submitted</strong>. Not a default value.</span>
						</p>
						<p id="aqm-default-wrap">
							<label for="aqm_field_default"><strong>Default value</strong></label>
							<input type="text" id="aqm_field_default" name="aqm_field_default" maxlength="200"
								value="<?php echo esc_attr( $editing_field ? aqm_col( $editing_field, 'default_value' ) : '' ); ?>"
								placeholder="Leave blank for none" style="width:100%;margin-top:4px">
							<span class="description" style="display:block;margin-top:3px">Real text, already in the box when the form opens. If they leave it alone, <strong>this is what gets submitted</strong>.</span>
						</p>
						<p>
							<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600">
								<input type="checkbox" name="aqm_field_required" value="1" <?php checked( $editing_field ? (int) $editing_field->required : 1, 1 ); ?>>
								Mark as Required
							</label>
						</p>

						<?php
						// Shown only for Number fields - the inline script in
						// aqm_admin_scripts() toggles it and pre-sets the checkbox
						// from the label. See aqm_guess_whole_number().
						$nf_whole = $editing_field ? (int) aqm_num_opt( $editing_field, 'num_whole', 1 ) : 1;
						?>
						<input type="hidden" name="aqm_num_submitted" value="1">
						<div id="aqm-num-opts" style="display:none;border:1px solid #dcdcde;border-radius:4px;padding:12px 14px;margin-bottom:12px;background:#fbfcfb">
							<p style="margin:0 0 10px">
								<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600">
									<input type="checkbox" id="aqm_num_whole" name="aqm_num_whole" value="1" <?php checked( 1, $nf_whole ); ?>>
									Whole numbers only
								</label>
								<span class="description" style="display:block;margin-left:26px">Tick for a count &mdash; people, seats, tickets. Untick for money, weights or an average.</span>
							</p>
							<p style="display:flex;gap:10px;flex-wrap:wrap;margin:0">
								<span style="flex:1 1 90px">
									<label for="aqm_num_min" style="font-weight:600">Minimum</label>
									<input type="number" step="any" id="aqm_num_min" name="aqm_num_min" style="width:100%"
										value="<?php echo esc_attr( $editing_field ? aqm_num_opt( $editing_field, 'num_min', '' ) : '' ); ?>">
								</span>
								<span style="flex:1 1 90px">
									<label for="aqm_num_max" style="font-weight:600">Maximum</label>
									<input type="number" step="any" id="aqm_num_max" name="aqm_num_max" style="width:100%"
										value="<?php echo esc_attr( $editing_field ? aqm_num_opt( $editing_field, 'num_max', '' ) : '' ); ?>">
								</span>
								<span style="flex:1 1 90px">
									<label for="aqm_num_default" style="font-weight:600">Default</label>
									<input type="number" step="any" id="aqm_num_default" name="aqm_num_default" style="width:100%"
										value="<?php echo esc_attr( $editing_field ? aqm_num_opt( $editing_field, 'num_default', '' ) : '' ); ?>">
								</span>
							</p>
							<p class="description" style="margin:8px 0 0">Leave blank for no limit. These are enforced on the server, not just in the browser.</p>
						</div>
						<p style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
							<?php submit_button( $editing_field ? 'Update' : 'Add Field', 'primary', 'submit', false ); ?>
							<?php if ( $editing_field ) : ?>
								<a href="<?php echo esc_url( $base ); ?>" class="button">Cancel</a>
							<?php endif; ?>
						</p>
					</form>
				</div>

				<div class="aqm-box" style="font-size:12px;background:#fafafa">
					<p style="margin:0 0 10px;color:#555"><strong>Field types</strong> &mdash; click one to set it above.</p>
					<?php foreach ( aqm_field_types() as $type => $label ) : ?>
						<button type="button" class="aqm-type-pick" data-type="<?php echo esc_attr( $type ); ?>">
							<span class="aqm-type-badge" style="min-width:60px;text-align:center"><?php echo esc_html( $type ); ?></span>
							<span style="color:#555"><?php echo esc_html( $label ); ?></span>
						</button>
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
		<?php
		$export_all_url = wp_nonce_url(
			add_query_arg(
				array( 'action' => 'aqm_export_entries', 'form_id' => 0, 's' => $search ),
				admin_url( 'admin-post.php' )
			),
			'aqm_export_entries'
		);
		?>
		<a href="<?php echo esc_url( $export_all_url ); ?>" class="page-title-action">Export all forms</a>
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
	$all     = ! $form;

	$table = aqm_table( 'contact_entries' );

	/* Every form, or one.

	   Exporting everything answers the question a per-form export cannot:
	   "has this person ever written in, and what did they agree to?" With
	   consent handled by hand, that is the search you actually run.

	   Forms do not share a field list, so the columns are the union of every
	   field across every form, keyed by field_key. A label used by several
	   forms stays one column; anything unique to one form gets its own and is
	   simply blank elsewhere. */
	if ( $all ) {
		$columns = array();
		foreach ( aqm_get_forms() as $one ) {
			foreach ( aqm_get_fields( $one->id ) as $f ) {
				if ( ! isset( $columns[ $f->field_key ] ) ) {
					$columns[ $f->field_key ] = $f->label;
				}
			}
		}
		$sql    = "SELECT * FROM $table WHERE 1=%d";
		$params = array( 1 );
	} else {
		$columns = array();
		foreach ( aqm_get_fields( $form_id ) as $f ) {
			$columns[ $f->field_key ] = $f->label;
		}
		$sql    = "SELECT * FROM $table WHERE form_id = %d";
		$params = array( $form_id );
	}

	if ( '' !== $search ) {
		$sql     .= ' AND form_data LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $search ) . '%';
	}
	$sql .= ' ORDER BY submitted_at DESC, id DESC';

	$entries = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore

	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	$slug = $all ? 'all-forms' : sanitize_title( $form->form_name );
	header( 'Content-Disposition: attachment; filename=aqm-' . $slug . '-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM, so Excel reads UTF-8 correctly.

	$header = array( 'ID' );
	if ( $all ) {
		$header[] = 'Form';
	}
	foreach ( $columns as $label ) {
		$header[] = $label;
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
		if ( $all ) {
			// form_name is stored on the entry, so a submission whose form
			// has since been deleted still says where it came from.
			$row[] = $e->form_name;
		}
		foreach ( $columns as $key => $label ) {
			$val   = $data[ $key ] ?? '';
			$row[] = is_array( $val ) ? implode( ', ', $val ) : $val;
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

/* ══════════════════════════════════════════════════════════════
   STORED IP ADDRESSES

   A form only writes an IP when its "store IP" box is ticked. Turning that
   off stops new ones being written but leaves whatever is already in the
   table, and nothing in this plugin ages rows out - there is no cron. This
   is the cleanup.

   It clears the ip_address column and nothing else. Names, messages, form
   data and dates are untouched; no row is deleted. '' is exactly what
   aqm_handle_submission() writes when store_ip is off, so a purged row ends
   up identical to a new one. The column is NOT NULL default '', so NULL
   would error - the empty string is the correct value, not a shortcut.

   The rate-limit transients (aqm_rl_<md5(ip)>) are deliberately left alone.
   They are hashed, they expire after an hour on their own, and clearing
   them mid-flood would hand a spammer a fresh allowance.
   ══════════════════════════════════════════════════════════════ */

function aqm_entries_table_exists() {
	global $wpdb;
	$table = aqm_table( 'contact_entries' );
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

function aqm_count_stored_ips() {
	global $wpdb;
	if ( ! aqm_entries_table_exists() ) {
		return null;
	}
	$table = aqm_table( 'contact_entries' );
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE ip_address <> ''" );
}

function aqm_purge_stored_ips() {
	global $wpdb;
	if ( ! aqm_entries_table_exists() ) {
		return 0;
	}
	$table = aqm_table( 'contact_entries' );

	// The WHERE keeps the affected-row count meaningful and avoids rewriting
	// rows that are already empty.
	return (int) $wpdb->query( "UPDATE `{$table}` SET ip_address = '' WHERE ip_address <> ''" );
}

function aqm_render_ip_purge_box() {
	$count = aqm_count_stored_ips();
	if ( null === $count ) {
		return;   // No entries table yet - nothing to offer.
	}
	?>
	<h2>Stored IP addresses</h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Currently stored</th>
			<td>
				<?php if ( 0 === $count ) : ?>
					<p><strong>None.</strong> No submission is holding an IP address.</p>
				<?php else : ?>
					<p><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
					<?php echo 1 === $count ? 'submission is' : 'submissions are'; ?> holding an IP address.</p>
				<?php endif; ?>
				<p class="description">
					Clearing removes the IP address only. Names, messages, form data and dates are kept &mdash; no submission is deleted.
					<br>Spam protection is unaffected: rate limiting uses a scrambled, self-expiring counter, not this column.
				</p>
				<?php if ( $count > 0 ) : ?>
					<form method="post" onsubmit="return confirm('Clear the stored IP address from every submission? The submissions themselves are kept. This cannot be undone.');">
						<?php wp_nonce_field( 'aqm_purge_ips', 'aqm_purge_nonce' ); ?>
						<p><button type="submit" class="button button-secondary">Clear all stored IP addresses</button></p>
					</form>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}

function aqm_admin_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You do not have permission to view this page.' );
	}

	if ( isset( $_POST['aqm_purge_nonce'] ) ) {
		check_admin_referer( 'aqm_purge_ips', 'aqm_purge_nonce' );
		aqm_purge_stored_ips();
		wp_safe_redirect( add_query_arg( 'aqm_msg', 'ips_purged', admin_url( 'admin.php?page=aqm-settings' ) ) );
		exit;
	}

	if ( isset( $_POST['aqm_global_nonce'] ) ) {
		check_admin_referer( 'aqm_save_global', 'aqm_global_nonce' );

		$proxy = isset( $_POST['proxy_header'] ) ? sanitize_text_field( wp_unslash( $_POST['proxy_header'] ) ) : '';
		update_option( 'aqm_proxy_header', array_key_exists( $proxy, aqm_proxy_headers() ) ? $proxy : '' );
		update_option( 'aqm_rate_limit', max( 0, min( 100, (int) ( $_POST['rate_limit'] ?? 5 ) ) ) );
		update_option( 'aqm_delete_on_uninstall', empty( $_POST['delete_on_uninstall'] ) ? 0 : 1 );
		update_option( 'aqm_optout_url', esc_url_raw( wp_unslash( $_POST['optout_url'] ?? '' ) ) );

		wp_safe_redirect( add_query_arg( 'aqm_msg', 'settings_saved', admin_url( 'admin.php?page=aqm-settings' ) ) );
		exit;
	}

	$proxy_header = (string) get_option( 'aqm_proxy_header', '' );
	$rate_limit   = (int) get_option( 'aqm_rate_limit', 5 );
	$delete_all   = (int) get_option( 'aqm_delete_on_uninstall', 0 );
	$optout_url   = aqm_optout_url();
	$privacy_url  = aqm_privacy_url();
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

			<h2>Consent &amp; Privacy</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Privacy policy</th>
					<td>
						<?php if ( '' !== $privacy_url ) : ?>
							<p><strong>Published:</strong>
								<a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $privacy_url ); ?></a>
							</p>
						<?php else : ?>
							<p><strong>Not set.</strong> Any form text promising a privacy policy is promising something this site does not publish.</p>
							<p><a href="<?php echo esc_url( admin_url( 'options-privacy.php' ) ); ?>">Set or create one under Settings &rarr; Privacy</a> - WordPress can generate a starter policy for you.</p>
						<?php endif; ?>
						<p class="description">Taken from WordPress&#8217;s own setting, so it stays in step with the rest of the site.</p>
						<p><strong>Keeping it current.</strong> Review the policy whenever the information you collect changes, whenever another service starts seeing it (a CRM, a mailing tool, an analytics or chat plugin), or whenever your consent wording starts promising something the policy does not cover. Worth a look once a year regardless.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="optout_url">Opt-out page</label></th>
					<td>
						<input type="url" id="optout_url" name="optout_url" value="<?php echo esc_attr( $optout_url ); ?>" placeholder="https://example.com/unsubscribe" class="regular-text">
						<p class="description">Where someone goes to stop hearing from you. Optional.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Using them</th>
					<td>
						<p>Write either token into a field&#8217;s help text, the words beside a tick box, a form introduction, or an auto-reply. It becomes a link when the page exists, and plain words when it does not:</p>
						<p><code>{privacy_policy}</code> &nbsp; <code>{optout}</code></p>
						<p class="description">Example: <em>&#8220;&hellip;you agree to our {privacy_policy} and may {optout} at any time.&#8221;</em></p>
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

		<?php aqm_render_ip_purge_box(); ?>
	</div>
	<?php
}

/* ══════════════════════════════════════════════════════════════
   13. REST API - form import and export                (7.8.0)

   Everything in the form builder runs through admin-post handlers
   guarded by nonces, which means a form can only ever be built by a
   human clicking. That is fine for one form. It is not fine for four
   forms of thirteen fields whose labels must match each other
   character for character, because that is precisely the thing a
   person typing does not do reliably - and the CSV export unions
   columns by field_key, so one drifted label becomes a second column
   that never merges back.

   These routes let a form live as a JSON file: version controlled,
   diffable, reviewable, and reproducible on another site.

   WHAT PROTECTS YOU HERE

     - manage_options on every route. Nothing here is public.
     - dry_run returns the full plan and writes nothing.
     - Nothing is ever deleted unless you ask. prune=false (default)
       means a field in the database but absent from the JSON is
       reported and LEFT ALONE.
     - Even with prune=true, a field is not deleted from a form that
       already holds submissions unless force=true as well. Deleting a
       field orphans that answer in every stored entry, and the entry
       keeps the data with no column left to show it under.
     - The whole import runs in a transaction where the storage engine
       supports one, so a failure halfway does not leave half a form.

   IDENTIFYING A FORM

   By "id" if you pass one, otherwise by exact form_name, otherwise it
   is created. Form IDs are not stable or predictable - a deleted form
   leaves its number behind, so an install's first form is not
   necessarily 1 - which makes name matching the reliable route. The
   response always reports the id it used, and that is the number the
   [aqm_form id="N"] shortcode needs.
   ══════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', 'aqm_rest_routes' );

function aqm_rest_routes() {
	register_rest_route(
		'aqm/v1',
		'/forms',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'aqm_rest_list_forms',
				'permission_callback' => 'aqm_rest_may_manage',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'aqm_rest_import_form',
				'permission_callback' => 'aqm_rest_may_manage',
			),
		)
	);

	register_rest_route(
		'aqm/v1',
		'/forms/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'aqm_rest_export_form',
				'permission_callback' => 'aqm_rest_may_manage',
			),
		)
	);
}

function aqm_rest_may_manage() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	return new WP_Error(
		'aqm_forbidden',
		'Importing and exporting forms needs the manage_options capability.',
		array( 'status' => rest_authorization_required_code() )
	);
}

/**
 * Column limits, so an over-long value is REJECTED rather than silently
 * truncated by MySQL.
 *
 * 7.5.0 exists because consent text was being cut off at a varchar
 * boundary with no error - the form looked saved and the sentence people
 * were agreeing to had lost its second half. Never again by accident.
 */
function aqm_rest_limits() {
	return array(
		'form_name'         => 120,
		'notify_email'      => 120,
		'notify_cc'         => 255,
		'email_subject'     => 200,
		'autoreply_subject' => 200,
		'success_message'   => 255,
		'field_label'       => 120,
		'field_key'         => 80,
		'field_default'     => 200,
		'option_label'      => 120,
		// TEXT columns. Capped only to keep a runaway payload out of the DB.
		'form_intro'        => 20000,
		'autoreply_body'    => 20000,
		'placeholder'       => 2000,
		'help_text'         => 2000,
	);
}

function aqm_rest_len( $text ) {
	return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text );
}

function aqm_rest_count_entries( $form_id ) {
	global $wpdb;
	if ( ! aqm_entries_table_exists() ) {
		return 0;
	}
	$table = aqm_table( 'contact_entries' );
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE form_id = %d", $form_id ) ); // phpcs:ignore
}

/* ---------- GET /aqm/v1/forms ---------- */

function aqm_rest_list_forms() {
	$out = array();
	foreach ( aqm_get_forms() as $form ) {
		$out[] = array(
			'id'          => (int) $form->id,
			'form_name'   => $form->form_name,
			'shortcode'   => '[aqm_form id="' . (int) $form->id . '"]',
			'fields'      => count( aqm_get_fields( $form->id ) ),
			'submissions' => aqm_rest_count_entries( $form->id ),
		);
	}
	return rest_ensure_response( array( 'forms' => $out ) );
}

/* ---------- GET /aqm/v1/forms/{id} ---------- */

function aqm_rest_export_form( WP_REST_Request $req ) {
	$form = aqm_get_form( (int) $req['id'] );
	if ( ! $form ) {
		return new WP_Error( 'aqm_not_found', 'No form with that ID.', array( 'status' => 404 ) );
	}
	return rest_ensure_response( aqm_rest_form_to_array( $form ) );
}

/**
 * One form as the exact shape import accepts, so export -> edit -> import
 * is a round trip with nothing lost in between.
 */
function aqm_rest_form_to_array( $form ) {
	$fields = array();
	foreach ( aqm_get_fields( $form->id ) as $f ) {
		$one = array(
			'key'         => $f->field_key,
			'label'       => $f->label,
			'type'        => $f->field_type,
			'required'    => (bool) $f->required,
			'enabled'     => (bool) $f->enabled,
			'placeholder' => $f->placeholder,
			'help_text'   => $f->help_text,
			'default'     => $f->default_value,
		);

		if ( 'number' === $f->field_type ) {
			$one['number'] = array(
				'whole'   => (bool) $f->num_whole,
				'min'     => $f->num_min,
				'max'     => $f->num_max,
				'default' => $f->num_default,
			);
		}

		if ( in_array( $f->field_type, aqm_option_types(), true ) ) {
			$labels = array();
			foreach ( aqm_get_options( $f->id ) as $opt ) {
				$labels[] = $opt->label;
			}
			$one['options'] = $labels;
		}

		$fields[] = $one;
	}

	return array(
		'id'                => (int) $form->id,
		'form_name'         => $form->form_name,
		'notify_email'      => $form->notify_email,
		'notify_cc'         => $form->notify_cc,
		'email_subject'     => $form->email_subject,
		'form_intro'        => $form->form_intro,
		'success_message'   => $form->success_message,
		'captcha_enabled'   => (bool) $form->captcha_enabled,
		'spam_protection'   => (bool) $form->spam_protection,
		'store_ip'          => (bool) $form->store_ip,
		'autoreply_enabled' => (bool) $form->autoreply_enabled,
		'autoreply_subject' => $form->autoreply_subject,
		'autoreply_body'    => $form->autoreply_body,
		'shortcode'         => '[aqm_form id="' . (int) $form->id . '"]',
		'fields'            => $fields,
	);
}

/* ---------- POST /aqm/v1/forms ---------- */

function aqm_rest_import_form( WP_REST_Request $req ) {
	global $wpdb;

	$body = $req->get_json_params();
	if ( ! is_array( $body ) ) {
		return new WP_Error( 'aqm_bad_body', 'Send a JSON object describing the form.', array( 'status' => 400 ) );
	}

	$dry_run = ! empty( $body['dry_run'] );
	$prune   = ! empty( $body['prune'] );
	$force   = ! empty( $body['force'] );

	$errors   = array();
	$warnings = array();
	$lim      = aqm_rest_limits();

	/* ---- validate the form settings ---- */

	$name = isset( $body['form_name'] ) ? sanitize_text_field( (string) $body['form_name'] ) : '';
	if ( '' === $name ) {
		$errors[] = 'form_name is required.';
	} elseif ( aqm_rest_len( $name ) > $lim['form_name'] ) {
		$errors[] = sprintf( 'form_name is %d characters; the column holds %d.', aqm_rest_len( $name ), $lim['form_name'] );
	}

	$email = isset( $body['notify_email'] ) ? sanitize_email( (string) $body['notify_email'] ) : '';
	if ( '' !== $email && ! is_email( $email ) ) {
		$errors[] = 'notify_email is not a valid address: ' . $email;
	}
	if ( '' === $email ) {
		$warnings[] = 'notify_email is empty - submissions will be stored but no notification will be sent.';
	}

	$cc = array();
	if ( ! empty( $body['notify_cc'] ) ) {
		foreach ( preg_split( '/[,;]+/', (string) $body['notify_cc'] ) as $one ) {
			$one = sanitize_email( trim( $one ) );
			if ( is_email( $one ) ) {
				$cc[] = $one;
			} elseif ( '' !== trim( $one ) ) {
				$errors[] = 'notify_cc contains an invalid address: ' . $one;
			}
		}
	}
	$cc_joined = implode( ', ', $cc );

	$text_settings = array(
		'email_subject'     => 'email_subject',
		'autoreply_subject' => 'autoreply_subject',
		'success_message'   => 'success_message',
	);
	$settings      = array();
	foreach ( $text_settings as $key => $limit_key ) {
		$val = isset( $body[ $key ] ) ? sanitize_text_field( (string) $body[ $key ] ) : '';
		if ( aqm_rest_len( $val ) > $lim[ $limit_key ] ) {
			$errors[] = sprintf( '%s is %d characters; the column holds %d.', $key, aqm_rest_len( $val ), $lim[ $limit_key ] );
		}
		$settings[ $key ] = $val;
	}

	foreach ( array( 'form_intro', 'autoreply_body' ) as $key ) {
		$val = isset( $body[ $key ] ) ? sanitize_textarea_field( (string) $body[ $key ] ) : '';
		if ( aqm_rest_len( $val ) > $lim[ $key ] ) {
			$errors[] = sprintf( '%s is %d characters; the cap is %d.', $key, aqm_rest_len( $val ), $lim[ $key ] );
		}
		$settings[ $key ] = $val;
	}

	if ( aqm_rest_len( $cc_joined ) > $lim['notify_cc'] ) {
		$errors[] = 'notify_cc is too long once joined; the column holds ' . $lim['notify_cc'] . '.';
	}

	/* ---- validate the fields ---- */

	$raw_fields = isset( $body['fields'] ) && is_array( $body['fields'] ) ? $body['fields'] : array();
	if ( ! $raw_fields ) {
		$errors[] = 'fields is required and must contain at least one field.';
	}

	$types  = aqm_field_types();
	$clean  = array();
	$keys   = array();
	$labels = array();

	foreach ( $raw_fields as $i => $rf ) {
		$where = 'field ' . ( $i + 1 );
		if ( ! is_array( $rf ) ) {
			$errors[] = $where . ' is not an object.';
			continue;
		}

		$label = isset( $rf['label'] ) ? sanitize_text_field( (string) $rf['label'] ) : '';
		if ( '' === $label ) {
			$errors[] = $where . ' has no label.';
			continue;
		}
		$where .= ' ("' . $label . '")';
		if ( aqm_rest_len( $label ) > $lim['field_label'] ) {
			$errors[] = $where . ' label is ' . aqm_rest_len( $label ) . ' characters; the column holds ' . $lim['field_label'] . '.';
		}

		$type = isset( $rf['type'] ) ? sanitize_key( (string) $rf['type'] ) : 'text';
		if ( ! array_key_exists( $type, $types ) ) {
			$errors[] = $where . ' has type "' . $type . '", which this plugin cannot render. Valid: ' . implode( ', ', array_keys( $types ) ) . '.';
			continue;
		}

		$key       = isset( $rf['key'] ) ? sanitize_key( (string) $rf['key'] ) : '';
		$key_given = ( '' !== $key );
		if ( '' === $key ) {
			// Same slug rule the builder uses, minus the collision suffix -
			// that is applied later, and only when actually inserting.
			$key = preg_replace( '/_+/', '_', trim( preg_replace( '/[^a-z0-9_]/', '_', strtolower( $label ) ), '_' ) );
			if ( '' === $key ) {
				$key = 'field';
			}
		}
		if ( aqm_rest_len( $key ) > $lim['field_key'] ) {
			$errors[] = $where . ' key is longer than ' . $lim['field_key'] . ' characters.';
		}
		if ( isset( $keys[ $key ] ) ) {
			$errors[] = $where . ' repeats the key "' . $key . '". Keys must be unique within a form - the CSV export uses them as column identity.';
			continue;
		}
		$keys[ $key ] = true;

		// Two fields with the same label produce one CSV column holding two
		// different questions' answers. That is a data problem, not a style one.
		$lower = strtolower( $label );
		if ( isset( $labels[ $lower ] ) ) {
			$errors[] = $where . ' repeats the label "' . $label . '" used by field ' . $labels[ $lower ] . '.';
		} else {
			$labels[ $lower ] = $i + 1;
		}

		$default = isset( $rf['default'] ) ? sanitize_text_field( (string) $rf['default'] ) : '';
		if ( aqm_rest_len( $default ) > $lim['field_default'] ) {
			$errors[] = $where . ' default is longer than ' . $lim['field_default'] . ' characters.';
		}

		$ph   = isset( $rf['placeholder'] ) ? sanitize_text_field( (string) $rf['placeholder'] ) : '';
		$help = isset( $rf['help_text'] ) ? sanitize_text_field( (string) $rf['help_text'] ) : '';
		foreach ( array( 'placeholder' => $ph, 'help_text' => $help ) as $k => $v ) {
			if ( aqm_rest_len( $v ) > $lim[ $k ] ) {
				$errors[] = $where . ' ' . $k . ' exceeds the ' . $lim[ $k ] . ' character cap.';
			}
		}

		/* options */
		$options   = array();
		$has_opts  = isset( $rf['options'] ) && is_array( $rf['options'] );
		$takes_opt = in_array( $type, aqm_option_types(), true );

		if ( $has_opts && ! $takes_opt ) {
			$errors[] = $where . ' is a "' . $type . '" field and cannot hold options. Only ' . implode( ', ', aqm_option_types() ) . ' can.';
		}
		if ( $takes_opt ) {
			if ( ! $has_opts || ! $rf['options'] ) {
				// A dropdown with no options renders as an empty list nobody
				// can complete, and the field is usually required.
				$errors[] = $where . ' is a "' . $type . '" field with no options.';
			} else {
				$seen = array();
				foreach ( $rf['options'] as $opt ) {
					$opt = trim( sanitize_text_field( (string) $opt ) );
					if ( '' === $opt ) {
						continue;
					}
					if ( aqm_rest_len( $opt ) > $lim['option_label'] ) {
						$errors[] = $where . ' has an option longer than ' . $lim['option_label'] . ' characters: ' . $opt;
						continue;
					}
					if ( isset( $seen[ strtolower( $opt ) ] ) ) {
						$warnings[] = $where . ' lists "' . $opt . '" twice; the duplicate was dropped.';
						continue;
					}
					$seen[ strtolower( $opt ) ] = true;
					$options[]                  = $opt;
				}
			}
		}

		/* number settings */
		$num = array(
			'whole'   => aqm_guess_whole_number( $label ) ? 1 : 0,
			'min'     => '',
			'max'     => '',
			'default' => '',
		);
		if ( 'number' === $type && isset( $rf['number'] ) && is_array( $rf['number'] ) ) {
			$n            = $rf['number'];
			$num['whole'] = ! empty( $n['whole'] ) ? 1 : 0;
			foreach ( array( 'min', 'max', 'default' ) as $nk ) {
				$nv          = isset( $n[ $nk ] ) ? trim( (string) $n[ $nk ] ) : '';
				$num[ $nk ] = is_numeric( $nv ) ? $nv : '';
			}
			if ( '' !== $num['min'] && '' !== $num['max'] && $num['min'] + 0 > $num['max'] + 0 ) {
				$errors[] = $where . ' has a minimum above its maximum, which nothing can satisfy.';
			}
		}

		$clean[] = array(
			'key'         => $key,
			'key_given'   => $key_given,
			'label'       => $label,
			'type'        => $type,
			'required'    => isset( $rf['required'] ) ? ( $rf['required'] ? 1 : 0 ) : 1,
			'enabled'     => isset( $rf['enabled'] ) ? ( $rf['enabled'] ? 1 : 0 ) : 1,
			'placeholder' => $ph,
			'help_text'   => $help,
			'default'     => $default,
			'options'     => $options,
			'takes_opt'   => $takes_opt,
			'number'      => $num,
		);
	}

	if ( $errors ) {
		return new WP_Error(
			'aqm_invalid',
			'The form was not imported. ' . count( $errors ) . ' problem' . ( 1 === count( $errors ) ? '' : 's' ) . ' found.',
			array( 'status' => 400, 'errors' => $errors, 'warnings' => $warnings )
		);
	}

	/* ---- find the target form ---- */

	$ftable = aqm_table( 'forms' );
	$target = null;

	if ( ! empty( $body['id'] ) ) {
		$target = aqm_get_form( (int) $body['id'] );
		if ( ! $target ) {
			return new WP_Error( 'aqm_not_found', 'No form with id ' . (int) $body['id'] . '. Leave "id" out to create one.', array( 'status' => 404 ) );
		}
	} else {
		foreach ( aqm_get_forms() as $one ) {
			if ( strtolower( $one->form_name ) === strtolower( $name ) ) {
				$target = $one;
				break;
			}
		}
	}

	$entries = $target ? aqm_rest_count_entries( $target->id ) : 0;

	/* ---- work out what will happen to each field ---- */

	$existing = array();
	if ( $target ) {
		foreach ( aqm_get_fields( $target->id ) as $f ) {
			$existing[ $f->field_key ] = $f;
		}
	}

	$plan    = array();
	$removes = array();

	// Fields already on the form that this JSON does not mention. Worked out
	// before the loop below, because a "create" only deserves a rename warning
	// when there is an unmatched field it could plausibly be a rename OF.
	$unmatched = array();
	foreach ( $existing as $key => $f ) {
		if ( ! isset( $keys[ $key ] ) ) {
			$unmatched[] = $f;
		}
	}

	foreach ( $clean as $pos => $cf ) {
		$have   = $existing[ $cf['key'] ] ?? null;
		$action = 'create';
		$note   = '';

		if ( $have ) {
			$same = ( $have->label === $cf['label']
				&& $have->field_type === $cf['type']
				&& (int) $have->required === $cf['required']
				&& (int) $have->enabled === $cf['enabled']
				&& (string) $have->placeholder === $cf['placeholder']
				&& (string) $have->help_text === $cf['help_text']
				&& (string) $have->default_value === $cf['default']
				&& (int) $have->sort_order === $pos + 1 );
			$action = $same ? 'unchanged' : 'update';

			if ( $have->field_type !== $cf['type'] ) {
				$note = 'type changes from ' . $have->field_type . ' to ' . $cf['type'];
				if ( $entries > 0 ) {
					$warnings[] = $cf['key'] . ': ' . $note . ' on a form with ' . $entries .
						' stored submission' . ( 1 === $entries ? '' : 's' ) .
						'. Existing answers keep whatever they were and are not re-validated.';
				}
			}
		}

		/* The rename trap.

		   With no explicit "key", the key is derived from the label - so
		   editing a label in the JSON does not rename a field, it creates a
		   second one and leaves the first orphaned. The CSV export keys
		   columns by field_key, so that quietly produces two columns for one
		   question and strands every answer already stored under the old one.

		   Not guessed at, because a genuine new field looks identical from
		   here. It is named, loudly, with the one-word fix. */
		if ( 'create' === $action && ! $cf['key_given'] && $unmatched && $target ) {
			$names = array();
			foreach ( $unmatched as $u ) {
				$names[] = '"' . $u->label . '" (key ' . $u->field_key . ')';
			}
			$warnings[] = 'Creating a NEW field "' . $cf['label'] . '" with key "' . $cf['key'] .
				'", while ' . implode( ' and ', $names ) . ' on this form ' .
				( count( $names ) > 1 ? 'are' : 'is' ) . ' unmatched. If you meant to RENAME one of those, ' .
				'add its existing key to this field in the JSON - otherwise you get two columns for one ' .
				'question and the answers already stored stay under the old one.';
		}

		$row = array(
			'key'    => $cf['key'],
			'label'  => $cf['label'],
			'type'   => $cf['type'],
			'action' => $action,
		);
		if ( '' !== $note ) {
			$row['note'] = $note;
		}
		if ( $cf['takes_opt'] ) {
			$row['options'] = count( $cf['options'] );
		}
		$plan[] = $row;
	}

	foreach ( $existing as $key => $f ) {
		if ( isset( $keys[ $key ] ) ) {
			continue;
		}
		$why = '';
		if ( ! $prune ) {
			$why = 'left in place (pass prune=true to remove fields missing from the JSON)';
		} elseif ( $entries > 0 && ! $force ) {
			$why = 'NOT removed: this form has ' . $entries . ' stored submission' .
				( 1 === $entries ? '' : 's' ) . ' and deleting the field orphans that answer. Pass force=true if you mean it.';
		} else {
			$why = 'will be deleted';
		}
		$removes[] = array(
			'key'    => $key,
			'label'  => $f->label,
			'action' => ( 'will be deleted' === $why ) ? 'delete' : 'keep',
			'reason' => $why,
		);
	}

	$report = array(
		'dry_run'     => $dry_run,
		'form'        => array(
			'id'     => $target ? (int) $target->id : null,
			'name'   => $name,
			'action' => $target ? 'update' : 'create',
		),
		'submissions' => $entries,
		'fields'      => $plan,
		'other_fields' => $removes,
		'warnings'    => $warnings,
	);

	if ( $dry_run ) {
		$report['message'] = 'Nothing was written. Re-send without dry_run to apply this.';
		return rest_ensure_response( $report );
	}

	/* ---- write ---- */

	// InnoDB gives us all-or-nothing. On MyISAM these are no-ops and the
	// import is not atomic; say so rather than implying a guarantee.
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore

	$form_row = array(
		'form_name'         => $name,
		'notify_email'      => $email,
		'notify_cc'         => $cc_joined,
		'email_subject'     => $settings['email_subject'],
		'form_intro'        => $settings['form_intro'],
		'success_message'   => $settings['success_message'],
		'autoreply_subject' => $settings['autoreply_subject'],
		'autoreply_body'    => $settings['autoreply_body'],
		'captcha_enabled'   => isset( $body['captcha_enabled'] ) ? ( $body['captcha_enabled'] ? 1 : 0 ) : 1,
		'spam_protection'   => isset( $body['spam_protection'] ) ? ( $body['spam_protection'] ? 1 : 0 ) : 1,
		'store_ip'          => ! empty( $body['store_ip'] ) ? 1 : 0,
		'autoreply_enabled' => isset( $body['autoreply_enabled'] ) ? ( $body['autoreply_enabled'] ? 1 : 0 ) : 1,
	);
	$form_fmt = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' );

	if ( $target ) {
		$form_id = (int) $target->id;
		$ok      = $wpdb->update( $ftable, $form_row, array( 'id' => $form_id ), $form_fmt, array( '%d' ) );
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			return new WP_Error( 'aqm_write_failed', 'Could not update the form: ' . $wpdb->last_error, array( 'status' => 500 ) );
		}
	} else {
		$form_row['created_at'] = current_time( 'mysql' );
		$form_fmt[]             = '%s';
		if ( false === $wpdb->insert( $ftable, $form_row, $form_fmt ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			return new WP_Error( 'aqm_write_failed', 'Could not create the form: ' . $wpdb->last_error, array( 'status' => 500 ) );
		}
		$form_id = (int) $wpdb->insert_id;
	}

	$fields_table  = aqm_table( 'form_fields' );
	$options_table = aqm_table( 'field_options' );
	$fail          = null;

	foreach ( $clean as $pos => $cf ) {
		$have = $existing[ $cf['key'] ] ?? null;
		$row  = array(
			'label'         => $cf['label'],
			'field_type'    => $cf['type'],
			'placeholder'   => $cf['placeholder'],
			'help_text'     => $cf['help_text'],
			'default_value' => $cf['default'],
			'required'      => $cf['required'],
			'enabled'       => $cf['enabled'],
			'sort_order'    => $pos + 1,
			'num_whole'     => $cf['number']['whole'],
			'num_min'       => $cf['number']['min'],
			'num_max'       => $cf['number']['max'],
			'num_default'   => $cf['number']['default'],
		);
		$fmt = array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' );

		if ( $have ) {
			$field_id = (int) $have->id;
			if ( false === $wpdb->update( $fields_table, $row, array( 'id' => $field_id ), $fmt, array( '%d' ) ) ) {
				$fail = 'field "' . $cf['label'] . '": ' . $wpdb->last_error;
				break;
			}
		} else {
			$row['form_id']   = $form_id;
			$row['field_key'] = $cf['key'];
			$fmt[]            = '%d';
			$fmt[]            = '%s';
			if ( false === $wpdb->insert( $fields_table, $row, $fmt ) ) {
				$fail = 'field "' . $cf['label'] . '": ' . $wpdb->last_error;
				break;
			}
			$field_id = (int) $wpdb->insert_id;
		}

		if ( ! $cf['takes_opt'] ) {
			// Switching a dropdown to a text field leaves its options behind
			// as invisible rows that reappear if it is switched back.
			$wpdb->delete( $options_table, array( 'field_id' => $field_id ), array( '%d' ) );
			continue;
		}

		// Options are reconciled by label, not rewritten wholesale, so an
		// option's ID survives and the stored answers that reference its
		// label keep meaning the same thing.
		$current = array();
		foreach ( aqm_get_options( $field_id ) as $opt ) {
			$current[ strtolower( $opt->label ) ] = $opt;
		}

		$order = 0;
		foreach ( $cf['options'] as $label ) {
			$order++;
			$lower = strtolower( $label );
			if ( isset( $current[ $lower ] ) ) {
				$wpdb->update(
					$options_table,
					array( 'label' => $label, 'sort_order' => $order ),
					array( 'id' => (int) $current[ $lower ]->id ),
					array( '%s', '%d' ),
					array( '%d' )
				);
				unset( $current[ $lower ] );
			} elseif ( false === $wpdb->insert(
				$options_table,
				array( 'field_id' => $field_id, 'label' => $label, 'sort_order' => $order ),
				array( '%d', '%s', '%d' )
			) ) {
				$fail = 'option "' . $label . '": ' . $wpdb->last_error;
				break 2;
			}
		}

		// Anything left is an option the JSON no longer lists.
		foreach ( $current as $stale ) {
			if ( $prune ) {
				$wpdb->delete( $options_table, array( 'id' => (int) $stale->id ), array( '%d' ) );
			} else {
				$warnings[] = 'Option "' . $stale->label . '" on "' . $cf['label'] .
					'" is not in the JSON and was left in place (prune=true removes it).';
			}
		}
	}

	if ( null !== $fail ) {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
		return new WP_Error( 'aqm_write_failed', 'Import rolled back at ' . $fail, array( 'status' => 500 ) );
	}

	foreach ( $removes as $r ) {
		if ( 'delete' !== $r['action'] ) {
			continue;
		}
		$gone = $existing[ $r['key'] ];
		$wpdb->delete( $options_table, array( 'field_id' => (int) $gone->id ), array( '%d' ) );
		$wpdb->delete( $fields_table, array( 'id' => (int) $gone->id, 'form_id' => $form_id ), array( '%d', '%d' ) );
	}

	$wpdb->query( 'COMMIT' ); // phpcs:ignore

	$report['form']['id'] = $form_id;
	$report['warnings']   = $warnings;
	$report['shortcode']  = '[aqm_form id="' . $form_id . '"]';
	$report['message']    = 'Imported. Put ' . $report['shortcode'] . ' on a draft page and test it before it goes anywhere public.';

	return rest_ensure_response( $report );
}


/* ══════════════════════════════════════════════════════════════
   14. SELF-UPDATE FROM GITHUB RELEASES

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
   15. UNINSTALL
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
	delete_option( 'aqm_optout_url' );
}
