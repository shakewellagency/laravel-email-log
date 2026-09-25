# laravel-email-log

Records every email a Laravel app sends in a `sent_emails` table: from, to, cc, bcc, subject, body, the
provider's message ID (SES's `X-SES-Message-ID` when sending through SES) and the Mailable or Notification class
(Mailable class from Laravel 11; Laravel 10 only passes Notification classes).

- Rows are written after the mail transport accepts the message, so only mail that actually went out is recorded.
- A logging failure is reported but never fails the send.
- No UI, routes or attachment storage.

Laravel 10–13, PHP 8.1+.

## Install

```bash
composer config repositories.laravel-email-log vcs https://github.com/shakewellagency/laravel-email-log
composer require shakewellagency/laravel-email-log
php artisan migrate
```

The migration creates `sent_emails`, or adds `message_id` and `mailable` to one created by
`dcblogdev/laravel-sent-emails`. Existing rows and columns are kept, so it's a drop-in replacement: remove that
package, its `config/sentemails.php` and its published views.

## Configuration

| Env | Default | |
|---|---|---|
| `EMAIL_LOG_ENABLED` | `true` | Turn logging off. |
| `EMAIL_LOG_RETENTION_DAYS` | none | Delete rows older than this when `php artisan model:prune` runs (schedule it daily). |

Query the log through `Shakewell\EmailLog\Models\SentEmail`.
