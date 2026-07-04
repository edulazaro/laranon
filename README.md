![Laranon](art/banner.png)

# Laranon

Reversible PII anonymization for Laravel. Detect personal data (Spanish and English packs included), replace it with stable tokens, realistic surrogates or redactions, and restore it afterwards, so LLMs, logs and third parties never see the real thing. Zero dependencies beyond Laravel core.

```php
use EduLazaro\Laranon\Laranon;

$result = Laranon::scope("chat-{$sessionId}")->anonymize(
    'Our client John Smith, SSN 536-90-4399, requests the transfer to GB29 NWBK 6016 1331 9268 19.'
);

$result->text;
// "Our client «PER_1» «AP_1», SSN «SSN_1», requests the transfer to «IBAN_1»."

$llmResponse = $chat->send($result->text);

$result->restore($llmResponse);
// Tokens are replaced back with the real values. The map never left your server.
```

## Why it is different

- **Checksum validation, not just regexes.** DNI (mod-23 letter), NIE, CIF, IBAN (mod-97), credit cards (Luhn plus IIN), NSS, CCC. A `12345678A` with a wrong control letter is *not* flagged, which crushes false positives.
- **Per-word name tokens.** Person names are tokenized per word, never per person: "John" always yields the same token wherever it appears, with zero identity guessing. See below.
- **Stable scoped tokens.** Within a scope (a conversation, a case), the same value always becomes the same token, across every call, so multi-turn chats stay coherent and can be restored at any point.
- **Replacements never repeat.** The token map guarantees that two different values never share a replacement, whatever the strategy generates. A shared surrogate would merge two people and garble restoration.
- **Known entities.** Feed the values you already have (client emails, phones, company names) for exact, total-recall matching where it matters most.
- **Streaming-safe restore.** Tokens split across SSE chunks (even inside the multibyte `«`) are buffered and restored correctly.
- **Exact restoration.** Every token maps back to the literal original text, byte for byte.

## Install

```bash
composer require edulazaro/laranon
```

Optionally publish the config, and the vault migration if you use the database store:

```bash
php artisan vendor:publish --tag=laranon-config
php artisan vendor:publish --tag=laranon-migrations
```

## Usage

### Basic

```php
use EduLazaro\Laranon\Laranon;

$result = Laranon::anonymize($text);   // AnonymizedText { text, map }
$restored = $result->restore($output); // tokens back to original values
```

Helpers: `anon($text, $scope = null)` and `deanon($text, $map = null, $scope = null)`.

### Sessions: the recommended way for LLM chat

For a chat turn you want one in-memory map shared across every piece of the prompt (user message, retrieved context, tool results), and you want it to vanish when the request ends. That is exactly a **session**: a stateful, throwaway object that owns its own map, persists nothing, and dies with the request. No scope, no vault, no cache.

```php
use EduLazaro\Laranon\Anonymizer;

$anon = Anonymizer::create();          // the map lives inside $anon

// Anonymize the whole prompt (targets the 'content' key of each message)
$messages = $anon->anonymize($messages, 'content');

// ...call the model, it decides to use a tool...

// Decode the tool arguments before running the tool (search on real values)
$args = $anon->restore($response->toolCall, 'arguments');
$result = runTool($args);
$messages[] = ['role' => 'tool', 'content' => json_encode($result)];
$messages = $anon->anonymize($messages, 'content'); // fresh PII, same map

// ...second model call...

// Decode the reply before showing it to the user
$reply = $anon->restore($response->content);
// $anon goes out of scope here; the map is gone. Nothing was persisted.
```

`anonymize()` and `restore()` accept three shapes, all sharing the session's map:

```php
$anon->anonymize($string);                              // a string
$anon->anonymize([$a, $b, $c]);                          // a list of strings
$anon->anonymize($messages, 'content');                 // key in each element
$anon->anonymize($messages, 'payload.text');            // nested key (dot)
$anon->anonymize($messages, ['content', 'extra']);      // several keys
$anon->anonymize($messages, 'tool_calls.*.arguments');  // '*' wildcard (nested lists)
```

Only the string leaves at the given paths are touched; `role`, ids, tool names, keys and non-string values are left untouched. Missing keys are skipped. `$anon->stream()` gives an SSE-safe restorer bound to the same map (see Streaming).

Because the chat history is stored **in the clear** (real values), each turn just builds a new session and re-anonymizes the whole prompt from scratch: tokens come out identical (deterministic in reading order), so consistency holds with nothing persisted between turns.

