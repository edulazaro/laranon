<?php

use EduLazaro\Laranon\Support\AnonymizedText;
use EduLazaro\Laranon\Support\TokenMap;

if (! function_exists('anon')) {
    /**
     * Anonymize a text, optionally under a scope.
     */
    function anon(string $text, ?string $scope = null): AnonymizedText
    {
        return app('laranon')->scope($scope)->anonymize($text);
    }
}

if (! function_exists('deanon')) {
    /**
     * Restore tokens using a map, an AnonymizedText, or a scope.
     */
    function deanon(string $text, TokenMap|AnonymizedText|array|null $map = null, ?string $scope = null): string
    {
        return app('laranon')->scope($scope)->restore($text, $map);
    }
}
