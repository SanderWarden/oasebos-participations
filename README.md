# Oasebos Participations

Custom WordPress plugin for Stichting Oasebos project participations, one-time donations, recurring donation foundations, Mollie payments, dynamic templates, PDF generation and email confirmations.

## Installation

1. Copy `oasebos-participations` into `wp-content/plugins/`.
2. Optional but recommended: run `composer install --no-dev` inside the plugin directory to install the Mollie SDK and Dompdf.
3. Activate **Oasebos Participations** in WordPress admin.
4. Open **Oasebos → Settings** and configure Mollie API key, organization details, and sender email.
5. Configure Mollie webhook URL shown on the settings page: `/wp-json/oasebos/v1/mollie-webhook`.

## Shortcodes

- `[oasebos_participation_form]`
- `[oasebos_donation_form amounts="25,50,100"]`
- `[oasebos_recurring_donation_form amounts="10,25,50" intervals="1 month,1 year"]`
- `[oasebos_payment_return]`

## Implemented MVP foundation

- Plugin bootstrap with PSR-4-style fallback autoloading.
- Activation database schema for projects, templates, participations, donations, recurring donations, payment logs, and email logs.
- Admin menu for dashboard, projects, templates, participations, donations, recurring donations, settings, payment logs, emails, and exports.
- CRUD foundations for projects and templates with nonce/capability checks.
- Read-only admin listings and CSV exports for operational records.
- Frontend participation, donation, and recurring donation forms with nonce protection.
- Dynamic tag rendering service for template content.
- Mollie integration boundary using the official SDK when installed; graceful stub flow when SDK/API key is missing.
- REST webhook route with idempotent paid-state handling.
- PDF service using Dompdf when installed; graceful HTML fallback when missing.
- Email service with `wp_mail` and audit logs.

## Manual setup before going live

- Run `composer install --no-dev` in this plugin directory so Mollie SDK and Dompdf are available.
- Add the Mollie API key, sender details and organization details in **Oasebos → Settings**.
- Configure the Mollie webhook URL shown in settings: `/wp-json/oasebos/v1/mollie-webhook`.
- Create active Oasebos projects and assign agreement/certificate templates.
- Add the shortcodes to WordPress pages and test all flows in Mollie test mode before switching to a live key.

## Implemented production support

- Mollie first recurring payment, customer creation, mandate detection, subscription creation, subscription sync and cancellation actions.
- Secure admin PDF downloads through signed admin-post URLs.
- Admin resend actions for participation, donation and recurring confirmations.
- Atomic hectare deduction during paid participation processing to reduce oversell risk.
- Automatic database schema upgrades when the plugin version changes.

## Recommended next hardening

- Add automated tests in a WordPress test harness.
- Replace the simple custom admin tables with full `WP_List_Table` classes if large datasets require bulk actions and advanced pagination.
- Review email copy/templates and legal text with Stichting Oasebos before production launch.
