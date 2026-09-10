# Plugin: Email Verification and Double Opt-In (DOI) by Leuchtfeuer

Universal email verification plugin for Mautic, providing Double Opt-In (DOI) functionality with seamless form integration.

## Overview / Purpose / Features

- **Form Action Management**: Separate actions for immediate submission vs. post-verification
- **Email Verification**: Built-in DOI workflow with `{doi_link}` token support
- **Follow-up Emails**: Automated reminders for unconfirmed submissions
- **Link Expiration**: Configurable timeout for DOI verification links
- **Flexible Redirects**: Configurable success/error pages after verification
- **Security**: HMAC-based hash generation for verification links
- **Console Commands**: Cron-compatible follow-up email sending, timeout processing, and optional cleanup of expired submissions
- **Compliance**: Snapshots field labels and submitted values at the point of submission, so historical DOI records stay accurate and readable even if the form or its fields change later

## Requirements for this release
> [!TIP]
> Other releases of this plugin may cover different Mautic versions!
- Mautic 5.2 
- PHP 8.1 or higher


## Installation

### Composer
This plugin can be installed through composer.

### Manual Installation
Alternatively, it can be installed manually, following the usual steps:
1. Extract to `plugins/LeuchtfeuerDoiBundle/`
2. Run `php bin/console cache:clear`
3. Run `php bin/console mautic:plugins:reload`
4. Configure plugin settings in Mautic admin

## Configuration

### Plugin Settings
- **Follow-up wait time**: Hours before sending reminder emails (default: 24 hours)
- **DOI link timeout**: Hours after which verification links expire (default: 48 hours)

### Cron Jobs
Set up the following cron jobs for automated processing:
- `php bin/console leuchtfeuer:doi:send-followup` - Send follow-up reminder emails
- `php bin/console leuchtfeuer:doi:update-timeout` - Mark expired pending submissions as timed out
- `php bin/console leuchtfeuer:doi:cleanup-submissions` - Delete expired pending submissions (per-form setting)


### Form Configuration
1. **Actions Tab**: Configure immediate vs. post-verification actions
2. **Email Verification Tab**:
   - Enable DOI for this form
   - Verification email to send (required when DOI is enabled)
   - Follow-up email to send (optional)
   - Thank you page redirect URL (optional)
   - Verification error redirect URL (optional)
   - Configure Conditions for skipping the verification (optional)
   - Delete in case of DOI timeout (optional)

## Usage

1. Create verification email with `{doi_link}` token
2. Configure form with DOI settings
3. Form submissions trigger verification email
4. Users click verification link to confirm
5. Post-verification actions execute automatically

## Known Issues
- Campaign Forms do not withhold the contact from the campaign until the email-verification has been successful, but instead start the campaign immediately

## Troubleshooting
Make sure you have not only installed but also enabled the Plugin.
If things are still funny, please try
`php bin/console cache:clear`
and
`php bin/console mautic:assets:generate`
## Change log
- https://github.com/Leuchtfeuer/mautic-DOI-bundle/releases
  
## Future Ideas
- Campaign integration: Start campaign from form action with conditional contact updates (e.g. MOI=1).
- Missing email handling: Manage cases with empty or unmapped leads.email.
- Form action – Update Marketing Opt-In: Convenience action to set MOI fields and audit values.
- MOI data model: Fixed fields or dedicated table for bool + audit tracking.
- MOI flavor support: Allow multiple brand-specific opt-in variants defined in plugin config.
- Feedback pages: Support Mautic landing pages and generic preset URLs for thank-you/error redirects.
- Multi-language support: Translated DOI emails, language-aware redirects and feedback pages.
- Multi-brand support: URL-aware feedback pages per brand.
- Form behaviour: Per-form follow-up wait time setting.
- Honeypot support: NHI field awareness and handling.
- DOI email restriction: Only show emails containing `{doi_link}` token.
- Audit trail (future): Optional contact field audit log (leads.emailverifications_audit) for verification events.
- Persist content of checkbox field at time of consent


## Sponsoring & Commercial Support
We are continuously improving our plugins. If you are requiring priority support or custom features, please contact us at mautic-plugins@leuchtfeuer.com.

## Get Involved
Feel free to open issues or submit pull requests on [GitHub](#). Follow the contribution guidelines in `CONTRIBUTING.md`.”

## Credits
@patrykgruszka
@biozshock

## Author
Leuchtfeuer Digital Marketing GmbH
Please raise any issues in GitHub.
For all other things, please email mautic-plugins@Leuchtfeuer.com

## License
This plugin is licensed under the GPL v3 License.


