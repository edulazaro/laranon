<?php

namespace EduLazaro\Laranon\Contracts;

/**
 * A recognizer that applies the grammar of its pack's language: the person
 * names, with their particles, honorifics and function words. It runs only
 * when the text's language is its pack's, or when the language cannot be
 * told. Every other recognizer (numbers, identifiers, emails) runs on every
 * text, whatever its language: a Spanish phone appears in an English
 * letter.
 */
interface LanguageScoped
{
}