### Per-word name tokens

Person names are tokenized per WORD, never per person. "John Smith" becomes `«PER_1» «AP_1»`: the given name and the surname each get their own independent, stable token. Consistency is automatic and no identity is ever guessed:

- A later bare "John" (same text or a later call in the same scope) gets `«PER_1»` again. The token belongs to the word, not to a person, so it is correct by construction.
- "John Baker" becomes `«PER_1» «AP_2»`: same given-name token, different surname token, which is exactly the information a human reader has.
- "Mr. Baker" becomes "Mr. `«AP_2»`", sharing the token of the "Baker" in "John Baker". Honorifics themselves stay in cleartext.
- Grammatical particles stay in cleartext too: "John de la Cruz" reads `«PER_1» de la «AP_3»`.
- Restoration is exact: each token maps back to the literal word.

Honorific conventions drive the typing of lone words: `Mr./Mrs./Dr./Sr./Sra.` introduce a surname ("Mr. Smith", "Sr. García"), while `D./Dña./Don/Doña/Sir/Lady` introduce a given name ("Sir Ian", "Doña Luz"). With several words, the first is the given name and the rest are surnames ("Mr. James Miller", "D. Juan Pérez García").

### Scopes: stable tokens across turns

```php
// Turn 1
Laranon::scope('chat-42')->anonymize('SSN 536-90-4399');      // «SSN_1»

// Turn 2: same value, same token
Laranon::scope('chat-42')->anonymize('On file: 536-90-4399'); // «SSN_1»

// Restore later without carrying the map around
Laranon::scope('chat-42')->restore($llmResponse);

// Drop the map: pseudonymization becomes effective anonymization
Laranon::scope('chat-42')->forget();
```

Scoped maps persist encrypted (app key) in the configured vault: `cache` (default, TTL-expiring), `database` (survives days, for queued jobs) or `array` (single request). Each `anonymize()` call scans only the new text; name words already tokenized in the scope are swept in that new text as exact, word-bounded literals, so a bare "John" in turn 5 reuses the token established in turn 1.

### Strategies

```php
Laranon::strategy('faker')->anonymize($text);  // valid surrogates, same format
Laranon::strategy('redact')->anonymize($text); // [DNI], one-way, nothing vaulted
```

- `token` (default): `«PER_1»`, `«DNI_1»`... reversible, ideal for LLM round-trips.
- `faker`: realistic surrogates with the same format (a valid fake DNI for a DNI, a bare given name for a name word), reversible, ideal for natural-reading document generation. Uniqueness is enforced by the map.
- `redact`: `[DNI]`, `[PER]`... one-way, nothing can be reversed, ideal for logs and outbound scrubbing.

### Known entities

```php
Laranon::withEntities([
    ['value' => 'Acme Health Ltd', 'type' => 'entity'],
    ['value' => 'jane@client.com', 'type' => 'email'],
])->anonymize($text);
```

Or from models, with the `Anonymizable` trait:

```php
class Client extends Model
{
    use \EduLazaro\Laranon\Concerns\Anonymizable;

    protected array $anonymizable = ['email', 'phone'];
}

Laranon::withModels($case->clients)->anonymize($text);
```

Use entities for values with no ambiguity (emails, phones, company names). Person names are already covered by the per-word detector; feeding them as whole-string entities would fight it.

### Stable tokens for known entities

Values you own that have a database id (a company, an email) can pin their placeholder to it instead of the ad-hoc first-seen counter, so the same record gets the same token in every scope and every call:

```php
Laranon::withStableEntities([
    ['value' => $client->company_name, 'type' => 'entity', 'id' => $client->id],
])->anonymize($text); // always «ENT_{$client->id}»
```

Do NOT use this for person names: a full-name pinned token would conflict with per-word tokenization and silently assert an identity that a bare later mention cannot confirm. Person names are best left to the detector.

### Filtering

```php
Laranon::only(['dni', 'iban'])->anonymize($text);
Laranon::except('url')->anonymize($text);
```

### Streaming (SSE)

```php
$stream = Laranon::stream($result); // or ->scope('chat-42')->stream()

foreach ($chunks as $chunk) {
    echo $stream->push($chunk); // safe to emit; split tokens are buffered
}

echo $stream->flush();
```

### Log scrubbing

```php
// config/logging.php
'stack' => [
    'driver' => 'stack',
    'tap' => [\EduLazaro\Laranon\Integrations\ScrubLogsTap::class],
    // ...
],
```

