<p align="center">
  <h1 align="center">⚡ Passwire</h1>
  <p align="center">
    <strong>Self-Hosted Fault-Tolerant AI Gateway & Multi-Account Key Pooler</strong>
  </p>
  <p align="center">
    <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License"></a>
    <a href="https://php.net"><img src="https://img.shields.io/badge/php-8.3%2B-777BB4.svg" alt="PHP Version"></a>
    <a href="https://laravel.com"><img src="https://img.shields.io/badge/laravel-11%2F12-FF2D20.svg" alt="Laravel Version"></a>
    <a href="#docker-deployment"><img src="https://img.shields.io/badge/docker-ready-2496ED.svg" alt="Docker Ready"></a>
  </p>
</p>

---

## 📖 Overview

**Passwire** is an enterprise-grade, self-hosted AI Gateway that sits between your applications and upstream LLM providers (Google Gemini, Anthropic Claude, OpenAI, and custom OpenAI-compatible endpoints).

It pools multiple accounts per provider, automatically cooldowns keys that hit rate limits (**HTTP 429**), fails over in milliseconds to backup keys, redacts sensitive personal data (PII) before it leaves your infrastructure, and compresses prompts to save token costs—all while exposing a single, drop-in **OpenAI-compatible `/v1/chat/completions` API**.

---

## ✨ Key Features

- 🔑 **Multi-Account Key Pooling & Auto-Failover**  
  Add multiple free or paid accounts per provider. When an account encounters a rate limit (`429`), quota failure (`401/402/403`), or server error (`5xx`), Passwire places that account in an automatic temporary cooldown and instantly retries with the next healthy account.

- ⚡ **Circuit Breakers**  
  Prevents cascade failures by tripping a circuit breaker after 5 consecutive account failures, protecting your application from hung connections.

- 🔀 **Virtual Model Combos & Smart Routing**  
  Create custom virtual models like `auto`, `auto/free`, or `auto/coding` with 4 intelligent routing strategies:
  - **Priority Fallback**: Linear pipeline (try Model A, fallback to Model B on rate limits or errors).
  - **Round-Robin**: Distribute load evenly across upstream models.
  - **Lowest Cost First**: Automatically sorts the pipeline by pricing rates, routing to the cheapest model first.
  - **Lowest Latency First**: Telemetry-driven routing that sorts by 24-hour rolling average response time.

- ⚡ **1-Click Popular Provider Presets**  
  Quickly connect top inference engines with one click: **Groq Cloud, DeepSeek, Ollama (Local LLM), OpenRouter, Together AI, Mistral AI, and Perplexity** with pre-configured endpoints and OpenAI protocol compatibility.

- 🔄 **On-the-Fly Protocol Translation**  
  Send standard OpenAI-format chat completion requests; Passwire's native adapters translate them seamlessly into Google Gemini REST or Anthropic Messages protocols and normalize the responses back.

- 🛡️ **Built-in Data Guardrails (PII Masking)**  
  Automatically scans and redacts sensitive data—including private keys, JWTs, API tokens, credit cards, SSNs, emails, phone numbers, and passwords—before the prompt is dispatched to any external model.

- 🗜️ **Token Compression**  
  Reduces token consumption by stripping conversational fillers ("could you please", "thank you in advance") and deduplicating message history without altering intent.

- 🚀 **Response Cache & Cost Analytics**  
  Serves identical queries directly from cache in under 10ms at $0 cost. Real-time cost calculators track token usage, money spent, and dollars saved.

- 📊 **Observability & Live Studio**  
  Includes a dark-mode admin console with real-time analytics, latency trends, account health matrix, interactive test playground, and Discord/Slack alert webhooks.

---

## 🚀 Quick Start (Local Setup)

### Prerequisites
- PHP 8.3 or higher
- Composer
- Node.js & NPM
- SQLite or MySQL

### 1. Clone & Install Dependencies
```bash
git clone https://github.com/masud5009/Passwire.git
cd Passwire

composer install
npm install && npm run build
```

### 2. One-Command Setup
Run the automated installer to set up `.env`, run migrations, seed providers, and configure the administrator:
```bash
php artisan passwire:install
```

> **Default Admin Credentials:**
> - **URL:** `http://localhost:8000/login`
> - **Email:** `admin@passwire.local`
> - **Password:** `password`
> - **Master Gateway Key:** `sk-pw-master-key-1234567890abcdef`

### 3. Start the Application
```bash
php artisan serve
```

Visit `http://127.0.0.1:8000` to access the Passwire Console.

---

## 🐳 Docker Deployment

Passwire includes complete Docker and Docker Compose support for 1-command deployment:

```bash
# Start container stack (Passwire + Nginx + SQLite persistence)
docker compose up -d

# Run database setup inside the container
docker compose exec app php artisan migrate --seed --force
```

Your gateway is now running at `http://localhost:8080`.

---

## 🔌 API Integration

Passwire acts as a drop-in replacement for any OpenAI-compatible client. Simply change the `base_url` to your Passwire instance and pass your gateway key.

### cURL
```bash
curl http://localhost:8000/v1/chat/completions \
  -H "Authorization: Bearer sk-pw-master-key-1234567890abcdef" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "auto",
    "messages": [
      {"role": "user", "content": "Explain quantum computing in one sentence."}
    ]
  }'
```

### Python (OpenAI SDK)
```python
from openai import OpenAI

client = OpenAI(
    base_url="http://localhost:8000/v1",
    api_key="sk-pw-master-key-1234567890abcdef"
)

response = client.chat.completions.create(
    model="auto",  # Or 'gemini-2.5-flash', 'claude-3-5-sonnet', etc.
    messages=[{"role": "user", "content": "Write a quick Python sort function."}]
)

print(response.choices[0].message.content)

# Embeddings API (for RAG & Vector Search)
embedding = client.embeddings.create(
    model="text-embedding-3-small", # or gemini/text-embedding-004
    input="Passwire AI Gateway simplifies model routing and failover."
)
print("Vector dimensions:", len(embedding.data[0].embedding))
```

### Cursor IDE & VS Code (Continue / Cline)
In your IDE settings:
- **OpenAI Base URL:** `http://localhost:8000/v1`
- **API Key:** `sk-pw-master-key-1234567890abcdef`
- **Model Name:** `auto` (or any configured combo/model)

---

## 🧪 Testing

Passwire is covered by a suite of automated unit and feature tests:

```bash
php artisan test
```

---

## 🤝 Contributing

Contributions, bug reports, and feature requests are welcome! Please check out [CONTRIBUTING.md](CONTRIBUTING.md) to get started.

---

## 📄 License

Passwire is open-sourced software licensed under the [MIT License](LICENSE).
