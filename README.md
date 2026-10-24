# shirahcan/ai-client

The estate's one door to [`ai-service`](https://github.com/Shirahcan/ai-service): generation,
embeddings, streaming, and web research. Same relationship `shirahcan/auth-service-helper`
has to `auth-service`, and `shirahcan/calendar-client` has to `calendar-service` - the
**service** owns the state, this package owns the calling convention.

> **Renamed from `shirahcan/ai-waterfall` at 1.0.** "Waterfall" named one internal mechanism
> of the service, and the package now also carries research and (next) the assistant harness.
> The old names were removed in 1.17.0; see **Migrating** below.

## ⚠ It holds no provider keys, and must never learn how to

A client that carried its own keys "as a fallback" would recreate the per-product
duplication the whole programme exists to end, and would do it invisibly - working fine
right up until two products were benching the same provider quota separately again.

That is not hypothetical. An audit of this estate on 2026-10-12 found a product that had
never integrated and still kept its own provider keys, so its 429s taught nobody else
anything and its spend appeared in no ledger - and another holding live keys in its own
database behind an admin console that no longer affected what answers a user's question.

⚠ **Which products, and how many keys, is deliberately not written here.** This
repository is public so the deploy boxes can install it; naming a product that currently
has provider keys outside the vault would publish a map of where to look for them. The
detail lives in the private audit (`portify/docs/plans/ai-infra-2026-10-12/01_AUDIT.md`),
and the point survives without it: a client that carries keys is a client nobody can audit.

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

## An assistant: `$ai->assistant()`

```php
$catalog = new ToolCatalog([new AtlasQueryTool, new DraftEmailTool]);

$answer = $ai->assistant('porter', 'consultant', 'You help a Canadian immigration consultant.')
    ->withTools($catalog)
    ->as($user)                      // every authorize() sees this
    ->withKnowledge('consultant')    // ground the turn in the index
    ->ask('what is outstanding on the Garcia file?');

$answer->text;          $answer->toolsUsed();      // ['atlas_query']
$answer->exhausted;     $answer->refusedTools();

foreach ($ai->assistant('porter', 'client')->withTools($catalog)->as($user)->stream($question) as $event) {
    if ($event->type === AiStreamEvent::TOKEN)   { echo $event->text(); }
    if ($event->type === AiStreamEvent::DISCARD) { /* un-paint everything so far */ }
}
```

A tool is four methods:

```php
class AtlasQueryTool implements ToolContract
{
    public function name(): string { return 'atlas_query'; }
    public function description(): string { return 'Read the asker's own cases, clients and payments.'; }
    public function schema(): array { return ['type' => 'object', 'properties' => [...]]; }
    public function authorize(mixed $viewer, array $args): bool { return $viewer->canSee($args['case_id'] ?? null); }
    public function run(array $args): mixed { return Atlas::query($args); }
}
```

⚠ **The loop and the tools run in YOUR process, never in ai-service.** The service decides
what to do next; your product decides whether this viewer may do it, and then does it. The
alternative - the service driving the loop and calling back - is the tidier diagram and the
wrong trade: it would give the box that holds every AI provider key in the estate the
ability to act **as a user** inside every product, and would give your permission model two
enforcers.

⚠ **`authorize()` runs on EVERY step, with the actual arguments.** A loop can revisit a
tool asking about a different case, and a check made once against the first arguments is
not a check. A refused tool is never executed, and the model is told so it can answer
without it - asserted, including the case where it asks twice with different arguments.

⚠ **Every tool is DESCRIBED to the model, including ones the viewer cannot use.** A model
that cannot see a tool cannot say "you would need X for that". Describing grants nothing;
execution is where the refusal lives.

⚠ **Read `exhausted`.** True means the loop ran out of steps or time, and `text` is then
EMPTY on purpose - the model never decided to say anything, and presenting its last
mid-thought as an answer is how an assistant states half a fact confidently. Run your
deterministic path instead.

⚠ **What a streamed turn costs**, because the three paths differ:

| path | model calls | streamed by the provider |
|---|---|---|
| no tools registered | 1 | yes |
| tools, none needed | 1 | no - the planner's text is replayed as tokens |
| tools, some used | 2 + steps | yes, written from the tool results |

