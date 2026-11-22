# Laranon repository instructions

## Development preference

- Do not introduce Filament. For administration panels and internal applications, use Blade, Livewire, Alpine.js, and the existing Wiremodal, Wiretoast, and Wiretables components where appropriate.

## README sponsors

- Keep the sponsors in this order: Kenodo, AndorraDev, Claimeet, Crowd Legal, Inis Legal.
- Keep the entries in one row in the README. Use each brand's official icon from `art/` at 24 pixels wide, and link both the icon and the brand name to its public website.

## Anonymization behavior

- Use language detection to choose the grammar for person names: articles, particles, honorifics, and function words. Names may come from any language's dictionaries.
- Run number and identifier recognizers from every configured locale pack on every text, regardless of detected language. A Spanish phone or postal code can appear in a text in another language.
- When a text has no clear language and several name recognizers run, let each use the function words of the other candidate languages as name boundaries. Preserve surname words between a given name and a surname, such as `Sans` in `Jordi Sans Vidal`.
- Keep the CENDOJ heading, `AL PAGO`, `PARA QUE`, `Per J. K. Rowling`, `Real Decreto`, and English names in capitals covered by regression tests when changing name detection.