Every log message and context string is redacted before being written.

### Outbound HTTP scrubbing

```php
Http::scrubPii()->post($url, $payload); // one-way redaction of the request body
```

### Detection audit

```bash
php artisan laranon:scan storage/corpus.txt --types=dni,iban,person
```

Prints every span the anonymizer would replace (type, value, offset, confidence) plus a per-type summary. Useful to measure recall against your own corpus before trusting a pipeline.

## What it detects

| Pack | Types |
| --- | --- |
| Universal | email, IBAN (mod-97), credit cards (Luhn plus IIN), IPv4/v6, URLs, BIC/SWIFT, known entities |
| Spanish (`es`) | DNI, NIE, CIF, NSS, CCC, phones (+34/6xx/7xx/9xx), postal codes (context-gated), plates, court refs (NIG, "autos 123/2023", "expediente número 456/2023"), person names |
| English (`en`) | SSN, UK NINO, passports (context-gated), US/UK phones, ZIP codes, person names |

Ambiguous formats (postal codes, plain 5-digit ZIPs, passports) are context-gated on purpose: precision first.

Person names combine honorific triggers (D., Dña., Sr., Mr., Dr...) with gazetteer-gated capitalized sequences: a run of 2+ capitalized words only counts once its first token is a known given name, and a last-token hit in the surname gazetteer bumps confidence. Every match is split into per-word spans (`person` for the given name, `surname` for each surname word). A curated list of names that are also plain nouns ("Luz", "Rose", "Faith"...) requires a confirmed surname before matching at all, so bare "Luz" is left alone but "Luz Martínez" is not (honorific patterns skip the gazetteer, so "Dña. Luz" still matches on its own). Grammatical particles and honorific titles never gate a match or become name spans, even when census artifacts list them as given names.

The bundled dictionaries (`data/`) are built from official sources, not toy lists:

| File | Entries | Source |
| --- | --- | --- |
| `data/es/names.php` | ~16,600 | INE, national census, frequency >= 20 (via [marcboquet/spanish-names](https://github.com/marcboquet/spanish-names)) |
| `data/es/surnames.php` | ~24,300 | INE, national census, frequency >= 20 |
| `data/es/names_ambiguous.php` | 34 | hand-curated (Marian and virtue names) |
| `data/en/names.php` | ~7,300 | US SSA baby name applications, aggregate occurrences >= 100 (via [hackerb9/ssa-baby-names](https://github.com/hackerb9/ssa-baby-names)) |
| `data/en/surnames.php` | ~162,000 | US Census Bureau, 2010 release, count >= 100 |
| `data/en/names_ambiguous.php` | 31 | hand-curated (flower, gem and virtue names) |

Extend them or point `data_path` in the config to your own directory with the same filenames. Static PHP arrays are cached by opcache in shared memory, so they load once per server, not per request.

## Configuration

`config/laranon.php` controls:

- `locales` and `packs`: which locale packs run (`es`, `en` bundled; add your own).
- `universal`: recognizers that run regardless of locale.
- `strategy` and `strategies`: the default replacement strategy and the registered ones.
- `token_format` and `labels`: `«%s_%d»` with labels like `person => PER`, `surname => AP`, `dni => DNI`.
- `vault`: store (`cache`, `database`, `array`), cache prefix, TTL, table name.
- `data_path`: override the bundled gazetteers.

## Extending

- **Recognizer**: implement `Contracts\Recognizer`, or extend `Recognizers\RegexRecognizer` (patterns with a confidence each, plus an optional checksum validation) and add it to a pack or to the `universal` config list.
- **Locale pack**: implement `Contracts\LocalePack` and register it in `config/laranon.php`.
- **Strategy**: implement `Contracts\Strategy` and add it to `strategies`.
- **Vault**: implement `Contracts\VaultStore` for custom persistence (for example, a column on your own chat session model).

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Sponsors

Laranon is supported by the following sponsors. Thank you for keeping it growing:

<p>
  <a href="https://kenodo.com"><img src="art/logo-kenodo.png" width="24" alt="Kenodo"></a>&nbsp;<a href="https://kenodo.com">Kenodo</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://andorradev.com"><img src="art/logo-andorradev.png" width="24" alt="AndorraDev"></a>&nbsp;<a href="https://andorradev.com">AndorraDev</a>
</p>

## Author

Created by [Edu Lazaro](https://edulazaro.com)

## License

Laranon is open-sourced software licensed under the [MIT license](LICENSE.md).