The middle row replays rather than paying twice: the planner already wrote the answer and
there is no new evidence a second call could write from. A replayed stream has **no stream
id**, because there is nothing to cancel.

⚠ **The persona is NAMED here and OWNED by the service.** Pass the key and the audience;
the words come from the persona registry at the service's own choke point, so changing how
an assistant sounds is a row, not a deploy.

## Knowledge: `$ai->knowledge()`

```php
foreach ($entries->chunk(50) as $batch) {
    $ai->knowledge()->sync($batch->map(fn ($e) => [
        'slug'     => $e->slug,
        'text'     => $e->embeddableText(),
        'audience' => $e->audience,          // null = every audience
    ])->all());
}

$ai->knowledge()->retire($everySlugYouStillPublish);   // once, at the end

$hits = $ai->knowledge()->retrieve('how do I reset my password', 'client');
$hits['matches'][0]['slug'];
$hits['index_stale'];                                   // read this
```

⚠ **The AUTHORITY stays in your product; only the INDEX lives here.** What is true -
and whether an entry still names a route or a tool that exists - belongs where your own
tests can fail on it. A corpus whose staleness nobody can test is how a stale government
fee becomes a confident lie, and one cost $1,000 a position. If the only copy of an entry
is in the service, nothing can test it any more.

⚠ **Vectors live beside the model that made them, and that is the whole reason this
endpoint exists.** A query embedded by one model and scored against chunks built by
another returns confident nonsense with **no error**. Every chunk records its model,
nothing is scored across models, and a reply that could score nothing says
`index_stale: true` instead of returning an innocent empty list.

⚠ **`audience` is a HARD FILTER, and `null` means the audience-blind entries ONLY.** Not
everything. The recurring defect: a practising consultant asked how to set up instalments
for a client and was told to "ask your consultant" - she IS the consultant - because one
entry served every audience.

⚠ **Sync often; it is cheap.** The service skips by checksum, so re-sending an unchanged
corpus costs one request and no provider calls, and an identical body of text is embedded
once and reused across an entry's audiences. What it will not skip is text whose embedding
model has changed.

⚠ **`retire()` takes what should REMAIN, not what to delete**, because a delete-list
silently keeps whatever a crashed sync forgot to mention. An empty list is refused unless
you confirm it: "this product publishes nothing" and "the sync crashed before collecting
the slugs" are the same request on the wire, and one of them wipes a working index.

⚠ **`retrieve()` THROWS when the query cannot be embedded.** Catch it and fall back to
your own lexical path - semantic retrieval is an enhancement, and an empty result would be
indistinguishable from "nothing matched".

## Conversations: `$ai->conversations()`

```php
$thread = $ai->conversations()->start(
    ownerRef: (string) $user->id,          // YOUR id for them, never a name or an email
    audience: 'consultant',
    subjectRef: (string) $case->id,        // YOUR id for what it is about
    clientRef: (string) $row->id,          // YOUR id for the thread - makes a retry safe
);

$ai->conversations()->append($thread->id, ConversationTurn::ROLE_USER, $question, clientRef: $messageId);
$ai->conversations()->append($thread->id, ConversationTurn::ROLE_ASSISTANT, $answer->text,
    structured: $answer->fields, refused: $answer->declined);

$page = $ai->conversations()->read($thread->id);            // newest page first
$threads = $ai->conversations()->forOwner((string) $user->id);   // summaries only

$ai->conversations()->eraseForOwner((string) $user->id);    // "forget me"
$ai->conversations()->eraseForSubject((string) $case->id);  // "forget this matter"
```

⚠ **The service holds the transcript and CANNOT identify the person.** `ownerRef` is your
own id and nothing in the service can map it to a human. What is centralised is the text,
not the identity - so **authorisation stays yours**: the trust key proves which PRODUCT is
calling, never which person. Decide who may read a thread exactly as you decide who may
read a case, before you call.

⚠ **This is a STORE, not a cache**, and that is the opposite of `knowledge()`. Here the
service IS the authority. It is the estate's own pattern, proven in calendar-service and
video-service, and `Meeting.php` says so in its own docblock: the product keeps its own
foreign keys and the thread's id, and hydrates the rest.

