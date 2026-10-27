# Contributing to Passwire

Thank you for your interest in contributing to **Passwire**! As an open-source project, Passwire thrives on community contributions, bug fixes, performance improvements, and new provider integrations.

---

## 🛠️ Development Setup

1. **Fork the repository** on GitHub.
2. **Clone your fork locally**:
   ```bash
   git clone https://github.com/your-username/passwire.git
   cd passwire
   ```
3. **Install dependencies**:
   ```bash
   composer install
   npm install && npm run build
   ```
4. **Setup environment & database**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   touch database/database.sqlite
   php artisan migrate --seed
   ```
5. **Run test suite**:
   ```bash
   php artisan test
   ```

---

## 🔌 Adding a New Provider Adapter

To integrate a new upstream AI provider with native streaming or custom payload formatting:

1. Create a new adapter class in `app/Services/Gateway/Adapters/`:
   ```php
   namespace App\Services\Gateway\Adapters;

   class MyProviderAdapter implements ProviderAdapterInterface
   {
       public function buildUrl(string $baseUrl, string $model, bool $isStream, string $apiKey): string { ... }
       public function formatHeaders(string $apiKey, bool $isStream): array { ... }
       public function formatPayload(string $model, array $payload): array { ... }
       public function parseResponse(array $response, string $model): array { ... }
   }
   ```
2. Register the adapter in `app/Services/Gateway/Adapters/ProviderAdapterFactory.php`.
3. Write automated unit and feature tests in `tests/Feature/`.

---

## 🎨 Coding Standards

- **Code Style:** We follow the standard Laravel code style via Laravel Pint:
  ```bash
  ./vendor/bin/pint
  ```
- **Tests:** Always ensure existing tests pass and write new tests covering your changes:
  ```bash
  php artisan test
  ```

---

## 📝 Pull Request Checklist

Before submitting a Pull Request:
- [ ] Code follows PSR-12 and passes `./vendor/bin/pint --test`.
- [ ] All tests pass via `php artisan test`.
- [ ] Sensitive information, personal API keys, and local SQLite/SQL dump files are excluded.
- [ ] Clear, descriptive PR title and summary explaining what the change accomplishes.

Thank you for helping make Passwire the best self-hosted AI gateway!
