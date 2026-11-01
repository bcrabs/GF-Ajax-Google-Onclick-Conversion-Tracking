<?php
/**
 * Google Ads Conversion Tracking for Gravity Forms AJAX Submissions
 *
 * Calls the Google Ads event snippet's gtag_report_conversion() when a
 * Gravity Forms AJAX submission renders its confirmation message, so the
 * conversion is recorded without a page reload or a thank-you page.
 *
 * The Google tag (gtag.js) and the Google Ads "click" event snippet, which
 * defines gtag_report_conversion(), must already be on the page. This file
 * does not load either of them.
 *
 * Covers both Gravity Forms AJAX submission methods:
 *  - Legacy iframe method (ajax="true" on the shortcode, "AJAX" toggle on the
 *    block): listens for the gform_confirmation_loaded jQuery event.
 *  - New AJAX method (Gravity Forms 2.9+, submission_method "ajax" via the
 *    gform_form_args filter): that path never fires gform_confirmation_loaded,
 *    so the gform/ajax/post_ajax_submission filter is used instead.
 *
 * Redirect confirmations (type "Redirect" or "Page") never render a
 * confirmation message, so nothing is reported for them. Fire the conversion
 * on the destination page instead.
 *
 * Verified against Gravity Forms 3.2.1 and WordPress 7.1.4.
 *
 * @package   CRFTD
 * @author    CRFTD Web Development & Consulting <https://crftd.dev>
 * @license   GPL-2.0-or-later
 * @version   1.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'gform_pre_enqueue_scripts', 'crftd_gf_google_conversion_add_script', 10, 0 );
add_action( 'gform_pre_print_scripts', 'crftd_gf_google_conversion_add_script', 10, 0 );
/**
 * Attach the conversion listener to Gravity Forms' main front-end script.
 *
 * Both hooks fire just before Gravity Forms enqueues (or, for forms rendered
 * after the header is sent, prints) its scripts for a form. Attaching the
 * listener to gform_gravityforms as an inline script means:
 *  - it is only output on pages that actually render a form;
 *  - it prints wherever Gravity Forms prints its script (header or footer),
 *    after jQuery, so it can never run before jQuery exists;
 *  - it runs once per request, however many forms are on the page.
 *
 * Note: Gravity Forms adds the defer attribute to its scripts by string
 * replacing " src" in the script tag (GFFormDisplay::add_script_defer). The
 * inline code below is part of that tag, so it must never contain the
 * substrings " src" or " defer".
 *
 * @since 1.1.0
 *
 * @return void
 */
function crftd_gf_google_conversion_add_script() {
	static $added = false;

	if ( $added || is_admin() || ! wp_script_is( 'gform_gravityforms', 'registered' ) ) {
		return;
	}

	$added = true;

	/**
	 * Limit conversion reporting to specific forms.
	 *
	 * @since 1.1.0
	 *
	 * @param int[] $form_ids Form IDs to report. An empty array (the default) reports every form.
	 */
	$form_ids = apply_filters( 'crftd_gf_google_conversion_form_ids', array() );
	$form_ids = array_values( array_filter( array_map( 'absint', (array) $form_ids ) ) );
	$form_ids = wp_json_encode( $form_ids );

	if ( ! is_string( $form_ids ) ) {
		$form_ids = '[]';
	}

	$script = <<<'JS'
(function () {
	'use strict';
	var allowed = __FORM_IDS__;
	var reported = {};

	function report( formId ) {
		formId = parseInt( formId, 10 );
		if ( ! formId || reported[ formId ] ) {
			return;
		}
		if ( allowed.length && allowed.indexOf( formId ) === -1 ) {
			return;
		}
		if ( typeof window.gtag_report_conversion !== 'function' ) {
			return;
		}
		reported[ formId ] = true;
		window.gtag_report_conversion();
	}

	function bind() {
		// Legacy iframe AJAX method. Skip the Save & Continue "link sent" message, which uses the same event.
		if ( window.jQuery ) {
			window.jQuery( document ).on( 'gform_confirmation_loaded', function ( event, formId ) {
				var wrapper = document.getElementById( 'gform_confirmation_wrapper_' + formId );
				if ( wrapper && wrapper.classList.contains( 'form_saved_message_sent' ) ) {
					return;
				}
				report( formId );
			} );
		}

		// New AJAX method (GF 2.9+). Only a completed, non-spam submission that rendered a message counts.
		if ( window.gform && window.gform.utils && typeof window.gform.utils.addFilter === 'function' ) {
			window.gform.utils.addFilter( 'gform/ajax/post_ajax_submission', function ( data ) {
				var result = data && data.submissionResult ? data.submissionResult.data : null;
				if (
					result &&
					result.is_valid &&
					! result.is_spam &&
					result.submission_type === 'submit' &&
					result.confirmation_type === 'message' &&
					data.form
				) {
					report( data.form.getAttribute( 'data-formid' ) );
				}
				return data;
			} );
		}
	}

	if ( window.gform && typeof window.gform.initializeOnLoaded === 'function' ) {
		window.gform.initializeOnLoaded( bind );
	} else {
		bind();
	}
})();
JS;

	wp_add_inline_script( 'gform_gravityforms', str_replace( '__FORM_IDS__', $form_ids, $script ) );
}
