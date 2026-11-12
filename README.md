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

## SES delivery events

Optional. Records what SES did with each email (Send, Delivery, Bounce, Complaint, Reject, DeliveryDelay,
Rendering Failure) in `sent_email_events`, joined to `sent_emails` on `message_id`:

```php
SentEmail::where('to', 'like', '%jane@example.com%')->latest('id')->first()->events;
```

Setup, per environment:

1. In SES (same account and region the app sends from), create an SNS topic and a configuration set with an event
   destination that publishes the event types above to it. The topic policy must let `ses.amazonaws.com` publish
   from that configuration set.
2. Set the env vars below and deploy. Setting `EMAIL_LOG_SES_TOPIC_ARN` registers the webhook route; the
   configuration set is applied to `services.ses.options` unless the app already sets one there.
3. Subscribe `https://<app>/email-log/ses-events` to the topic over HTTPS. The app confirms the subscription.

| Env | Default | |
|---|---|---|
| `EMAIL_LOG_SES_TOPIC_ARN` | none | The SNS topic. Events are only accepted from this topic, with a valid AWS signature. |
| `EMAIL_LOG_SES_CONFIGURATION_SET` | none | The SES configuration set to send through. |
| `EMAIL_LOG_SES_EVENTS_PATH` | `email-log/ses-events` | Webhook path. |

The route has no auth middleware, because SNS can't send a token. It rejects anything not signed by SNS for the
configured topic, checks the topic before downloading a signing certificate, caches certificates for a day, stores
each SNS message once, and is throttled to 300 requests a minute. Events are pruned with `EMAIL_LOG_RETENTION_DAYS`.
History starts when this is switched on; SES keeps nothing to backfill.
