# E2K Payment Bundle

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-blue)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207.x-black)](https://symfony.com)

A flexible Symfony bundle for payment gateway abstraction with built-in support for **CentralBill** and **Paystack**, featuring signature verification, standardized DTOs, and extensibility for custom payment gateways.

---

## Features

- **Unified Payment Interface**: Consistent API across multiple payment providers (`PaymentGatewayInterface`).
- **Built-in Gateways**:
  - **CentralBill** (Redirect payment flow, HMAC-SHA256 signature verification for IPN webhooks).
  - **Paystack** (REST API initialize & verification, HMAC-SHA512 webhook signature verification).
- **Extensible Architecture**: Autowired service locator (`PaymentGatewayRegistry`) and active resolver (`ActiveGatewayResolver`). Easily add custom gateways by implementing an interface.
- **Normalized Data Structures**: DTOs for payment requests (`PaymentRequest`), responses (`PaymentResponse`), callbacks (`CallbackPayload`), and results (`CallbackResult`).
- **Standardized Error Handling**: Built-in exception listener (`PaymentExceptionListener`) delivering consistent JSON API error responses with translation support.
- **Multilingual Support**: Built-in translation catalogs (`en`, `fr`) for error messages.

---

## Requirements

- **PHP**: `>= 8.2`
- **Symfony**: `^6.4` (LTS) or `^7.4` (LTS)
- **Symfony HTTP Client**: `symfony/http-client` (required for Paystack gateway)

---

## Installation

Install the bundle using Composer:

```bash
composer require e2k/payment-bundle
```

If you do not use Symfony Flex, enable the bundle manually in `config/bundles.php`:

```php
// config/bundles.php
return [
    // ...
    E2K\PaymentBundle\E2KPaymentBundle::class => ['all' => true],
];
```

---

## Configuration

Create or update the configuration file in `config/packages/e2k_payment.yaml`:

```yaml
# config/packages/e2k_payment.yaml
e2k_payment:
    default_gateway: '%env(PAYMENT_DEFAULT_GATEWAY)%'
    payment_route_prefix: '%env(PAYMENT_ROUTE_PREFIX)%' # Defaults to /payments/
    gateways:
        centralbill:
            enabled: true
            application_id: '%env(CENTRALBILL_APPLICATION_ID)%'
            application_secret: '%env(CENTRALBILL_APPLICATION_SECRET)%'
            web_pay_baseUrl: '%env(CENTRALBILL_WEB_PAY_BASE_URL)%'
            callback_url: '%env(CENTRALBILL_CALLBACK_URL)%'
            redirect_url: '%env(CENTRALBILL_REDIRECT_URL)%'
        paystack:
            enabled: true
            secret_key: '%env(PAYSTACK_SECRET_KEY)%'
            api_base_url: '%env(PAYSTACK_API_BASE_URL)%' # Defaults to https://api.paystack.co
            callback_url: '%env(PAYSTACK_CALLBACK_URL)%'
```

Define the environment variables in your `.env` or `.env.local`:

```dotenv
###> e2k/payment-bundle ###
PAYMENT_DEFAULT_GATEWAY=centralbill
PAYMENT_ROUTE_PREFIX=/payments/

# CentralBill
CENTRALBILL_APPLICATION_ID=your-centralbill-application-id
CENTRALBILL_APPLICATION_SECRET=your-centralbill-application-secret
CENTRALBILL_WEB_PAY_BASE_URL=https://your-centralbill-endpoint.com/payment/webpay
CENTRALBILL_CALLBACK_URL=https://your-domain.com/payments/callback/centralbill
CENTRALBILL_REDIRECT_URL=https://your-domain.com/payment/return

# Paystack
PAYSTACK_SECRET_KEY=xxxxxxxxxxxxxxxxxxxxxxxx
PAYSTACK_API_BASE_URL=https://api.paystack.co
PAYSTACK_CALLBACK_URL=https://your-domain.com/payments/callback/paystack
###< e2k/payment-bundle ###
```

---

## Quick Start

### 1. Initiate a Payment

Inject `ActiveGatewayResolver` into your controller or service:

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

    #[Route('/checkout', name: 'app_checkout', methods: ['POST'])]
    public function checkout(): Response
    {
        // Resolves default gateway or a specific one: $this->gatewayResolver->resolve('paystack');
        $gateway = $this->gatewayResolver->resolve();

        $request = new PaymentRequest(
            amount: '5000',
            currency: Currency::XOF,
            invoiceId: 'INV-2024-001',
            customerId: 'customer@example.com',
            invoiceIssuedAt: new \DateTimeImmutable(),
            customerName: 'John Doe',
            description: 'Order #001 payment',
        );

        $response = $gateway->initiate($request);

        if ($response->requiresRedirect()) {
            return $this->redirect($response->redirectUrl);
        }

        return $this->json($response);
    }
}
```

### 2. Handle IPN / Webhook Callbacks

```php
namespace App\Controller;

use E2K\PaymentBundle\DTO\CallbackPayload;
use E2K\PaymentBundle\Resolver\ActiveGatewayResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PaymentWebhookController extends AbstractController
{
    public function __construct(
        private readonly ActiveGatewayResolver $gatewayResolver,
    ) {}

    #[Route('/payments/callback/{gateway}', name: 'app_payment_callback', methods: ['POST', 'GET'])]
    public function webhook(Request $request, string $gateway): Response
    {
        $gatewayInstance = $this->gatewayResolver->resolve($gateway);
        $payload = new CallbackPayload(httpRequest: $request, gatewayName: $gateway);

        // 1. Validate signature (throws InvalidCallbackException if invalid/tampered)
        $gatewayInstance->validateSignature($payload);

        // 2. Parse and normalize callback data
        $result = $gatewayInstance->handle($payload);

        if ($result->isSuccessful()) {
            // Update order status, credit user wallet, dispatch domain events, etc.
            // $invoiceId = $result->invoiceId;
            // $transactionId = $result->gatewayTransactionId;
            // $amount = $result->amount;
        }

        return new JsonResponse(['status' => 'acknowledged'], Response::HTTP_OK);
    }
}
```

---

## Detailed Documentation

For full documentation including adding custom gateways, enum references, and exception handling details, see [docs/index.md](docs/index.md).

---

## Quality & Tests

Run test suites and static analysis:

```bash
# Run PHPUnit tests
composer test

# Run PHPStan static analysis
composer stan

# Run PHP CS Fixer
composer cs
```

---

## License

This bundle is released under the [MIT License](LICENSE).