⚠ **Pass a `clientRef` on every write.** A loopback call can still time out AFTER the
service has committed; your retry then writes a second copy, and nothing raises an error
because both writes succeeded. The person sees their own question twice in their own
history, or a blank thread nobody started appears in their list.

⚠ **A turn needs prose OR structure, and `structured` stays structured.** A `ReeveAnswer`
holds six fields whose separation is a safety control - a ledger figure and a regulation
must never share a paragraph - so do not flatten them to display them.

⚠ **A refusal is a turn, not an error.** It arrives as a 200 and belongs in the
transcript. In clear, it is the most useful aggregate the service holds.

⚠ **Pass `grounded` and `provenanceClass` on every assistant reply.** They are in clear, so
they are the only part of a reply that can still be GROUPED once the bodies are ciphertext -
and `stats()` reports them per audience, which is where a self-reflect backlog comes from.
Leave them `null` on a person's question and on a reply that genuinely says nothing about
where it came from: **a reply that said nothing is not an ungrounded reply**, and folding
those in makes an assistant that reports nothing look like one that grounds nothing. A
`false` is a value and is sent; only `null` means "nothing to say".

⚠ **Retention is per (product, audience), and most specific wins.** Set it from the admin
surface: `PUT /v1/admin/retention` with a `product` (and optionally an `audience`) and
`days`. Portify needs it because it owes three different promises - 30 days to a visitor on
its own site, 14 to a visitor on a consultant's site, and for ever to anybody signed in - and
a single per-product number would either delete a client's history or keep an anonymous
visitor's for a year.

⚠ **Pages walk BACKWARDS with `nextBefore`, and you never build a cursor.** An
unrecognised one is refused rather than quietly restarting, because a silent restart is how
a paging loop runs for ever while every response looks valid.

⚠ **A thread WILL be gone one day.** Retention erases on a schedule and an erasure erases
on request, so `byIds()` can return fewer threads than you asked for. That absence is the
answer, not a failure - render it. `FakeAiConversations::pretendRetentionErased()` exists
so you can reach that branch in a test.

### The held pattern: `HeldInAiService`

```php
class AssistantConversation extends Model
{
    use HeldInAiService;

    protected static function aiConversationSubject(): string
    {
        return PorterThreadSubject::class;   // implements HeldConversationSubject
    }
}
```

Loading a collection hydrates `title`, `turn_count` and `last_turn_at` from the service in
**one** call, so they really are the model's attributes and `isDirty()`, `getOriginal()` and
`wasChanged()` still mean what they always meant. Held columns are never written to your
table, and a dirty held attribute is sent to the service FIRST - a refusal means nothing
changed.

⚠ **Only the SUMMARY SCALARS are held, never the turns**, and this is the one way it
differs from `HeldInCalendarService`. A booking's held facts are a few scalars on one row;
a conversation's are a LIST that grows, so hydrating turns per row would be N requests of M
turns. Read turns with `read()`, for the one thread on screen.

⚠ **Writing a DERIVED held attribute throws.** `turn_count` and `last_turn_at` are counted
from the turns by the service. Dropping such a write quietly is what `Meeting` did over its
booking: the change went nowhere and nothing said so.

⚠ **`cursor()` and raw `DB::table()` reads BYPASS hydration**, because no collection is
built. When you sweep a product after a cutover, that is what you are looking for.

## The vault: `$ai->admin()`

```php
$vault = $ai->admin();                       // needs AI_SERVICE_ADMIN_KEY

$vault->credentials();                       // configuration, never a key
$vault->saveCredential([...]);               // same (provider,label) ROTATES
$vault->updateCredential($id, ['enabled' => false]);
$vault->clearSensitive($id, 'owner@shirah.co');

$vault->budgets();                           // every product, armed or not
$vault->setBudget('portify', 20_000_000, 50_000_000, 'owner@shirah.co');

$vault->killSwitch();
$vault->stop('global', 'owner@shirah.co', 'a key leaked');

$vault->trustKeys();
$vault->issueTrustKey('finance', 'owner@shirah.co');   // key_shown_once
```

⚠ **It uses a SEPARATE key, and that separation is the safety model.**
`AI_SERVICE_ADMIN_KEY`, minted with `php artisan ai:issue-key <product> --admin`, is
not `AI_SERVICE_TRUST_KEY`. The trust key travels on every generation call this product
makes, so it is the one most likely to leak - one already did, into a chat transcript,
on 2026-10-12. Behind the trust key these methods would make four plaintext `.env` lines
on one box into four estate-wide root keys. The package refuses to boot if the two
values are the same.

