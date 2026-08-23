# Privacy request runbook

Signed-in users request an encrypted export or deletion review from their account.
Exports are built by a queue worker, encrypted with the application key, checksum
verified on download, scoped to the authenticated subject, and expired after seven
days by `privacy:enforce-retention`.

For deletion, support must verify identity through an authenticated channel, record
the applicable jurisdiction and lawful retention exceptions, and obtain an approval
reference. Agency ownership must first be reassigned or the agency formally closed.
After approval, run:

```bash
php artisan privacy:process-deletion REQUEST_UUID --approval-reference=TICKET_ID
```

The command revokes active credentials, deactivates memberships, removes private
engagement and notification data, unsubscribes matching newsletters, redacts direct
consumer content, and anonymizes the account. Consent/audit evidence and business
records with a lawful retention basis remain restricted and linked only to the
anonymized identity.

Run `php artisan privacy:enforce-retention --dry-run` before policy changes. The
scheduled non-dry run removes expired raw analytics, identifiers, encrypted exports,
and abandoned invitation-only accounts. Any legal hold must be implemented and tested
before a request subject to that hold is processed.
