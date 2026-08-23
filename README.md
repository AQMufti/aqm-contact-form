# A. Q. Mufti – Contact Form

A self-contained WordPress multi-form builder. Each form has its own fields, dropdowns, editable comboboxes, CAPTCHA, spam protection and notification settings. No external services and no third-party libraries.

Place the form on any page with the shortcode:

```
[aqm_form id="1"]
```

## Features

- **Stores every enquiry** in the database as well as emailing it, so nothing is lost if mail delivery fails
- **Visual form builder** — add, rename, reorder (drag and drop) and delete fields and dropdown options without touching code
- **Ten field types** including a combobox where visitors can pick from a list *or* type their own value
- **Unlimited forms**, each with its own fields, recipient, subject and settings
- **Spam protection** — signed maths CAPTCHA, honeypot field, timing check and per-IP rate limiting
- **Auto-reply** to the person who wrote in, with configurable wording
- **Submissions screen** with search, per-form filtering, pagination, bulk delete and CSV export
- **Privacy** — IP storage can be switched off per form
- **Accessible** — per-field error messages, ARIA wiring, focus management, autocomplete attributes and WCAG AA contrast
- **Self-updating** from this repository's releases

## Requirements

- WordPress 5.8 or later
- PHP 7.4 or later

## Installation

1. Download the latest `aqm-contact-form.zip` from [Releases](../../releases)
2. In WordPress: **Plugins → Add New → Upload Plugin**
3. Choose the ZIP and click **Install Now**
4. If a previous version is installed, WordPress offers **Replace current with uploaded** — your forms, fields and submissions are preserved
5. Activate, then open **AQM Contact → Form Builder** to set the notification address for each form

## Updates

Once installed, the plugin checks this repository for new releases and shows the update in **Dashboard → Updates** like any other plugin. You can also enable automatic updates from the Plugins screen.

## Releasing a new version

1. Update the `Version:` header and the `AQM_VERSION` constant in `aqmcontactform.php` — they must match
2. Build the ZIP so that it contains a single top-level folder named `aqm-contact-form`
3. Create a new release on GitHub, tag it `v<version>` (for example `v7.1.1`)
4. **Attach the ZIP to the release** — this matters. GitHub's automatic source download names its folder after the commit, which would install the plugin as a duplicate rather than updating it
5. Publish. Sites check for updates twice a day, or immediately via the "Check for updates" link on the Plugins screen

## Configuration

Per-form options live in **AQM Contact → Form Builder → Form Settings**: recipient and Cc, email subject, auto-reply, thank-you message, CAPTCHA, silent spam traps and IP storage. Site-wide options (rate limit, proxy header, uninstall behaviour) live under **AQM Contact → Settings**.

If the site sits behind Cloudflare or a load balancer, choose your proxy under **AQM Contact → Settings** — otherwise every visitor appears to share one IP address and rate limiting will block genuine enquiries. The settings screen shows which IP address it currently detects so you can confirm it is right.

## License

GPL-2.0-or-later
