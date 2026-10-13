# E2K Payment Bundle

Symfony bundle for payment gateway abstraction.

## Sommaire

1. [Installation](#installation)
2. [Configuration](#configuration)
3. [Usage](#usage)
4. [Add a gateway](#add-a-gateway)
5. [Exception Handling](#exception-handling)
6. [Tests](#tests)

---

## Installation

```bash
composer require e2k/payment-bundle
```

Save the bundle if Flex doesn't do it automatically :

```php
// config/bundles.php
return [
    E2K\PaymentBundle\E2KPaymentBundle::class => ['all' => true],
];
```

---

## Configuration

```yaml
# config/packages/e2k_payment.yaml
e2k_payment:
  default_gateway: '%env(PAYMENT_DEFAULT_GATEWAY)%'
  payment_route_prefix: '%env(PAYMENT_ROUTE_PREFIX)%'
  gateways:
    centralbill:
      enabled: true
      application_id: '%env(CENTRALBILL_APPLICATION_ID)%'
      application_secret: '%env(CENTRALBILL_APPLICATION_SECRET)%'
      web_pay_base_url: '%env(CENTRALBILL_WEB_PAY_BASE_URL)%'
      callback_url: '%env(CENTRALBILL_CALLBACK_URL)%'
      redirect_url: '%env(CENTRALBILL_REDIRECT_URL)%'
    paystack:
      enabled: true
      secret_key: '%env(PAYSTACK_SECRET_KEY)%'
      callback_url: '%env(PAYSTACK_CALLBACK_URL)%'
```

```dotenv
# .env
PAYMENT_DEFAULT_GATEWAY=centralbill
CENTRALBILL_APPLICATION_ID=fbab3ccc-719e-11ed-93ad-02420a0003c1
CENTRALBILL_APPLICATION_SECRET=app!secret
CENTRALBILL_SANDBOX=true
CENTRALBILL_CALLBACK_URL=https://acme.net/payment/callback/centralbill
CENTRALBILL_REDIRECT_URL=https://acme.net/payment/return

PAYSTACK_SECRET_KEY=sk_live_xxxxxxxxxxxx
PAYSTACK_CALLBACK_URL=https://acme.net/payment/callback/paystack
```

---

## Use

### Initiate a payment

```php
use E2K\PaymentBundle\Resolver\ActiveGatewayResolver;
use E2K\PaymentBundle\DTO\PaymentRequest;
use E2K\PaymentBundle\Enum\Currency;

class PaymentController extends AbstractController
{
    public function __construct(
        private readonly ActiveGatewayResolver $gatewayResolver,
    ) {}

    #[Route('/payments/initiate', methods: ['POST'])]
    public function initiate(): Response
    {
        $gateway = $this->gatewayResolver->resolve(); // default gateway

        $response = $gateway->initiate(new PaymentRequest(
            amount:        5000,
            currency:      Currency::XOF,
            reference:     'ORDER-42',
            customerEmail: 'client@example.com',
        ));

        return $this->redirect($response->redirectUrl);
    }
}
```

### Process the IPN callback

```php
use E2K\PaymentBundle\DTO\CallbackPayload;

#[Route('/payments/callback/{gateway}', methods: ['GET', 'POST'])]
public function callback(Request $request, string $gateway): Response
{
    $gw = $this->gatewayResolver->resolve($gateway);
    $payload = new CallbackPayload($request, $gateway);

    $gw->validateSignature($payload);   // throws InvalidCallbackException if invalid
    $result = $gw->handle($payload);    // returns a normalized CallbackResult

    if ($result->isSuccessful()) {
        // Update the order, dispatch an event, etc.
    }

    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}
```

---

## Add a gateway

Implementing `PaymentGatewayInterface` is sufficient — no additional configuration is required:


```php
use E2K\PaymentBundle\PaymentGatewayInterface;

final class WaveGateway implements PaymentGatewayInterface
{
    public function getName(): string { return 'wave'; }
    public function isConfigured(): bool { return true; }
    // ...
}
```

The bundle automatically registers any class implementing `PaymentGatewayInterface`
in the registry via `registerForAutoconfiguration()`.

---

## Exception Handling

The `PaymentExceptionListener` intercepts all exceptions from the bundle
on the `/payments/*` routes and returns normalized JSON responses:

```json
{
  "error": {
    "code": "gateway.declined",
    "message": "Payment declined: insufficient funds."
  }
}
```

| Exception | HTTP | Code |
|---|---|---|
| `GatewayException::declined` | 422 | `gateway.declined` |
| `GatewayException::httpError` | 502 | `gateway.http_error` |
| `GatewayException::networkError` | 502 | `gateway.network_error` |
| `InvalidCallbackException::invalidSignature` | 400 | `callback.invalid_signature` |
| `InvalidCallbackException::replayDetected` | 200 | `callback.replay_detected` |
| `GatewayNotFoundException` | 500 | `gateway.not_found` |

---

## Tests

```bash
composer install
vendor/bin/phpunit
```
