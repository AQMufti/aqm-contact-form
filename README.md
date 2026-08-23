# A. Q. Mufti – Contact Form

A self-contained WordPress contact form plugin with database storage, admin-managed event types, spam protection and CSV export. No external services and no third-party libraries.

Place the form on any page with the shortcode:

```
[aqm_contact_form]
```

## Features

- **Stores every enquiry** in the database as well as emailing it, so nothing is lost if mail delivery fails
- **Admin-managed dropdown** — add, rename, reorder and delete the options without touching code, and rename the field itself ("Type of Event", "Session Type", "Reason for Contact", whatever suits)
- **Spam protection** without a CAPTCHA — honeypot field, signed timing check, per-IP rate limiting and a link threshold
- **Auto-reply** to the person who wrote in, with configurable wording
- **Entries screen** with search, filtering, pagination, single-entry view, bulk delete and CSV export
- **Privacy tools** — optional consent checkbox, optional and anonymisable IP storage, and integration with WordPress's built-in Export/Erase Personal Data tools
- **Accessible** — per-field error messages, ARIA wiring, focus management, autocomplete attributes and WCAG AA contrast
- **Self-updating** from this repository's releases

## Requirements

- WordPress 5.8 or later
- PHP 7.4 or later

## Installation

1. Download the latest `aqm-contact-form.zip` from [Releases](../../releases)
2. In WordPress: **Plugins → Add New → Upload Plugin**
3. Choose the ZIP and click **Install Now**
4. If a previous version is installed, WordPress offers **Replace current with uploaded** — your entries and settings are preserved
5. Activate, then visit **AQM Contact → Settings** to set the notification address

## Updates

Once installed, the plugin checks this repository for new releases and shows the update in **Dashboard → Updates** like any other plugin. You can also enable automatic updates from the Plugins screen.

## Releasing a new version

1. Update the `Version:` header and the `AQM_CF_VERSION` constant in `aqmcontactform.php` — they must match
2. Build the ZIP so that it contains a single top-level folder named `aqm-contact-form`
3. Create a new release on GitHub, tag it `v<version>` (for example `v2.2.1`)
4. **Attach the ZIP to the release** — this matters. GitHub's automatic source download names its folder after the commit, which would install the plugin as a duplicate rather than updating it
5. Publish. Sites check for updates twice a day, or immediately via the "Check for updates" link on the Plugins screen

## Configuration

Everything is configured under **AQM Contact → Settings**: the notification recipient and Cc list, the auto-reply, the thank-you message, spam thresholds, the consent checkbox, IP storage, and whether to delete the data if the plugin is ever removed.

If the site sits behind Cloudflare or a load balancer, choose your proxy in the spam protection section — otherwise every visitor appears to share one IP address and rate limiting will block genuine enquiries. The settings screen shows which IP address it currently detects so you can confirm it is right.

## License

GPL-2.0-or-later
