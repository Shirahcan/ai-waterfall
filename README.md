# shirahcan/ai-waterfall

Thin client for the estate's [`ai-service`](https://github.com/Shirahcan/ai-service). Same
shape as `shirahcan/auth-service-helper` is to `auth-service`: the **service** owns the
state, this package owns the calling convention.

## ⚠ It holds no provider keys, and must never learn how to

A client that carried its own keys "as a fallback" would recreate the per-product
duplication the whole programme exists to end, and would do it invisibly — working fine
right up until two products were benching the same provider quota separately again.

If the service is unreachable, that is an outage to **report**, not to route around.

## Install

```bash
composer require shirahcan/ai-waterfall
```

```dotenv
AI_SERVICE_URL=http://127.0.0.1:8007
AI_SERVICE_TRUST_KEY=<issued by the service>
AI_SERVICE_TIMEOUT=60
```

The key is minted **by the service**, because the callee owns the key:

```bash
# on the ai-service box
php artisan ai:issue-key portify --label="portify prod"
```

An unset key throws at resolution, not at the first call — a deployment mistake is far
cheaper to find at boot than as a 401 during a client's question.

## Config is three values, deliberately

`base_url`, `trust_key`, `timeout`. **No provider names, no model ids, no rates.** The
moment a product's config names a model, that product has an opinion about routing and the
single source of truth has leaked back out of the service.

## Use

```php
$ai = app(AiWaterfallClient::class);

$r = $ai->generateJson('porter.classify', $system, $prompt);
$r->array();          // decoded payload
$r->provider;         // who actually answered
$r->fellThrough();    // did the waterfall fall past anything?

$ai->generateText('sites.write_pml', $system, $prompt)->text();

// A provider-shaped payload the caller built. This is how Porter keeps its
// conversational turn (Gemini `contents` is not OpenAI `messages`) without the
// service ever learning what a Porter turn is.
$ai->invokeRaw('porter.turn', $payload, requiredKeys: ['action']);
```

## Failures are typed, because the remedies differ

| Exception | Means | What to do |
|---|---|---|
| `AllProvidersFailedException` | every credential refused | degrade to the deterministic path; carries the attempt trail |
| `AiOverBudgetException` | hard spend cap reached | raise the cap or find the loop — takes effect next request |
| `NoCompliantCredentialException` | sensitive task, no cleared key | a human clears one; **never** retry |
| `AiUnavailableException` | service unreachable | treat exactly as "all providers failed" today |

⚠ `over_budget` is **not** an outage and must never be folded into it. Opposite remedies,
and a caller that conflates them sends an operator to the wrong place.

⚠ `NoCompliantCredentialException` is what protects a client's passport. Retrying or
falling back here would send identity documents or supplier invoices to a training tier.

## Testing

`FakeAiWaterfall` is part of the contract, not a convenience — three products depend on one
service, and if testing an AI path needed that service running, each suite would go flaky
for reasons unrelated to the code under test.

```php
$fake = (new FakeAiWaterfall())->queue(['action' => 'ask']);
$this->app->instance(AiWaterfallClient::class, $fake);
```

An exhausted queue **throws** rather than inventing a result: a fake that quietly returns
something empty lets a test pass while asserting nothing.
