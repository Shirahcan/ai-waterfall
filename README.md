# shirahcan/ai-client

The estate's one door to [`ai-service`](https://github.com/Shirahcan/ai-service): generation,
embeddings, streaming, and web research. Same relationship `shirahcan/auth-service-helper`
has to `auth-service`, and `shirahcan/calendar-client` has to `calendar-service` - the
**service** owns the state, this package owns the calling convention.

> **Renamed from `shirahcan/ai-waterfall` at 1.0.** "Waterfall" named one internal mechanism
> of the service, and the package now also carries research and (next) the assistant harness.
> See **Migrating** below; nothing breaks in the meantime.

## ⚠ It holds no provider keys, and must never learn how to

A client that carried its own keys "as a fallback" would recreate the per-product
duplication the whole programme exists to end, and would do it invisibly - working fine
right up until two products were benching the same provider quota separately again.

That is not hypothetical. Measured on production 2026-10-12: **Studendly never integrated
and still holds five provider keys in its own `.env`**, so its 429s teach nobody else
anything and its spend appears in no ledger. And **Portify kept 11 live API keys in its own
database** behind an admin console that no longer affects what answers a user's question.

If the service is unreachable, that is an outage to **report**, not to route around.

## Install

```bash
composer require shirahcan/ai-client
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

An unset key throws at resolution, not at the first call. ⚠ That guard is load-bearing and
it is not enough on its own: **finance-suite ran for weeks on production with no key**, and
because its callers catch broadly, every AI feature it has degraded silently rather than
anybody being told. Check `isReachable()` in your health endpoint.

## Config is three values, deliberately

`base_url`, `trust_key`, `timeout`, in `config/ai-client.php`. **No provider names, no model
ids, no rates.** The moment a product's config names a model, that product has an opinion
about routing and the single source of truth has leaked back out of the service.

⚠ The file is `ai-client.php`, **not** `ai.php`: Portify and MployNow both already have a
`config/ai.php` of their own, and publishing over one would silently replace a product's own
AI config.

## Use

```php
$ai = app(AiClient::class);

$r = $ai->generateJson('porter.classify', $system, $prompt);
$r->array();          // decoded payload
$r->provider;         // who actually answered
$r->fellThrough();    // did the waterfall fall past anything?

$ai->generateText('sites.write_pml', $system, $prompt)->text();

// A provider-NEUTRAL conversation each adapter projects. This is how Porter keeps its
// conversational turn without the service ever learning what a Porter turn is.
$ai->generateStructured('porter.turn', $system, $messages, $schema, requiredKeys: ['action']);

$ai->embed('knowledge.embed.query', $text)->vector;
```

### Research

```php
$out = $ai->research()->search('research.search', 'LMIA advertising window',
    includeDomains: ['canada.ca'], depth: 'advanced');

$page = $ai->research()->fetch($out['results'][0]['url']);   // null if not usable
$page['text'];   // readable text, never HTML
```

⚠ **It searches and fetches; it does not research.** Grounding, synthesis, citation
validation and "is this source good enough to show a client" stay in your product, because
they are judgements about your domain: a regulated immigration answer and a supplier-credit
check have opposite standards for a good source.

⚠ **`fetch()` returns `null` for an unsafe, unreachable, 404 or empty page, and deliberately
does not say which.** A caller that could tell them apart could use the endpoint to map the
estate's internal network from outside. The reason is in the service's log.

⚠ **A search key is a credential**, with `kind = 'search'` on the service, invisible to the
generation walk. Keys live in the vault, not in your `.env`, and the bench is shared - so the
first exhausted search key protects every product.

### A voice, and whose question it was

```php
$ai->withPersona('porter', 'consultant')     // the stored voice, and for whom
   ->withCorrelation(['_key' => 'case:9e3f', 'user_id' => $id])
   ->generateJson('chat.answer', $system, $prompt);

