# E2K Payment Bundle

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-blue)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207.x-black)](https://symfony.com)

Symfony bundle for payment gateway abstraction.
Natively supports CentralBill and extends its functionality by implementing an interface.

## Installation

### Via Composer (with Symfony Flex)

```bash
composer require e2k/payment-bundle
```

### Sans Symfony Flex

```bash
composer require e2k/payment-bundle
```

Save the bundle manually:

```php
// config/bundles.php
return [
    E2K\PaymentBundle\E2KPaymentBundle::class => ['all' => true],
];
```

## Configuration

Create the configuration file:

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

Add the environment variables:

```dotenv
PAYMENT_DEFAULT_GATEWAY=centralbill
PAYMENT_ROUTE_PREFIX=/payments

CENTRALBILL_APPLICATION_ID=your-app-id
CENTRALBILL_APPLICATION_SECRET=your-secret
CENTRALBILL_WEB_PAY_BASE_URL=https://your-app.com/payment/webpay
CENTRALBILL_CALLBACK_URL=https://your-app.com/payment/callback/centralbill
CENTRALBILL_REDIRECT_URL=https://your-app.com/payment/return

PAYSTACK_SECRET_KEY=sk_live_xxxxxxxxxxxx
PAYSTACK_CALLBACK_URL=https://acme.net/payment/callback/paystack
```

## Documentation

See [docs/index.md](docs/index.md).

## Licence

[MIT](LICENSE)
