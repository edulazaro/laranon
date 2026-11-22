![Laranon](art/banner.png)

# Laranon

Reversible PII anonymization for Laravel. Detect personal data (Spanish and English packs included), replace it with stable tokens, realistic surrogates or redactions, and restore it afterwards, so LLMs, logs and third parties never see the real thing. Zero dependencies beyond Laravel core.

```php
use EduLazaro\Laranon\Laranon;

$result = Laranon::scope("chat-{$sessionId}")->anonymize(
    'Our client John Smith, SSN 536-90-4399, requests the transfer to GB29 NWBK 6016 1331 9268 19.'
);

$result->text;
// "Our client «PER_M_1» «AP_1», SSN «SSN_1», requests the transfer to «IBAN_1»."

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
- **Streaming-safe restore.** Replacements split across SSE chunks (tokens, faker surrogates, even a multibyte character cut in half) are buffered and restored correctly, with any `token_format`.
- **Exact restoration.** Every token maps back to the literal original text, byte for byte, when the text writes that value one way. A replacement that starts or ends with a letter only matches as a whole word, so a surrogate "Rey" never turns "Reyes" into "Lópezes". A name the text writes in several ways ("García", "GARCÍA") shares one token, so the model sees one person, and restores to the form that fits its line: a guess, which a line mixing both forms can miss (see [Per-word name tokens](#per-word-name-tokens)). An identifier written in several ways ("12345678Z" and "12.345.678-Z", "+34 600 123 123" and "600123123", an email in any case) shares one token too, and restores to the form seen first.

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

Person names are tokenized per WORD, never per person. "John Smith" becomes `«PER_M_1» «AP_1»`: the given name and the surname each get their own independent, stable token. Consistency is automatic and no identity is ever guessed:

- A later bare "John" (same text or a later call in the same scope) gets `«PER_M_1»` again. The token belongs to the word, not to a person, so it is correct by construction.
- "John Baker" becomes `«PER_M_1» «AP_2»`: same given-name token, different surname token, which is exactly the information a human reader has.
- "Mr. Baker" becomes "Mr. `«AP_2»`", sharing the token of the "Baker" in "John Baker". Honorifics themselves stay in cleartext.
- A given name says its gender: `«PER_F_1»` for a woman's name, `«PER_M_1»` for a man's, `«PER_1»` when the name is ambiguous (Reyes, Cruz) or unknown. The model keeps Spanish agreement ("la demandante") without seeing the name, so there is no need to leave given names in cleartext with `except('person')`. The gender comes from the census (INE) for Spanish names and from the US Social Security records (SSA) for English ones, and belongs to the word, like its token; honorifics stay in cleartext anyway. Set `gender` to `false` in the config to keep every given name neutral.
- Grammatical particles stay in cleartext too: "John de la Cruz" reads `«PER_M_1» de la «AP_3»`.
- The ways a document writes a word ("García", "GARCÍA", "GARCIA") share its token, so the model sees one person. Case and accents are ignored, ñ and ç are not (Peña and Pena are different surnames).
- A word seen in one form always restores to exactly that form. A word seen in several forms restores to the one that fits its line: the capitals form among words in capitals ("PARTE: JUAN GARCÍA"), the other one in running text ("el demandante Juan García"). That is a guess, and a line that mixes both misses it: in a header like "Ponente: JOSE LUIS SEOANE SPIEGELBERG Tipo de Resolución", a name the text also writes in running text comes back as "José Luis Seoane Spiegelberg". In a model's answer either form reads correctly; it matters when a document goes out and must come back byte for byte.

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

A scoped `anonymize()` reads the map, extends it and writes it back under a lock on the default cache store, so two concurrent requests on one scope (two queued jobs on the same case) never hand out `«DNI_1»` to two different values. A call waits up to `vault.lock_wait` seconds (10 by default) for a busy scope and then throws `LockTimeoutException`. Sessions hold no shared state and take no lock.

### Strategies

```php
Laranon::strategy('faker')->anonymize($text);  // valid surrogates, same format
Laranon::strategy('redact')->anonymize($text); // [DNI], one-way, nothing vaulted
```

- `token` (default): `«PER_F_1»`, `«DNI_1»`... reversible, ideal for LLM round-trips.
- `faker`: realistic surrogates with the same format (a valid fake DNI for a DNI, a bare given name for a name word), reversible, ideal for natural-reading document generation. Uniqueness is enforced by the map, a surrogate is never the original itself nor a word already in the text, a given name of known gender gets one of the same gender ("María" never becomes "Carlos"), and once a name pool (50 women's and 50 men's given names, 48 surnames) runs out the next ones fall back to typed tokens such as `«PER_F_100»`. A surrogate is still a real-looking word: if a model writes that same word on its own, restoring turns it into the original. For free LLM output, use `token`.
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

#### Written forms of an entity

Entities are found in any case. By default the ways a text writes one ("Acme Health", "ACME HEALTH") share a single token, whatever type you give it, so the model sees one company; the token restores to the form that fits its line, as names do:

```php
Laranon::withEntities([
    ['value' => 'Initech SL'],                     // no type: 'entity'
    ['value' => 'Acme Health', 'type' => 'client'], // your own type
])->anonymize('ACME HEALTH contra Acme Health.');
// "«CLIENT_1» contra «CLIENT_1»."
```

For a value where case means something (a code, a reference), set `'variants' => false` on that entity, and each of its written forms gets its own token:

```php
Laranon::withEntities([
    ['value' => 'Acme Health', 'type' => 'client'],             // "ACME HEALTH" shares its token
    ['value' => 'AB-12', 'type' => 'ref', 'variants' => false], // "ab-12" gets its own
]);
```

`withStableEntities()` takes the same key. Entities from `withModels()` always share.

### Stable tokens for known entities

Values you own that have a database id (a company, an email) can pin their placeholder to it instead of the ad-hoc first-seen counter, so the same record gets the same token in every scope and every call:

```php
Laranon::withStableEntities([
    ['value' => $client->company_name, 'type' => 'entity', 'id' => $client->id],
])->anonymize($text); // always «ENT_{$client->id}»
```

If that token already belongs to another value in the map (the counter gave `«ENT_1»` to another value in an earlier call of the same scope, or the id comes back for a renamed company), the value gets a regular token instead: restoring the right text wins over keeping the pin. Stable entities work the same in a session (`->withStableEntities([...])->newSession()`).

Do NOT use this for person names: a full-name pinned token would conflict with per-word tokenization and silently assert an identity that a bare later mention cannot confirm. Person names are best left to the detector.

### Filtering

```php
Laranon::only(['dni', 'iban'])->anonymize($text);
Laranon::except('url')->anonymize($text);
```

### Languages

Every pack (`es`, `en`, `fr`, `ca`, `it`, `de`, `nl`, `pt`) is available. The language of a text only decides the grammar its person names are read with: the articles, particles, honorifics and function words that tell a name from a word ("Sans" is a French word and a Catalan surname). The names themselves come from every language's dictionaries, and every number and identifier of the configured packs (phones, postal codes, plates, DNI, SSN, ZIP, IBAN, emails...) is found in every text, whatever its language: a Spanish phone appears in an English letter.

A text with as many letter n-grams as a language profile ranks (310) gets its language from them (see [Language detection](#language-detection)), and each of its paragraphs is read in it, except a long one whose grammatical words and n-grams both point to another language: a Spanish document with a passage pasted in English is read in both. A short paragraph, such as a heading, is read in the document's language, since a few words mislead between close languages ("dos" is Portuguese and Spanish, "va" Catalan and Spanish). A short text on its own is told by its grammatical words, lowercase only, since a capitalized one can be part of a name ("Sans" in Jordi Sans Vidal). With nothing to go by, such as digits alone, the language last told clearly in the scope or the session is used, if any. And when none of that gives a language, or the text is clearly in a language with no pack, every pack reads it, so a name is never lost to a wrong guess. When several packs read a text, each treats the function words of the others as name boundaries too: "AL PAGO" and "PARA QUE" cannot extend an all-caps name. A word between a given name and a surname can still belong to that name ("Jordi Sans Vidal"). A session (`Anonymizer::create()`) tells it once for all the strings it is given, so a short message is read in the conversation's language. When several languages read the same name and give it different genders, it gets none. A given name that is also a word (Rosa, Stella, Graça) is the word right after an article ("la Rosa de los Vientos").

Names are multicultural, whatever the language: each pack's recognizer knows its own full lists and the frequent names and surnames of the others, those borne by at least 1 in 100,000 people of their source (born since 1945 in the birth registers). So "Begoña Arrizabalaga" is found in an English text and "Giuseppe Rossi" in a Spanish one, while a name too rare abroad to tell from a word is not taken: the US records list "General" as a given name, and "Dirección General de Seguridad" is no person.

When you already know the language, set it:

```php
Laranon::language('fr')->anonymize($text);
```

Remove a locale from `locales` in the config only to never use that pack.

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
| Universal | email, IBAN (mod-97), credit cards (Luhn plus IIN), international phones (+cc / 00cc, any grouping), IPv4/v6, URLs, BIC/SWIFT (after the word BIC or SWIFT), known entities |
| Spanish (`es`) | DNI, NIE, NIF K/L/M (typed `dni`), CIF, NIF-IVA (`ES` + DNI, NIE or CIF), NSS, CCC, bare phones (6xx/7xx mobiles, 9xx landlines, with or without separators), postal codes (context-gated), plates, court refs (NIG, "autos 123/2023", "expediente número 456/2023"), person names |
| English (`en`) | SSN, UK NINO, passports (context-gated), US/UK phones, ZIP codes, person names |
| Catalan (`ca`) | person names with the Spanish rules and the INE data (which covers Catalonia), "i" between surnames ("Pujol i Soley"), Catalan names that are words (Llum, Neus, Roser, Pau) |
| Italian (`it`) | person names: "Mario ROSSI" and "ROSSI Mario", particles in any case ("De Luca", "Di Stefano", "dalla Chiesa"), Sig./Sig.ra/Dott./Avv./Ing./Prof. Names from the INE data (which holds the Italians living in Spain) and the other languages' frequent ones, with a neutral gender (Andrea, Simone and Nicola are men's names in Italy). No Italian identifiers yet (codice fiscale, partita IVA) |
| German (`de`) | person names: after the given name only known surnames, or any word right after a particle ("von der Leyen"), since German capitalizes every noun; Herr/Frau/Dr./Prof. Names and surnames from the INE data and the other languages' frequent ones, with a neutral gender |
| Dutch (`nl`) | person names with their particles in any case ("Jan van der Berg", "Van der Berg"), dhr./mevr./mr./dr. Names and surnames from the INE data and the other languages' frequent ones, with a neutral gender |
| Portuguese (`pt`) | person names with the Spanish rules, Dom/Dona and da/do/dos/das ("João da Silva"). Names and surnames from the INE data and the other languages' frequent ones, with a neutral gender |
| French (`fr`) | person names: "Jean DUPONT" and "DUPONT Jean", particles in any case ("de Gaulle", "Le Pen"), M./Mme/Mlle/Me/Dr/Pr. No French identifiers yet (NIR, SIREN, SIRET) |

Ambiguous formats (postal codes, plain 5-digit ZIPs, passports) are context-gated on purpose: precision first.

Person names combine honorific triggers (D., Dña., Sr., Mr., Dr...) with gazetteer-gated capitalized sequences: a run of 2+ capitalized words only counts once its first token is a known given name, and a last-token hit in the surname gazetteer bumps confidence. Every match is split into per-word spans (`person` for the given name, `surname` for each surname word). A curated list of names that are also plain nouns ("Luz", "Rose", "Faith"...) requires a confirmed surname before matching at all, so bare "Luz" is left alone but "Luz Martínez" is not (honorific patterns skip the gazetteer, so "Dña. Luz" still matches on its own). Grammatical particles and honorific titles never gate a match or become name spans, even when census artifacts list them as given names.

In Spanish, honorifics match in any case (`DON JUAN`, `don Juan`, `SR. GARCÍA`), and all-caps names without one ("Comparece JUAN GARCÍA LÓPEZ"), the way escrituras and court documents write them, are found too. An all-caps run must start with a known given name **and** end with a known surname, so headings such as "TRIBUNAL SUPREMO" or "SEGUNDO MOTIVO" are left alone. English also recognizes all-caps names ("JOHN DOE v. RICHARD ROE"); its run must open with a frequent given name, so "GENERAL MOTORS" stays clear. A name in capitals and the same name in mixed case share their tokens (see "Per-word name tokens").

The bundled dictionaries (`data/`) are built from official sources, not toy lists:

| File | Entries | Source |
| --- | --- | --- |
| `data/es/names.php` | ~22,000 | INE, annual census at 1 January 2025, frequency >= 20, plus the names of the 2012 list (via [marcboquet/spanish-names](https://github.com/marcboquet/spanish-names)) that have since dropped under 20 bearers; a rare new name that is also a surname (Sala, Valencia) is left out |
| `data/es/surnames.php` | ~24,300 | INE, national census, frequency >= 20 |
| `data/es/names_ambiguous.php` | 35 | hand-curated (Marian and virtue names) |
| `data/es/names_common.php` | ~8,000 | INE, 2025: given names borne as the first given name by 100 people or more. A name in capitals must open with one, so "REY DE ESPAÑA" is not a person |
| `data/es/names_gender.php` | ~20,900 | INE (2025, 2012 for older names), by first given name: `f` or `m` when 95% or more of the people bearing it share it; ambiguous names left out |
| `data/en/names.php` | ~7,300 | US SSA baby name applications, aggregate occurrences >= 100 (via [hackerb9/ssa-baby-names](https://github.com/hackerb9/ssa-baby-names)) |
| `data/en/surnames.php` | ~162,000 | US Census Bureau, 2010 release, count >= 100 |
| `data/en/names_ambiguous.php` | 31 | hand-curated (flower, gem and virtue names) |
| `data/ca/names_ambiguous.php` | 13 | hand-curated (Marian and virtue names: Llum, Neus, Roser), with the Spanish list |
| `data/es/names_frequent.php`, `data/en/names_frequent.php`, `data/fr/names_frequent.php` | ~3,500 / ~5,000 / ~3,900 | the given names borne by at least 1 in 100,000 people of the source (INE 2025; SSA and INSEE births since 1945), used by the other languages' recognizers |
| `data/fr/surnames_frequent.php` | ~14,500 | the same for INSEE surnames (births 1941-2000) |
| `data/pt/names_ambiguous.php` | 14 | hand-curated (Marian and virtue names: Graça, Esperança, Conceição), with the Spanish list |
| `data/it/names_ambiguous.php` | 21 | hand-curated (virtue, Marian and flower names: Speranza, Chiara, Stella) |
| `data/fr/names.php` | ~22,900 | INSEE, Fichier des prénoms, births in France 1900-2024: every word of a name borne by 20 people or more |
| `data/fr/surnames.php` | ~210,000 | INSEE, Fichier des noms, births in France 1891-2000, surnames given 30 times or more |
| `data/fr/names_gender.php` | ~21,300 | INSEE, by first given name: `f` or `m` when 95% or more share it; ambiguous names (Dominique, Claude) left out |
| `data/fr/names_ambiguous.php` | 28 | hand-curated (virtue, flower and gem names: Rose, Claire, Constance) |
| `data/en/names_gender.php` | ~6,200 | US SSA, 1880-2020: `f` or `m` when 95% or more of the babies given the name share it; ambiguous names (Taylor, Jordan) left out |

Extend them or point `data_path` in the config to your own directory with the same filenames. Static PHP arrays are cached by opcache in shared memory, so they load once per server, not per request.

### Language detection

`EduLazaro\Laranon\Support\LanguageDetector` tells the language of a text among 116, with no AI and no dependency: it ranks the sequences of one to three letters of the text by frequency and compares that ranking with each language's (Cavnar and Trenkle's n-gram method).

```php
use EduLazaro\Laranon\Support\LanguageDetector;

LanguageDetector::create()->detect('Le demandeur expose que la société défenderesse...'); // "fr"
LanguageDetector::create(['es', 'en', 'fr'])->scores($text);                          // ['es' => 0.51, 'fr' => ...]
```

`detect()` returns null when it cannot tell: a text with no letters, or a runner-up within 0.025 of the best score (a word or two, a mixed text, pt-BR against pt-PT).

It is based on [patrickschur/language-detection](https://github.com/patrickschur/language-detection): the same algorithm and the same language profiles (`data/languages`, MIT License, Copyright (c) Patrick Schur), so it gives exactly the same scores. A test checks them against that package.

## Configuration

`config/laranon.php` controls:

- `locales` and `packs`: the locale packs available (`es`, `en`, `fr`, `ca`, `it`, `de`, `nl` and `pt`; add your own). Each text is read with the packs of its languages (see [Languages](#languages)).
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
