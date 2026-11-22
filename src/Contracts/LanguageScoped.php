<?php

namespace EduLazaro\Laranon\Contracts;

/**
 * A recognizer that only fits texts in its pack's language: person names
 * (their particles, honorifics and function words) and values with no
 * checksum that look like ordinary numbers or codes elsewhere (phones with
 * no country prefix, postal codes, plates, SSN). It runs only when the
 * text's language is its pack's, or when the language cannot be told.
 * Recognizers without this mark (IBAN, DNI, cards, emails) run on every
 * text.
 */
interface LanguageScoped
{
}
