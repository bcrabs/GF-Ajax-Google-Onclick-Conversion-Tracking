# Google Ads Conversion Tracking for Gravity Forms AJAX Submissions

A single-file WordPress snippet that calls the Google Ads event snippet's `gtag_report_conversion()` the moment a Gravity Forms AJAX submission renders its confirmation message. No thank-you page, no page reload, no Tag Manager trigger to debug.

Verified against **Gravity Forms 3.2.1** and **WordPress 7.1.4** (both released 2026-10-28).

## The problem

Google Ads' "click" conversion type hands you a function, `gtag_report_conversion(url)`, meant to go on a link's or button's `onclick`. That works badly for forms:

- Wiring it to the submit button counts every click, including the ones that fail validation.
- AJAX-enabled Gravity Forms never load a new URL, so a page-load conversion has nothing to fire on.
- A redirect to a thank-you page just to get a trackable URL is an extra hop your lead doesn't need.

Gravity Forms tells the browser exactly when a submission succeeded and the confirmation is on screen. This snippet listens for that signal and reports the conversion then, and only then.

## What it does

| Situation | Reported? |
| --- | --- |
| AJAX form (`ajax="true"` on the shortcode, or the AJAX toggle on the block) shows a **message** confirmation | Yes |
| Form using the newer AJAX submission method (GF 2.9+, see below) shows a **message** confirmation | Yes |
| **Redirect** or **Page** confirmation | No. There is no confirmation to render; fire the conversion on the destination page instead |
| Non-AJAX form (full page postback) | No. Out of scope for this snippet |
| Validation errors, multipage Next / Previous | No |
| Save & Continue ("Save and Continue Later", "link sent" message) | No |
| Submission flagged as spam (newer AJAX method only; the legacy method doesn't expose this) | No |
| Same form confirmed twice in one page load | Once |
| `gtag_report_conversion` isn't defined on the page | Nothing happens, no console error |

By default every form on the site is reported. See [Limiting to specific forms](#limiting-to-specific-forms).

## Requirements

- Gravity Forms 2.9 or later (the newer AJAX path and the [`gform/ajax/post_ajax_submission`](https://docs.gravityforms.com/gform-ajax-post_ajax_submission/) filter this relies on arrived in 2.9.0). Tested against 3.2.1; older versions are untested.
- WordPress 6.5 or later (Gravity Forms' own floor). Tested against 7.1.4.
- The Google tag (`gtag.js`) **and** the Google Ads event snippet for a [*click* conversion](https://support.google.com/google-ads/answer/6331304), both placed in `<head>` as Google instructs. The event snippet is what defines `gtag_report_conversion()`. This file does not load either one.

## Install

Pick one. The snippet is a single function plus two `add_action()` calls, with no settings page.

**Must-use plugin (recommended).** Save `snippet.php` as `wp-content/mu-plugins/crftd-gf-google-conversion.php`. It loads on every request, survives theme switches, and can't be deactivated by accident.

**Code Snippets plugin.** Create a new PHP snippet, paste everything below the opening `<?php` line, set it to run on the front end only, activate.

**Child theme `functions.php`.** Paste everything below the opening `<?php` line at the end of the file.

Then submit a test form and confirm the hit with Google Tag Assistant or the conversion action's diagnostics in Google Ads.

## Limiting to specific forms

Most sites only want the lead form counted, not the newsletter box. Pass the form IDs you care about:

```php
add_filter( 'crftd_gf_google_conversion_form_ids', function () {
	return array( 3, 7 );
} );
```

An empty array (the default) reports every form. Values are run through `absint()` and non-numeric entries are dropped.

## The newer AJAX submission method

Gravity Forms 2.9.0 (2024-11-05) added a second AJAX implementation that posts to `admin-ajax.php` instead of a hidden iframe. As of 3.2.1 it is opt-in through the `gform_form_args` filter:

```php
add_filter( 'gform_form_args', function ( $args ) {
	$args['submission_method'] = GFFormDisplay::SUBMISSION_METHOD_AJAX;
	return $args;
} );
```

That path **does not fire [`gform_confirmation_loaded`](https://docs.gravityforms.com/gform_confirmation_loaded/)**, so the original version of this snippet was silent on it. Version 1.1 listens on the [`gform/ajax/post_ajax_submission`](https://docs.gravityforms.com/gform-ajax-post_ajax_submission/) filter as well and reports when the result is a valid, non-spam `submit` with a `message` confirmation. Both listeners are always installed; whichever method a form uses, the right one fires.

## How it works

1. On `gform_pre_enqueue_scripts` (and `gform_pre_print_scripts`, for forms rendered after the header is sent, such as widgets), the PHP side attaches a small inline script to Gravity Forms' own `gform_gravityforms` handle with `wp_add_inline_script()`. That means the script is only output on pages that render a form, and it always prints after jQuery. It runs once per request no matter how many forms are on the page.
2. The inline script waits for `gform.initializeOnLoaded()`, Gravity Forms' own "my scripts are ready" callback. Gravity Forms defers its front-end scripts, so this is the only safe moment to touch `gform.utils`.
3. It then binds two listeners: `jQuery(document).on('gform_confirmation_loaded')` for the legacy iframe method, and `gform.utils.addFilter('gform/ajax/post_ajax_submission')` for the newer method.
4. When either fires for a real submission, it checks the allow-list, checks that `gtag_report_conversion` exists, records the form ID so it can't double-fire, and calls `gtag_report_conversion()` with no URL (so Google's `event_callback` does not navigate anywhere).

One implementation detail worth knowing if you edit the JavaScript: Gravity Forms adds `defer` to its script tags by string-replacing `" src"` in the tag markup (`GFFormDisplay::add_script_defer()`). An inline script attached to that handle is part of the same markup, so the inline code must never contain the substrings `" src"` or `" defer"`.

## Verified against the source

Everything above was checked against the Gravity Forms 3.2.1 source rather than the docs alone:

- `form_display.php`: the iframe `load` handler triggers `gform_confirmation_loaded` only in the non-redirect confirmation branch; the redirect branch calls `gformRedirect()` and skips it. The Save & Continue "link sent" markup reuses `#gform_confirmation_wrapper_{id}` with the `form_saved_message_sent` class, which is how the snippet tells them apart.
- `includes/ajax/class-gf-ajax-handler.php` and `includes/api.php`: the newer method's JSON result carries `is_valid`, `is_spam`, `submission_type` (`submit`, `next`, `previous`, `save-continue`, `send-link`) and `confirmation_type` (`message` or `redirect`; Page confirmations arrive as `redirect`).
- `assets/js/dist/scripts-theme.min.js`: the newer method's confirmation routine never triggers `gform_confirmation_loaded`; the events it does fire are `gform/ajax/post_submission_request`, `gform/ajax/pre_display_confirmation`, `gform/ajax/post_ajax_submission` and `gform/post_render`.
- `gravityforms.php`: `gform_gravityforms` is registered on `init` with `jquery` as a dependency. The `gform` bootstrap object (which provides `initializeOnLoaded`) is printed either as a `before` inline script on that same handle or, for forms rendered after the header is sent, immediately ahead of the form markup. Both land before this snippet's `after` inline script.
- `GFFormDisplay::get_submission_method()`: the default is still decided by the `ajax` flag (`iframe` when true, `postback` when false); the newer `ajax` method is only used when set explicitly.

## What changed from 1.0

- **Output only where a form is.** 1.0 printed its `<script>` in `wp_footer` on every page of the site as long as Gravity Forms was active. 1.1 attaches to Gravity Forms' script handle, so it only ships on pages that render a form.
- **No more jQuery race.** 1.0 ran `jQuery(document).ready()` at `wp_footer` priority 10, before `wp_print_footer_scripts` (priority 20). On any site that loads jQuery in the footer or defers it, that was a `jQuery is not defined` error and a listener that never bound. 1.1 prints after jQuery and waits for Gravity Forms' own ready callback.
- **Supports the newer AJAX submission method.** 1.0 only knew about `gform_confirmation_loaded`, which the 2.9+ method never fires.
- **Doesn't count non-conversions.** Save & Continue confirmations and (where Gravity Forms exposes it) spam-flagged submissions are skipped. 1.0 reported both.
- **Fires once per form per page load.**
- **Form allow-list** via the `crftd_gf_google_conversion_form_ids` filter.
- **File renamed** from `snippet` to `snippet.php` so GitHub and editors highlight it.

Tested with `php -l`, a stubbed-WordPress harness for the PHP side (hook registration, once-only behavior, filter sanitization, admin bail-out) and a jsdom harness for the browser side (24 cases across both AJAX methods, including the Save & Continue, spam, redirect, allow-list and missing-`gtag` paths) using the real `gforms_hooks.js` from Gravity Forms 3.2.1.

## Credits

Built and maintained by [CRFTD Web Development & Consulting](https://crftd.dev). More free Gravity Forms and WordPress tools at [crftdtools.dev](https://crftdtools.dev).

## License

GPL-2.0-or-later, same as WordPress and Gravity Forms.
