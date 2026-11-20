# E2K Payment Bundle Documentation

**E2K Payment Bundle** is a unified payment gateway abstraction layer for Symfony 6.4 (LTS) and 7.x applications. It provides a standardized contract for handling payment initiation, webhook signature validation, IPN callback processing, and status checks across multiple payment providers.

---

## Table of Contents

1. [Architecture & Concepts](#1-architecture--concepts)
2. [Installation](#2-installation)
3. [Configuration](#3-configuration)
4. [Services & Dependency Injection](#4-services--dependency-injection)
5. [Data Transfer Objects (DTOs)](#5-data-transfer-objects-dtos)
6. [Enums Reference](#6-enums-reference)
7. [Usage Guide](#7-usage-guide)
   - [Initiating a Payment](#initiating-a-payment)
   - [Handling IPN / Webhooks](#handling-ipn--webhooks)
   - [Querying Transaction Status](#querying-transaction-status)
   - [CentralBill Polling (server-side API)](#centralbill-polling-server-side-api)
8. [Adding a Custom Payment Gateway](#8-adding-a-custom-payment-gateway)
9. [Exception Handling & Error Format](#9-exception-handling--error-format)
10. [Translations & Localization](#10-translations--localization)
11. [Testing & Quality](#11-testing--quality)

---

## 1. Architecture & Concepts

The bundle decouples your business logic from specific payment providers:

```
[Application Controller / Service]
              │
              ▼
   [ActiveGatewayResolver]
              │
              ▼
  [PaymentGatewayRegistry] (Service Locator)
         ┌────┴────────────────────────┐
         ▼                             ▼
 [CentralBillGateway]          [PaystackGateway]  ... [CustomGateway]
```

- **`PaymentGatewayInterface`**: The core contract implemented by all gateways (composed of `PaymentInitiatorInterface` and `CallbackHandlerInterface`).
- **`PaymentGatewayRegistry`**: A lazy-loaded Symfony Service Locator managing all registered gateways tagged with `e2k_payment.gateway`.
- **`ActiveGatewayResolver`**: Resolves the default configured gateway or a specific gateway by name.
- **`PaymentExceptionListener`**: Kernel exception listener intercepting bundle exceptions on payment routes to return standard JSON API error responses.

---

## 2. Installation

### Requirements

- **PHP**: `8.2` or higher
- **Symfony**: `^6.4` (LTS) or `^7.4`
- **HTTP Client**: `symfony/http-client` (required when using the Paystack gateway: `composer require symfony/http-client`)
- **Translation** (optional): `symfony/translation` to get translated error messages. Without it, the exception listener falls back to English messages.

### Composer

```bash
composer require e2k/payment-bundle
```

If Symfony Flex is not used, register the bundle manually:

```php
// config/bundles.php
return [
    // ...
    E2K\PaymentBundle\E2KPaymentBundle::class => ['all' => true],
];
```

---

## 3. Configuration

### Package Configuration

Create `config/packages/e2k_payment.yaml`:

```yaml
# config/packages/e2k_payment.yaml
e2k_payment:
    # Default gateway name resolved when none is specified
    default_gateway: '%env(PAYMENT_DEFAULT_GATEWAY)%'

    # Route prefix intercepted by PaymentExceptionListener (default: /payments/, cannot be empty)
    payment_route_prefix: '%env(PAYMENT_ROUTE_PREFIX)%'

    gateways:
        centralbill:
            enabled: true
            application_id: '%env(CENTRALBILL_APPLICATION_ID)%'
            application_secret: '%env(CENTRALBILL_APPLICATION_SECRET)%'
            web_pay_baseUrl: '%env(CENTRALBILL_WEB_PAY_BASE_URL)%'
            callback_url: '%env(CENTRALBILL_CALLBACK_URL)%'       # Optional
            redirect_url: '%env(CENTRALBILL_REDIRECT_URL)%'       # Optional
            callback_tolerance: 300                                # Optional, max age (s) of the signed IPN "Date" header. 0 disables the check
            api_base_url: '%env(CENTRALBILL_API_URL)%'            # Optional, e.g. https://api.centralbill.app: enables server-side pre-creation + polling
            initiate_timeout: 6                                    # Optional, timeout (s) of the pre-creation (the customer is waiting)
            status_timeout: 15                                     # Optional, timeout (s) of a payment lookup (polling)

        paystack:
            enabled: true
            secret_key: '%env(PAYSTACK_SECRET_KEY)%'
            api_base_url: '%env(PAYSTACK_API_BASE_URL)%'          # Optional, default: https://api.paystack.co
            callback_url: '%env(PAYSTACK_CALLBACK_URL)%'         # Optional
            timeout: 10                                           # Optional, timeout (s) of each Paystack API call
            webhook_secret: '%env(PAYSTACK_WEBHOOK_SECRET)%'      # Recommended, webhook signing secret (whsec_...)
```

> **Paystack webhook signature**: Paystack signs webhooks in two ways ([docs](https://paystack.com/docs/payments/webhooks/#signature-validation)):
>
> | `webhook_secret` | Header verified | Replay protection |
> |---|---|---|
> | set (`whsec_...`) | `Paystack-Signature: t=...,v1=<key id>:<hmac>`: HMAC-SHA512 of `{t}.{raw body}` with the signing secret; every `v1` is checked (secret rotation) | Yes: events whose `t` is more than 300 s away from the server clock are rejected (`callback.expired_request`). Paystack re-signs retries with a new `t`. |
> | not set / empty | `x-paystack-signature`: HMAC-SHA512 of the raw body with `secret_key` | No |
>
> Once `webhook_secret` is set, `Paystack-Signature` is **mandatory**: the legacy header is no longer accepted, otherwise an attacker could strip the timestamped header and replay an old event. Generate the signing secret on your Paystack dashboard (Developers > API configuration) and make sure your events carry `Paystack-Signature` before setting it. Paystack stops sending `x-paystack-signature` once you delete your secret key, so configuring `webhook_secret` is required in that case.

> **CentralBill IPN authenticity**: the IPN signature (HMAC-SHA256 keyed with the application secret, sent in `Signature` or `Authorization: Signature ...`) covers the `Digest` header, and the bundle checks that this digest matches the received body. An IPN whose signature does not cover `digest`, or whose body does not match it, is rejected (`callback.invalid_signature`, HTTP 400). Do not rewrite the request body (proxy, middleware) before it reaches the bundle.
>
> **CentralBill IPN freshness**: when the signed `headers` list of an IPN contains `date`, the bundle rejects notifications whose `Date` is more than `callback_tolerance` seconds away from the server clock (`callback.expired_request`, HTTP 400). Keep your server clock synchronized (NTP). This limits replays of captured notifications, but your application must still process callbacks idempotently (e.g. ignore an already processed `gatewayTransactionId`).

### Environment Variables

Configure your `.env` or `.env.local`:

```dotenv
###> e2k/payment-bundle ###
PAYMENT_DEFAULT_GATEWAY=centralbill
PAYMENT_ROUTE_PREFIX=/payments/

# CentralBill Configuration
CENTRALBILL_APPLICATION_ID=your-application-id
CENTRALBILL_APPLICATION_SECRET=your-application-secret
CENTRALBILL_WEB_PAY_BASE_URL=https://your-centralbill-endpoint.com/payment/webpay
CENTRALBILL_CALLBACK_URL=https://your-domain.com/payments/callback/centralbill
CENTRALBILL_REDIRECT_URL=https://your-domain.com/payment/return

# Paystack Configuration
PAYSTACK_SECRET_KEY=xxxxxxxxxxxxxxxxxxxxxxxx
PAYSTACK_API_BASE_URL=https://api.paystack.co
PAYSTACK_CALLBACK_URL=https://your-domain.com/payments/callback/paystack
PAYSTACK_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxx
###< e2k/payment-bundle ###
```

---

## 4. Services & Dependency Injection

The bundle registers and aliases the following services in the Symfony container:

| Service Class | Service ID / Alias | Description |
|---|---|---|
| `E2K\PaymentBundle\Resolver\ActiveGatewayResolver` | `e2k_payment.resolver` | Resolves payment gateways by name or falls back to the default gateway. |
| `E2K\PaymentBundle\Registry\PaymentGatewayRegistry` | `e2k_payment.registry` | Lazy service locator holding all tagged gateways (autowirable, private). |
| `E2K\PaymentBundle\EventListener\PaymentExceptionListener` | `e2k_payment.exception_listener` | Normalizes exceptions into JSON API responses on configured routes. |

### Using `ActiveGatewayResolver`

```php
use E2K\PaymentBundle\Resolver\ActiveGatewayResolver;

// Resolve default gateway:
$defaultGateway = $resolver->resolve();

// Resolve specific gateway by name:
$paystack = $resolver->resolve('paystack');
$centralbill = $resolver->resolve('centralbill');
```

---

## 5. Data Transfer Objects (DTOs)

### `PaymentRequest`

Encapsulates data required to initiate a payment transaction.

```php
namespace E2K\PaymentBundle\DTO;

use E2K\PaymentBundle\Enum\Currency;

$request = new PaymentRequest(
    amount: '10000',                              // Amount in currency units (string)
    currency: Currency::XOF,                      // Currency enum instance
    invoiceId: 'INV-2024-001',                    // Internal reference / invoice ID
    customerId: 'user@example.com',              // Customer identifier or email
    invoiceIssuedAt: new \DateTimeImmutable(),   // Invoice creation date
    customerName: 'Jane Doe',                     // Optional: customer display name
    invoicedDueDate: new \DateTimeImmutable('+7 days'),  // Optional
    invoiceExpiresAt: new \DateTimeImmutable('+30 days'), // Optional
    returnUrl: 'https://example.com/checkout/return',     // Optional: override config redirect URL
    callbackUrl: 'https://example.com/payments/ipn',     // Optional: override config callback URL
    metadata: ['plan_id' => 'gold_yearly'],       // Optional: key-value metadata
    description: 'Yearly Subscription',           // Optional: transaction description
);
```

### `PaymentResponse`

Returned by `PaymentGatewayInterface::initiate()`.

```php
namespace E2K\PaymentBundle\DTO;

$response->status;               // PaymentStatus (typically PaymentStatus::INITIATED)
$response->invoiceId;            // string
$response->gatewayTransactionId; // ?string
$response->redirectUrl;          // ?string (URL to redirect the customer to)
$response->gatewayToken;          // ?string (e.g., access code)
$response->rawResponse;          // array (raw gateway response data)

if ($response->requiresRedirect()) {
    // Redirect customer to $response->redirectUrl
}
```

### `CallbackPayload`

Wraps the incoming HTTP request for webhook/IPN handling.

```php
namespace E2K\PaymentBundle\DTO;

use Symfony\Component\HttpFoundation\Request;

$payload = new CallbackPayload(
    httpRequest: $request,     // Symfony Request
    gatewayName: 'centralbill', // Optional gateway identifier
);

$body = $payload->getBody();           // Request raw content string
$headers = $payload->getHeaders();     // Associative array of request headers
$query = $payload->getQueryParams();   // Associative array of query parameters
```

### `CallbackResult`

Returned by `PaymentGatewayInterface::handle()`.

```php
namespace E2K\PaymentBundle\DTO;

$result->status;               // PaymentStatus
$result->invoiceId;            // string
$result->gatewayTransactionId; // string
$result->amount;               // ?int
$result->errorMessage;         // ?string
$result->rawPayload;           // array

if ($result->isSuccessful()) {
    // Transaction succeeded ($result->status === PaymentStatus::SUCCESS)
}
```

---

## 6. Enums Reference

### `Currency`

Located at `E2K\PaymentBundle\Enum\Currency`:

| Enum Case | Code | Decimals |
|---|---|---|
| `Currency::XOF` | `XOF` | `0` |
| `Currency::XAF` | `XAF` | `0` |
| `Currency::EUR` | `EUR` | `2` |
| `Currency::USD` | `USD` | `2` |
| `Currency::GHS` | `GHS` | `2` |
| `Currency::NGN` | `NGN` | `2` |

```php
$currency = Currency::XOF;
$decimals = $currency->decimals(); // 0
```

### `PaymentStatus`

Located at `E2K\PaymentBundle\Enum\PaymentStatus`:

| Enum Case | Value | Description |
|---|---|---|
| `PaymentStatus::PENDING` | `PENDING` | Awaiting payment completion |
| `PaymentStatus::INITIATED` | `INITIATED` | Transaction successfully initiated |
| `PaymentStatus::SUCCESS` | `SUCCESS` | Payment completed and validated |
| `PaymentStatus::FAILED` | `FAILED` | Payment failed or refused |
| `PaymentStatus::CANCELLED` | `CANCELLED` | Payment cancelled by customer or gateway |
| `PaymentStatus::REFUNDED` | `REFUNDED` | Payment refunded |
| `PaymentStatus::EXPIRED` | `EXPIRED` | Payment session/invoice expired |

Helper methods:
```php
$status = PaymentStatus::SUCCESS;
$status->isSuccessful(); // true
$status->isFinal();      // true (SUCCESS, FAILED, CANCELLED, REFUNDED, EXPIRED)
```

---

## 7. Usage Guide

### Initiating a Payment

```php
namespace App\Controller;

use E2K\PaymentBundle\DTO\PaymentRequest;
use E2K\PaymentBundle\Enum\Currency;
use E2K\PaymentBundle\Resolver\ActiveGatewayResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CheckoutController extends AbstractController
{
    public function __construct(
        private readonly ActiveGatewayResolver $gatewayResolver,
    ) {}

    #[Route('/checkout/{gateway}', name: 'app_checkout_initiate', methods: ['POST'])]
    public function initiate(string $gateway = 'centralbill'): Response
    {
        $gatewayInstance = $this->gatewayResolver->resolve($gateway);

        $request = new PaymentRequest(
            amount: '5000',
            currency: Currency::XOF,
            invoiceId: 'ORDER-2024-00123',
            customerId: 'customer@example.com',
            invoiceIssuedAt: new \DateTimeImmutable(),
            customerName: 'John Doe',
            description: 'Order #00123',
        );

        $response = $gatewayInstance->initiate($request);

        if ($response->requiresRedirect()) {
            return $this->redirect($response->redirectUrl);
        }

        return $this->json([
            'status' => $response->status->value,
            'invoice_id' => $response->invoiceId,
            'token' => $response->gatewayToken,
        ]);
    }
}
```

### Handling IPN / Webhooks

```php
namespace App\Controller;

use E2K\PaymentBundle\DTO\CallbackPayload;
use E2K\PaymentBundle\Enum\PaymentStatus;
use E2K\PaymentBundle\Resolver\ActiveGatewayResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WebhookController extends AbstractController
{
    public function __construct(
        private readonly ActiveGatewayResolver $gatewayResolver,
    ) {}

    #[Route('/payments/callback/{gateway}', name: 'app_payment_webhook', methods: ['POST', 'GET'])]
    public function callback(Request $request, string $gateway): Response
    {
        $gatewayInstance = $this->gatewayResolver->resolve($gateway);
        $payload = new CallbackPayload($request, $gateway);

        // 1. Verify webhook signature (throws InvalidCallbackException if invalid)
        $gatewayInstance->validateSignature($payload);

        // 2. Process and normalize payload
        $result = $gatewayInstance->handle($payload);

        if ($result->isSuccessful()) {
            // Update order status in database
            // dispatch(new PaymentReceivedEvent($result->invoiceId, $result->gatewayTransactionId));
        } elseif (PaymentStatus::REFUNDED === $result->status) {
            // Paystack "refund.processed": $result->invoiceId is the refunded transaction reference,
            // $result->gatewayTransactionId the refund reference, $result->amount the refunded amount
        } else {
            // Handle failed / cancelled payment
            // $reason = $result->errorMessage;
        }

        return new JsonResponse(['received' => true], Response::HTTP_OK);
    }
}
```

> **Ignored events**: `handle()` throws `IgnoredCallbackException` (a subclass of `InvalidCallbackException`) for authentic notifications that do not affect a payment status. On routes under `payment_route_prefix`, `PaymentExceptionListener` acknowledges them with HTTP `200` (`callback.ignored_event`) so that the provider stops retrying. Outside that prefix, catch it in your controller *before* `InvalidCallbackException`.
>
> **Paystack events**: `charge.success` → status from `data.status`; `refund.processed` → `PaymentStatus::REFUNDED`; every other event (`transfer.*`, `charge.dispute.*`, `subscription.*`, `refund.pending`, `refund.failed`, ...) is ignored.

### Querying Transaction Status

```php
$gateway = $this->gatewayResolver->resolve('paystack');
$status = $gateway->getTransactionStatus('paystack_reference_123');

if ($status->isSuccessful()) {
    // Payment verified
}
```

> `getTransactionStatus()` throws a `GatewayException` when the status cannot be determined (network failure, invalid API key, unknown reference, non-JSON response, ...). It never reports such technical failures as `PaymentStatus::FAILED`, so you can safely retry later instead of cancelling an order that may have been paid.

### CentralBill Polling (server-side API)

CentralBill's public documentation only describes the signed Web Pay link and the IPN. When `gateways.centralbill.api_base_url` is set, the bundle also uses CentralBill's REST processor (`{api_base_url}/processor/v1/rest`) so that a payment can be polled when its IPN never arrives:

1. `initiate()` first **pre-creates** the payment (`POST /payments`) with the very same signed payload as the Web Pay link. The endpoint is idempotent on `(applicationId, invoice.id, signature)`: replaying it returns the existing payment, and the hosted page reached afterwards finds it back instead of creating a second one. Since the signature covers the amount, the currency and the customer, re-initiating an invoice with another amount creates a **new** payment.
2. `PaymentResponse::$gatewayTransactionId` then holds the **CentralBill payment id**: store it with your order, it is what you poll. `redirectUrl` is the payment link CentralBill returns for that payment (`links[rel=pay]`) when present, otherwise the locally built Web Pay link.
3. **The pre-creation never blocks a payment**: if the API is unreachable, rate limited or answers something unusable, a warning is logged and `initiate()` returns the Web Pay link with `gatewayTransactionId = null` (no polling for that payment; the IPN still works).
4. `fetchPayment($paymentId)` (`E2K\PaymentBundle\PaymentLookupInterface`) reads the payment back (`GET /payments/{id}`) and returns a `CallbackResult`, the same DTO as the IPN. `getTransactionStatus($paymentId)` returns its status only.

**Status rules**: a payment is `SUCCESS` as soon as one of its transactions is `COMPLETED`; otherwise it is `PENDING` (a failed attempt does not fail the payment, the customer can retry until it expires; failures are reported by the IPN). `totalAmountAlreadyPaid` is deliberately not used: CentralBill states it is not the field to decide on. Technical failures throw a `GatewayException` (`isRateLimited()` is true on HTTP 429).

**Before settling an order from a lookup**, check that the result really belongs to it, exactly as for an IPN: `invoiceId`, `amount` (invoice total) and `rawPayload['invoice']['customerId']`.

```php
use E2K\PaymentBundle\Enum\PaymentStatus;
use E2K\PaymentBundle\Exception\GatewayException;
use E2K\PaymentBundle\PaymentLookupInterface;

// e.g. in a Messenger handler scheduled every few minutes for orders still unpaid after 5 minutes
$gateway = $this->gatewayResolver->resolve('centralbill');

if (!$gateway instanceof PaymentLookupInterface || null === $order->getCentralBillPaymentId()) {
    return; // nothing to poll: rely on the IPN
}

try {
    $result = $gateway->fetchPayment($order->getCentralBillPaymentId());
} catch (GatewayException $e) {
    if ($e->isRateLimited()) {
        // back off until the quota window rolls over (20 requests / 60 s observed)
    }

    return; // retry on the next run, never cancel the order on a technical failure
}

if (PaymentStatus::SUCCESS === $result->status
    && $result->invoiceId === $order->getNumber()
    && 0 === bccomp((string) $result->amount, $order->getTotal(), 2)
    && strtolower($result->rawPayload['invoice']['customerId'] ?? '') === strtolower($order->getEmail())
) {
    $this->orderPayment->settle($order, $result->gatewayTransactionId); // idempotent: the IPN may win the race
}
```

> **Rate limit**: CentralBill answers HTTP 429 beyond **20 requests per 60 seconds** (observed), shared by pre-creations and lookups. Keep polling batches small, and give priority to pre-creations (a customer is waiting). The bundle is stateless: scheduling (e.g. Symfony Scheduler / Messenger) and throttling (e.g. Symfony RateLimiter) belong to the application.
>
> **Not covered by CentralBill's public documentation**: the endpoints, response envelopes and the absence of authentication on `GET /payments/{id}` come from a production integration, not from published specifications. The bundle tolerates several response shapes; check the behaviour against the sandbox before relying on polling.

---

## 8. Adding a Custom Payment Gateway

To add a new payment provider (e.g. Stripe, Wave, PayPal):

### 1. Implement `PaymentGatewayInterface`

```php
namespace App\Payment\Gateway;

use E2K\PaymentBundle\DTO\CallbackPayload;
use E2K\PaymentBundle\DTO\CallbackResult;
use E2K\PaymentBundle\DTO\PaymentRequest;
use E2K\PaymentBundle\DTO\PaymentResponse;
use E2K\PaymentBundle\Enum\PaymentStatus;
use E2K\PaymentBundle\Exception\GatewayException;
use E2K\PaymentBundle\Exception\InvalidCallbackException;
use E2K\PaymentBundle\PaymentGatewayInterface;

final class CustomGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $apiKey,
    ) {}

    public static function getName(): string
    {
        return 'custom_gateway';
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    public function initiate(PaymentRequest $request): PaymentResponse
    {
        if (!$this->isConfigured()) {
            throw GatewayException::configurationError(self::getName(), 'API key is missing.');
        }

        // Call provider API ...

        return new PaymentResponse(
            status: PaymentStatus::INITIATED,
            invoiceId: $request->invoiceId,
            redirectUrl: 'https://payment-provider.com/pay/xyz',
        );
    }

    public function validateSignature(CallbackPayload $payload): void
    {
        $headers = $payload->getHeaders();
        // Verify signature or throw InvalidCallbackException:
        // throw InvalidCallbackException::invalidSignature();
    }

    public function handle(CallbackPayload $payload): CallbackResult
    {
        $data = json_decode($payload->getBody(), true);

        return new CallbackResult(
            status: PaymentStatus::SUCCESS,
            invoiceId: $data['reference'],
            gatewayTransactionId: $data['id'],
            amount: (int) $data['amount'],
            rawPayload: $data,
        );
    }

    public function getTransactionStatus(string $gatewayTransactionId): PaymentStatus
    {
        // Query status from provider API
        return PaymentStatus::SUCCESS;
    }
}
```

### 2. Autoconfiguration

Thanks to Symfony autoconfiguration, any class implementing `PaymentGatewayInterface` is automatically tagged with `e2k_payment.gateway` and made available to `PaymentGatewayRegistry` and `ActiveGatewayResolver`.

```php
// Now resolvable:
$gateway = $gatewayResolver->resolve('custom_gateway');
```

---

## 9. Exception Handling & Error Format

The `PaymentExceptionListener` catches all bundle exceptions when the request URI starts with `payment_route_prefix` (default: `/payments/`).

### Error JSON Response Format

```json
{
  "error": {
    "code": "gateway.declined",
    "message": "Payment declined: insufficient funds.",
    "details": {
      "exception": "E2K\\PaymentBundle\\Exception\\GatewayException",
      "file": "/path/to/Gateway.php",
      "line": 42
    }
  }
}
```

> **Note**: The `details` object is included only when `APP_DEBUG` is `true`. In production, 5xx server errors return translated generic safety messages to prevent information leakage.

### Exception Mapping Reference

| Exception Condition | HTTP Status | Error Code (`PaymentErrorCode`) |
|---|---|---|
| `GatewayException::configurationError` | `500` | `gateway.misconfigured` |
| `GatewayException::httpError` | `502` | `gateway.http_error` |
| `GatewayException::networkError` | `502` | `gateway.network_error` |
| `GatewayException::declined` | `422` | `gateway.declined` |
| `GatewayException::unexpectedResponse` | `502` | `gateway.unexpected_response` |
| Other `GatewayException` | `500` | `payment.internal_error` |
| `InvalidCallbackException::invalidSignature` | `400` | `callback.invalid_signature` |
| `InvalidCallbackException::missingSignature` | `400` | `callback.missing_signature` |
| `InvalidCallbackException::unsupportedAlgorithm` | `400` | `callback.unsupported_algorithm` |
| `InvalidCallbackException::malformedPayload` | `400` | `callback.malformed_payload` |
| `InvalidCallbackException::missingField` | `400` | `callback.missing_field` |
| `InvalidCallbackException::expiredRequest` | `400` | `callback.expired_request` |
| `InvalidCallbackException::replayDetected` | `200` | `callback.replay_detected` |
| `IgnoredCallbackException::unsupportedEvent` (logged as `info`) | `200` | `callback.ignored_event` |
| `GatewayNotFoundException::forName` (unknown gateway requested, e.g. from the URL; logged as `warning`) | `404` | `gateway.not_found` |
| `GatewayNotFoundException::noDefault` (configured `default_gateway` is not registered; logged as `critical`) | `500` | `gateway.not_found` |
| `GatewayNotFoundException::notConfigured` (logged as `critical`) | `500` | `gateway.not_configured` |

---

## 10. Translations & Localization

The bundle provides message translations in both English (`en`) and French (`fr`) under the `E2KPaymentBundle` translation domain.

Translation files (XLIFF, so that no extra component such as `symfony/yaml` is needed):
- `translations/E2KPaymentBundle.en.xlf`
- `translations/E2KPaymentBundle.fr.xlf`

Messages can be customized by overriding the translation keys in your application's `translations/` directory.

---

## 11. Testing & Quality

Run project quality and test scripts:

```bash
# Run PHPUnit test suite
composer test

# Run PHPStan static analysis
composer stan

# Run PHP-CS-Fixer code style check
composer cs

# Run all QA checks
composer qa
```
