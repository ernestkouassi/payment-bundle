# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
et ce projet respecte le [Versionnement Sémantique](https://semver.org/lang/fr/).

---

## [Unreleased]

### Security
- CentralBill : les IPN dont l'en-tête `Date` signé s'écarte de plus de `callback_tolerance` secondes (300 par défaut) de l'horloge serveur sont rejetés (`callback.expired_request`), ce qui limite le rejeu d'une notification capturée.

### Fixed
- Paystack : les webhooks autres que `charge.success` ne sont plus convertis en `PaymentStatus::FAILED`. `refund.processed` donne `PaymentStatus::REFUNDED` (référence de la transaction lue dans `data.transaction_reference`, identifiant du remboursement dans `data.refund_reference`, qui peut être `null` et retombe alors sur la référence de la transaction ; repli sur `reference`/`id`) ; tous les autres événements lèvent `IgnoredCallbackException`, acquittée en 200 par le listener.
- Paystack : `getTransactionStatus()` lève une `GatewayException` en cas d'erreur HTTP (401, 404, 5xx…), de réponse non-JSON ou de passerelle non configurée, au lieu de renvoyer `PaymentStatus::FAILED`.
- Paystack : une réponse non-JSON (page d'erreur HTML d'un proxy, par exemple) lève une `GatewayException` au lieu d'une `JsonException` non gérée.
- CentralBill : `initiate()` utilise `invoiceIssuedAt`, `invoicedDueDate` et `invoiceExpiresAt` de `PaymentRequest` au lieu de dates calculées en dur.
- Le container compile sans `translator` ni `logger` ; un message explicite est levé si `symfony/http-client` manque alors que Paystack est activé.
- Les traductions sont livrées en XLIFF : elles ne nécessitent plus `symfony/yaml` (auparavant, le listener levait une exception dans une application sans ce composant).
- Une passerelle inconnue demandée par le client (par exemple `/payments/callback/xyz`) renvoie 404 et est loggée en `warning` au lieu de 500 et `critical`.
- CI : chemins PHPStan, job de couverture sur `master`, erreurs PHPStan niveau 8 et style de code.

### Added
- `IgnoredCallbackException`, `Reason::IGNORED_EVENT`, `PaymentErrorCode::CALLBACK_IGNORED` (`callback.ignored_event`) et la constante `PaystackStatus::EVENT_REFUND_PROCESSED`.
- Option `gateways.centralbill.callback_tolerance` (300 par défaut, 0 pour désactiver).
- Option `gateways.paystack.timeout` (10 secondes par défaut) ; `gateways.paystack.api_base_url` vaut désormais `https://api.paystack.co` par défaut.
- Alias autowirable `PaymentGatewayRegistry`.
- `GatewayNotFoundException::isServerMisconfiguration()` et `isNotConfigured()`.
- Dépendances déclarées : `symfony/event-dispatcher`, `symfony/service-contracts`, `symfony/http-client-contracts`, `symfony/translation-contracts` ; `symfony/http-client` et `symfony/translation` en `suggest`.
- Tests : boot d'un kernel FrameworkBundle, compilation du container, registre, `ApiErrorResponse`, cas d'erreur HTTP Paystack, fraîcheur des IPN CentralBill.

### Changed
- Paystack : `charge.failed` et les autres événements non gérés ne renvoient plus un `CallbackResult` en `FAILED` mais lèvent `IgnoredCallbackException`. Le code qui fait un `match` exhaustif sur `Reason` doit gérer le nouveau cas `IGNORED_EVENT`.
- `payment_route_prefix` ne peut plus être vide ; un préfixe vide au runtime (variable d'environnement) désactive le listener au lieu d'intercepter toutes les routes.
- `PaymentExceptionListener` est enregistré comme `kernel.event_subscriber` et reçoit `%kernel.debug%` au lieu du service `kernel`. Signature du constructeur : `(?LoggerInterface $logger, bool $debug = false, ?TranslatorInterface $translator = null, string $paymentRoutePrefix = '/payments/')`.
- `ActiveGatewayResolver::resolve()` sans argument lève `GatewayNotFoundException::noDefault()` si la passerelle par défaut n'est pas enregistrée.
- Paystack : authentification via l'option `auth_bearer` du client HTTP ; les métadonnées nulles ne sont plus envoyées.

### Removed
- Méthode privée inutilisée `PaymentExceptionListener::safeMessage()` et attribut sans effet `#[AutowireLocator]` sur `PaymentGatewayRegistry`.

---

## [1.0.0] - 2024-01-01

### Added
- Contrat `PaymentGatewayInterface` pour l'abstraction des passerelles
- Passerelle **CentralBill** avec initiation par redirection et IPN HMAC-SHA256
- `PaymentGatewayRegistry` avec ServiceLocator lazy via `#[TaggedLocator]`
- `ActiveGatewayResolver` — résolution par nom ou passerelle par défaut
- `PaymentExceptionListener` — réponses JSON normalisées sur `/payment/*`
- Traductions `E2KPaymentBundle` en français et anglais
- Support PHP 8.2, 8.3, 8.4
- Support Symfony 6.4 (LTS) et 7.x

[Unreleased]: https://github.com/ernestkouassi/payment-bundle/compare/1.1.5...HEAD
[1.0.0]: https://github.com/ernestkouassi/payment-bundle/releases/tag/1.0.0