⚠ **A key goes in and never comes back out.** No method here returns an `api_key`, in
any form. `key_fingerprint` is 8 hex characters of a one-way hash - it answers "did my
rotation land" and shares none of the secret, which a `sk-...4f2a` mask does not.

⚠ **This surface cannot mint an ADMIN key.** Everything else a leaked admin key could
do is undone by rotating it; a second admin key it minted for itself would survive the
rotation and nothing in a key listing would look wrong about it. Admin keys come from
the artisan command, over SSH, only.

⚠ **No admin key THROWS, and not as an AI failure.**
`AdminNotConfiguredException` is a `RuntimeException`, deliberately: every product
catches the AI exceptions to degrade, and degrading here means a console that shows
empty screens while an operator concludes the vault is empty.

⚠ **Read `armed` on a budget and both flags on a kill switch.** `ai_budget_limits` was
empty on production, so the hard cap could never fire for anybody - and "no cap" is
indistinguishable from "a generous cap" from a product's side. Likewise `stopped` is a
product's own switch while `refusing` is what the generation path gets, which is also
true under a global stop; collapse them and resuming one product appears to do nothing.

## Failures are typed, because the remedies differ

| Exception | Means | What to do |
|---|---|---|
| `AllProvidersFailedException` | every credential refused | degrade to the deterministic path; carries the attempt trail |
| `AiOverBudgetException` | hard spend cap reached | raise the cap or find the loop - takes effect next request |
| `NoCompliantCredentialException` | sensitive task, no cleared key | a human clears one; **never** retry |
| `AiUnavailableException` | service unreachable | treat exactly as "all providers failed" today |
| `AiRequestRefusedException` | the service answered and REFUSED the request | read `->getMessage()`: it says what to fix. `->status`, `->errorCode` |

⚠ `AiRequestRefusedException` **subclasses `AiUnavailableException` on purpose**, so every
existing degrade path still catches it unchanged. Before 1.3 a refused write arrived as
"ai-service returned an error (HTTP 422)", which sent a reader to check whether the service
was up - it was - instead of at the sentence saying what to fix. Catch it FIRST if you show
operators what the service said.

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

⚠ **`FakeAiAdmin` models the RULES, not just the shape.** It rotates on a repeated
(provider, label), refuses to grant clearance through a plain update, fingerprints
instead of returning a key, lists products with no cap, and keeps a global stop distinct
from a product stop. `(new FakeAiClient())->withoutAdminKey()` is how a console's "you
are not authorised" branch gets exercised - the deployment mistake most easily made is
shipping the console to a product whose env never got the key.

⚠ **An exhausted queue THROWS** rather than returning something empty, and an unregistered
URL fetches to `null`. A fake that invents a plausible answer lets a test pass while
asserting nothing.

## Migrating from `shirahcan/ai-waterfall`

The old names (`Shirahcan\AiWaterfall\*`, `AiWaterfallClient`, `FakeAiWaterfall`,
`config('ai-waterfall.*')`) are GONE as of 1.17.0. The `compat.php` aliases were removed once
a sweep of every consuming product found zero uses. If a
product still has one, rename it: `Shirahcan\AiWaterfall\` -> `Shirahcan\AiClient\`,
`AiWaterfallClient` -> `AiClient`, `FakeAiWaterfall` -> `FakeAiClient`,
`config('ai-waterfall.*')` -> `config('ai-client.*')`. The env var names are unchanged.

⚠ **Removed in a minor, deliberately.** Removing public names is breaking by the book, but a
major would have reached nobody (`^1` excludes `2.0`) while the zero-use sweep shows nothing
breaks. The caret delivering it is the point (see below).

⚠ **1.0 is for the caret, not for tidiness.** On a 0.x package `^0.4` **excludes** `0.5.0`,
so a release reached nobody until four `composer.json` files were hand-edited. That is
exactly how Portify ended up on v0.4.5, MployNow on v0.4.2 and finance-suite on v0.3.0 at
the same time, on one box, with MployNow unable to stream or embed and nothing saying so.
