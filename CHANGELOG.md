# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
et ce projet respecte le [Versionnement Sémantique](https://semver.org/lang/fr/).

---

## [Unreleased]

### Added
- À venir

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

[Unreleased]: https://github.com/e2k/payment-bundle/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/e2k/payment-bundle/releases/tag/v1.0.0
