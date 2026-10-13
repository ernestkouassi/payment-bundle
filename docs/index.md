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
- **HTTP Client**: `symfony/http-client` (required when using the Paystack gateway)

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

    # Route prefix intercepted by PaymentExceptionListener (default: /payments/)
    payment_route_prefix: '%env(PAYMENT_ROUTE_PREFIX)%'

    gateways:
        centralbill:
            enabled: true
            application_id: '%env(CENTRALBILL_APPLICATION_ID)%'
            application_secret: '%env(CENTRALBILL_APPLICATION_SECRET)%'
            web_pay_baseUrl: '%env(CENTRALBILL_WEB_PAY_BASE_URL)%'
            callback_url: '%env(CENTRALBILL_CALLBACK_URL)%'       # Optional
            redirect_url: '%env(CENTRALBILL_REDIRECT_URL)%'       # Optional

        paystack:
            enabled: true
            secret_key: '%env(PAYSTACK_SECRET_KEY)%'
            api_base_url: '%env(PAYSTACK_API_BASE_URL)%'          # Default: https://api.paystack.co
            callback_url: '%env(PAYSTACK_CALLBACK_URL)%'         # Optional
```

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
###< e2k/payment-bundle ###
```

---

## 4. Services & Dependency Injection

The bundle registers and aliases the following services in the Symfony container:

| Service Class | Service ID / Alias | Description |
|---|---|---|
| `E2K\PaymentBundle\Resolver\ActiveGatewayResolver` | `e2k_payment.resolver` | Resolves payment gateways by name or falls back to the default gateway. |
| `E2K\PaymentBundle\Registry\PaymentGatewayRegistry` | `e2k_payment.registry` | Service locator holding all tagged gateways. |
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
        } else {
            // Handle failed / cancelled payment
            // $reason = $result->errorMessage;
        }

        return new JsonResponse(['received' => true], Response::HTTP_OK);
    }
}
```

### Querying Transaction Status

```php
$gateway = $this->gatewayResolver->resolve('paystack');
$status = $gateway->getTransactionStatus('paystack_reference_123');

if ($status->isSuccessful()) {
    // Payment verified
}
```

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
| `GatewayNotFoundException::forName` | `500` | `gateway.not_found` |
| `GatewayNotFoundException::notConfigured` | `500` | `gateway.not_configured` |

---

## 10. Translations & Localization

The bundle provides message translations in both English (`en`) and French (`fr`) under the `E2KPaymentBundle` translation domain.

Translation files:
- `translations/E2KPaymentBundle.en.yaml`
- `translations/E2KPaymentBundle.fr.yaml`

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
