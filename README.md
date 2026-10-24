# Prontuno PHP SDK

Framework-agnostic PHP client for the [Prontuno API](https://prontuno.com/api-reference/general/introduction/). It supports email delivery, sending domains, suppressions, webhook endpoints, API keys, SMTP credentials, cursor pagination, and signed webhook events.

> Using Laravel? Install [`prontuno/prontuno-laravel`](https://github.com/prontuno/prontuno-laravel) for a facade, config file, mail transport, and webhook middleware.

## Installation

```bash
composer require prontuno/prontuno-php
```

PHP 8.3 or newer is required. The SDK uses [Guzzle](https://docs.guzzlephp.org/) for HTTP.

## Quick start

Prontuno has two kinds of API key: a **sending key**, which can only send email from one verified domain, and a **management key**, which can do everything else. Give the client both and it uses the right one for each call:

```php
use Prontuno\Prontuno;

$prontuno = new Prontuno(
    sendingKey: getenv('PRONTUNO_SENDING_KEY') ?: null,       // emails->send() and emails->sendBatch()
    managementKey: getenv('PRONTUNO_MANAGEMENT_KEY') ?: null, // everything else
);

$result = $prontuno->emails->send([
    'from' => 'Acme <hello@mg.example.com>',
    'to' => 'ada@example.com',
    'subject' => 'Welcome to Acme',
    'html' => '<h1>Welcome!</h1>',
], idempotencyKey: 'welcome-ada');

foreach ($result['emails'] as $email) {
    echo $email['id'], ' ', $email['status'], PHP_EOL; // queued or rejected
}
```

Requests and responses use the API's snake_case field names and are plain PHP arrays.

## API keys and routing

| Method | Key used |
| --- | --- |
| `emails->send()`, `emails->sendBatch()` | `sendingKey` |
| Everything else, including `emails->list()` and `emails->retrieve()` | `managementKey` |

- **One key for everything:** `new Prontuno($apiKey)` uses that key for every request, for example a send-only app with just a sending key. `sendingKey` and `managementKey` take precedence over `$apiKey`.
- **Missing keys fail early:** calling a method whose key isn't configured throws a `ConfigurationException` before any request is made, for example "GET /domains requires a management API key; pass managementKey (or apiKey) when creating the client." instead of a `403` from the API.
- **Blank keys count as unset**, so an empty environment variable doesn't send an empty `Authorization` header. `getenv()` returns `false` for unset variables, hence the `?: null` in the examples.
- **Several sending domains:** each sending key belongs to one domain. `withSendingKey()` returns a client for another domain that keeps all other settings, including the management key:

```php
$billing = $prontuno->withSendingKey((string) getenv('PRONTUNO_BILLING_SENDING_KEY'));
$billing->emails->send(['from' => 'billing@billing.example.com', /* ... */]);
```

## Configuration

```php
$prontuno = new Prontuno(sendingKey: $sendingKey, managementKey: $managementKey, options: [
    'base_url' => 'https://api.prontuno.net/v1',
    'timeout' => 30,          // seconds
    'connect_timeout' => 10,  // seconds
    'max_retries' => 2,
    'app_info' => 'my-app/1.2.0',   // sent as "prontuno-php/0.1.0 my-app/1.2.0"
    'http_client' => new GuzzleHttp\Client([...]), // optional custom Guzzle client
]);
```

The base URL defaults to `https://api.prontuno.net/v1`. Set `PRONTUNO_BASE_URL` in the environment to change it without code changes; an explicit `base_url` option takes precedence, and a blank value falls back to the default.

Custom endpoints must use HTTPS.

GET and DELETE requests are retried on `408`, `429`, `5xx`, and network errors. Email sends are retried only when you pass an idempotency key; other POST and PATCH requests are never retried automatically. `Retry-After` is honoured, capped at 60 seconds. The timeout applies to each attempt. If a DELETE is retried after an attempt whose outcome is unknown (a network error, timeout, or `5xx`) and the retry finds the resource already gone (`404`, or `409 already_revoked`), the call succeeds.

`$prontuno->lastResponse()` returns the request ID and rate-limit headers from the most recent response.

## Emails

```php
use Prontuno\Attachment;

$prontuno->emails->send([
    'from' => 'Acme <hello@mg.example.com>',
    'to' => ['ada@example.com', 'grace@example.com'],
    'cc' => 'team@example.com',
    'reply_to' => 'support@example.com',
    'subject' => 'Your report',
    'text' => 'The report is attached.',
    'headers' => ['X-Entity-Ref-ID' => 'report-42'],
    'attachments' => [
        Attachment::fromPath('/path/to/report.pdf', contentType: 'application/pdf'),
        Attachment::fromContent('notes.txt', 'Plain text content', 'text/plain'),
    ],
], idempotencyKey: 'report-42');

// Up to 1,000 emails. If any item is invalid, nothing is sent.
$prontuno->emails->sendBatch([
    ['from' => 'hello@mg.example.com', 'to' => 'ada@example.com', 'subject' => 'Hi', 'text' => 'Hi Ada'],
    ['from' => 'hello@mg.example.com', 'to' => 'grace@example.com', 'subject' => 'Hi', 'text' => 'Hi Grace'],
], idempotencyKey: 'newsletter-2026-10');

$email = $prontuno->emails->retrieve('email_01kx...');
echo $email['status'], $email['text'], $email['html']; // bodies are null when absent or unavailable

$page = $prontuno->emails->list(limit: 50, status: 'delivered', domainId: 'dom_01kx...');

foreach ($prontuno->emails->listAll(status: 'bounced') as $email) {
    echo $email['to'], ': ', $email['rejection_reason'], PHP_EOL;
}
```

Each email can have up to 100 recipients across `to`, `cc`, and `bcc`, and must be 25 MB or less once encoded with its attachments (larger emails are rejected with `413 message_too_large`). A batch holds up to 1,000 emails; if any item is invalid, nothing is sent.

Email statuses are `queued`, `sent`, `deferred`, `delivered`, `bounced`, `expired`, `rejected`, and `complained`.

`retrieve()` returns the full email, including its stored `text` and `html` bodies and its `attachments`. List results contain only the status and metadata, so call `retrieve()` when you need the content.

### Attachments

Attachments on a sent email come with a short-lived signed `download_url` that is valid for one hour, until `expires_at`. Both are `null` while a file hasn't finished uploading, and `emails->attachments->retrieve()` throws an `ApiException` with `403 attachment_unavailable` until it has.

```php
$page = $prontuno->emails->attachments->list('email_01kx...', limit: 20);

foreach ($prontuno->emails->attachments->listAll('email_01kx...') as $attachment) {
    echo $attachment['filename'], ' ', $attachment['size'], PHP_EOL;
}

// Fetch a fresh link for one attachment, then download it.
$attachment = $prontuno->emails->attachments->retrieve('email_01kx...', 'att_01kx...');
if ($attachment['download_url'] !== null) {
    $contents = file_get_contents($attachment['download_url']);
}
```

Download links expire, so request a fresh one with `emails->attachments->retrieve()` (or `emails->retrieve()`) instead of storing them. Treat `download_url` as opaque: it may be served from Prontuno's CDN or directly from storage, so don't parse it or depend on its host or path. Fetch it directly, without your API key. The file downloads under its original filename. `content_disposition` (`inline` or `attachment`) describes how the file was attached to the email, not how the link is served. A temporary storage outage returns a retryable `503 storage_unavailable`, which the SDK retries automatically.

## Pagination

`list()` returns a single page (`data` and `pagination`). Pass `pagination.next_cursor` back as `cursor` to fetch the next page. `listAll()` returns a generator that follows cursors for you. It reads at most 1,000 pages by default (set `maxPages` to change this) and stops with a `ConnectionException` if the API repeats a cursor.

## Domains

```php
$domain = $prontuno->domains->create('mg.example.com');

foreach ($domain['dns_records'] as $record) {
    echo "{$record['record_type']} {$record['hostname']} {$record['value']}", PHP_EOL;
}

$prontuno->domains->retrieve($domain['id']);   // check verification progress
$prontuno->domains->list();
```

## Suppressions

```php
$prontuno->suppressions->create('user@example.com');
$prontuno->suppressions->retrieve('user@example.com');   // by email or sup_ ID
$prontuno->suppressions->list(email: 'user@example.com');
$prontuno->suppressions->delete('user@example.com');
```

Only manual suppressions can be deleted. Hard-bounce and complaint suppressions return `403 suppression_not_removable`.

## Webhook endpoints

```php
$webhook = $prontuno->webhooks->create(
    name: 'Production app',
    url: 'https://example.com/webhooks/prontuno',
    events: ['email.delivered', 'email.bounced', 'email.complained'],
    // domainId: 'dom_01kx...', // scope to one verified domain; omit for team-wide
);

$secret = $webhook['secret']; // returned only once, so store it securely

$prontuno->webhooks->retrieve($webhook['id']);
$prontuno->webhooks->update($webhook['id'], ['is_enabled' => false]);
$prontuno->webhooks->update($webhook['id'], ['is_global' => false, 'domain_id' => 'dom_01kx...']); // switching scope needs is_global too
$prontuno->webhooks->rotateSecret($webhook['id']); // old secret stops working immediately
$prontuno->webhooks->list();
$prontuno->webhooks->delete($webhook['id']);
```

### Event types

| Event | Constant | Sent when |
| --- | --- | --- |
| `email.sent` | `WebhookEvent::EMAIL_SENT` | Prontuno's outbound mail server accepted the email for delivery. |
| `email.delivered` | `WebhookEvent::EMAIL_DELIVERED` | The recipient's mail server accepted the email. |
| `email.deferred` | `WebhookEvent::EMAIL_DEFERRED` | The recipient's mail server temporarily refused the email. Delivery is retried. |
| `email.bounced` | `WebhookEvent::EMAIL_BOUNCED` | The recipient's mail server permanently refused the email. |
| `email.expired` | `WebhookEvent::EMAIL_EXPIRED` | Delivery was retried until the retry period ran out without success. |
| `email.rejected` | `WebhookEvent::EMAIL_REJECTED` | Prontuno refused the email before sending it, for example because the recipient is on the suppression list. |
| `email.complained` | `WebhookEvent::EMAIL_COMPLAINED` | The recipient reported the email as spam to their mailbox provider. |

Subscribe to any combination with `events`. Each event's `data` describes the email it relates to.

## API keys

Managing API keys requires a `management` API key. A key's permissions (scopes) come from its type:

| Type | Scopes |
| --- | --- |
| `sending` | `email:send` for one verified domain (`domainId` required) |
| `mcp` | `domains:*`, `emails:read`, `inbound:read`, `suppressions:*`, `usage:read`, `webhooks:*`, plus `email:send` with `allowEmailSending: true` and a `domainId` |
| `management` | The `mcp` account scopes without `email:send`, plus `api_keys:*` and `smtp_credentials:*` |

```php
$key = $prontuno->apiKeys->create('Production sender', 'sending', domainId: 'dom_01kx...');
$plaintext = $key['key']; // returned only once, so store it securely

$prontuno->apiKeys->create('Support agent', 'mcp');
$prontuno->apiKeys->create('Agent that sends', 'mcp', domainId: 'dom_01kx...', allowEmailSending: true);
$prontuno->apiKeys->create('Ops automation', 'management');

$prontuno->apiKeys->retrieve($key['id']);                     // includes revoked keys
$prontuno->apiKeys->list(status: 'all', type: 'sending');  // active keys only by default

foreach ($prontuno->apiKeys->listAll(status: 'revoked') as $revoked) {
    // ...
}

$prontuno->apiKeys->revoke($key['id']); // fails immediately; revoking twice returns 409 already_revoked
```

## SMTP credentials

```php
$credential = $prontuno->smtpCredentials->create('Legacy CRM', 'dom_01kx...');

// Use these as the SMTP login. The password is returned only once.
[$username, $password] = [$credential['username'], $credential['password']];

$reset = $prontuno->smtpCredentials->resetPassword($credential['id']); // old password stops working
$prontuno->smtpCredentials->retrieve($credential['id']);
$prontuno->smtpCredentials->list(status: 'all');
$prontuno->smtpCredentials->revoke($credential['id']); // SMTP logins fail immediately
```

Creating API keys and SMTP credentials, and resetting passwords, are never retried automatically, so a timeout can't create a duplicate secret.

## Usage

```php
$usage = $prontuno->usage->retrieve();

if ($usage['subscription']['is_active'] ?? false) {
    echo $usage['subscription']['recipients_remaining']; // null on the unlimited plan
}
echo $usage['credits']['balance']; // prepaid credits; one covers one recipient
echo $usage['limits']['domains']['limit']; // null when unlimited
```

Sends draw on the subscription allowance first while it's active, then on prepaid credits. `subscription` is `null` when the team has no plan, and `recipients_remaining` is `0` while the subscription isn't active.

## Receiving webhooks

Prontuno signs the exact raw request body. Verify the body before you parse it.

```php
use Prontuno\Webhooks\Exceptions\WebhookVerificationException;
use Prontuno\Webhooks\WebhookEvent;
use Prontuno\Webhooks\WebhookVerifier;

$verifier = new WebhookVerifier((string) getenv('PRONTUNO_WEBHOOK_SECRET'));

try {
    $event = $verifier->constructEvent(
        file_get_contents('php://input'),
        $_SERVER['HTTP_PRONTUNO_SIGNATURE'] ?? '',
    );
} catch (WebhookVerificationException) {
    http_response_code(400);
    exit;
}

if ($event->is(WebhookEvent::EMAIL_BOUNCED)) {
    // $event->data['to_email'], $event->data['bounce']['type'], ...
}

http_response_code(204);
```

Signatures older than five minutes are rejected. Pass a different `tolerance` (in seconds) as the second constructor argument, or `0` to disable the check. Deliveries can be retried, so use `$event->eventId` to make your handler idempotent.

The client has the same helpers: `$prontuno->webhooks->constructEvent($payload, $signature, $secret)` and `$prontuno->webhooks->verifySignature($payload, $signature, $secret)`.

Webhook requests include these headers:

- `Prontuno-Event`
- `Prontuno-Event-Id`
- `Prontuno-Webhook-Id`
- `Prontuno-Signature` (`t=<unix-seconds>,v1=<hex-hmac>`)
- `Prontuno-Delivery-Attempt`
- `Idempotency-Key` (the event ID)

See [Event types](#event-types) for every event, its `WebhookEvent` constant, and when it's sent.

## Errors

Every SDK exception implements `Prontuno\Exceptions\ProntunoException`.

| Exception | When |
| --- | --- |
| `Prontuno\Exceptions\ApiException` | The API returned a non-2xx response |
| `Prontuno\Exceptions\ConnectionException` | Network failure, invalid response, or pagination fault |
| `Prontuno\Exceptions\ConfigurationException` | Invalid client options or arguments |
| `Prontuno\Webhooks\Exceptions\WebhookVerificationException` | Webhook signature or payload failed verification |

```php
use Prontuno\Exceptions\ApiException;

try {
    $prontuno->emails->send($email);
} catch (ApiException $e) {
    $e->status();           // 400
    $e->errorCode();        // "validation_failed"
    $e->getMessage();
    $e->requestId();        // x-request-id, useful for support
    $e->isRetryable();
    $e->suggestedAction();  // e.g. "fix_input", "top_up_credits"
    $e->retryAfter();       // seconds, when rate limited
    $e->errors();           // field errors, or per-item errors (with "index") for batches
}
```

## Testing

```bash
composer lint           # format the code with Pint
composer test           # static analysis, formatting check, and the test suite
composer test:coverage  # requires pcov or Xdebug; fails below 100% line coverage
```

## License

The Prontuno PHP SDK is open-sourced software licensed under the [MIT license](LICENSE.md).