$ai->personas()->publish('porter', 'Porter', '<the writing rules>', 'consultant');
$ai->personas()->all();                      // every version, newest first
$ai->personas()->rollBackTo($id);
```

⚠ **The caller NAMES the voice; the service OWNS the text.** The product knows which
assistant is talking and to which role; the service holds the words so the person who
owns them can edit them instead of waiting for a deploy.

⚠ **Set the audience wherever the voice genuinely differs by role.** Role-blind persona
is the recurring defect: a practising consultant was told *"I am software, not a person,
and not your consultant"* - correct for a client, wrong for the professional who IS the
consultant.

⚠ **Do not set a persona on an extraction or a classification.** Those want a
machine-readable answer; house style there buys nothing and costs tokens on every call.

⚠ **The correlation bag is IDENTIFIERS, not content.** The service caps it at ten keys
of eighty characters and REFUSES anything longer rather than truncating, because a
silently shortened identifier is a wrong identifier. It exists because the usage table
could not say whose question a row was, which is why Portify kept a second ledger with
its own cost calculator and its own copy of the rate table.

⚠ **`withBudget`, `withCorrelation` and `withPersona` all CLONE.** The client is a
singleton: mutating in place would put one user's correlation, or one audience's voice,
into the next request handled by the same worker.

## Failures are typed, because the remedies differ

| Exception | Means | What to do |
|---|---|---|
| `AllProvidersFailedException` | every credential refused | degrade to the deterministic path; carries the attempt trail |
| `AiOverBudgetException` | hard spend cap reached | raise the cap or find the loop - takes effect next request |
| `NoCompliantCredentialException` | sensitive task, no cleared key | a human clears one; **never** retry |
| `AiUnavailableException` | service unreachable | treat exactly as "all providers failed" today |

⚠ `over_budget` is **not** an outage and must never be folded into it. Opposite remedies,
and a caller that conflates them sends an operator to the wrong place.

⚠ `NoCompliantCredentialException` is what protects a client's passport. Retrying or
falling back here would send identity documents or supplier invoices to a training tier.

## Testing

`FakeAiClient` is part of the contract, not a convenience - three products depend on one
service, and if testing an AI path needed that service running, each suite would go flaky
for reasons unrelated to the code under test.

```php
$fake = (new FakeAiClient())
    ->queue(['action' => 'ask'])
    ->queueSearch([['url' => 'https://www.canada.ca/x', 'title' => 'x']])
    ->queuePage('https://www.canada.ca/x', ['url' => '...', 'title' => 'x', 'text' => '...', 'bytes' => 9])
    ->queueUsageReport(['totals' => ['calls' => 42], 'by_outcome' => ['ok' => 40, 'failed' => 2]])
    ->queueModelStatus([['provider' => 'gemini', 'model' => 'gemini-2.5-flash']]);

$this->app->instance(AiClient::class, $fake);
```

⚠ **A fake must cover the WHOLE surface, and this one did not.** Until 1.0,
`usageReport()`, `myUsageReport()` and `modelStatus()` were not overridden, so they
inherited the real client and made **live HTTP calls from inside a test**. Portify's
`ConsoleUsageTest` asserted its admin console reported 3 AI calls and was handed **2,398**
by a reachable ai-service; it had been testing the network, not the controller, ever since
that console moved off the local ledger. A fake that silently falls through to the real
transport is worse than no fake, because it makes a networked test look isolated.

⚠ **An exhausted queue THROWS** rather than returning something empty, and an unregistered
URL fetches to `null`. A fake that invents a plausible answer lets a test pass while
asserting nothing.

## Migrating from `shirahcan/ai-waterfall`

Nothing breaks on the day you upgrade. `src/compat.php` aliases every public class name, so
`Shirahcan\AiWaterfall\AiWaterfallClient`, the four exceptions and the value objects all
keep working.

```jsonc
// composer.json - the repositories URL does NOT change
"require": { "shirahcan/ai-client": "^1.0" }
```

Then, at your own pace: `Shirahcan\AiWaterfall\` -> `Shirahcan\AiClient\`,
`AiWaterfallClient` -> `AiClient`, `FakeAiWaterfall` -> `FakeAiClient`,
`config('ai-waterfall.*')` -> `config('ai-client.*')`. The env var names are unchanged.

⚠ **`class_alias` is an alias, not a subclass, and that distinction is the point.** The old
name is the SAME class, so a `catch (Shirahcan\AiWaterfall\Exceptions\AiUnavailableException)`
still catches what the new code throws. `class Old extends New` would NOT: the thrown object
would be the parent, the old catch would never match, and every product's degrade path would
silently stop running at exactly the moment it was needed.

⚠ **The container needs the old name too, and the alias alone does not give it.**
`app(AiWaterfallClient::class)` passes the container the literal string
`Shirahcan\AiWaterfall\AiWaterfallClient`, because `::class` is compiled from the source text
and never consults an alias. `AiClientServiceProvider` binds that string explicitly; without
it the container would fall back to reflection and construct a client with no base URL, no
trust key and no timeout.

⚠ **1.0 is for the caret, not for tidiness.** On a 0.x package `^0.4` **excludes** `0.5.0`,
so a release reached nobody until four `composer.json` files were hand-edited. That is
exactly how Portify ended up on v0.4.5, MployNow on v0.4.2 and finance-suite on v0.3.0 at
the same time, on one box, with MployNow unable to stream or embed and nothing saying so.
