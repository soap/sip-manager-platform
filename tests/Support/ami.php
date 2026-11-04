<?php

/*
| Helpers for tests that talk AMI. Loaded by tests/Pest.php.
*/

/**
 * One AMI message as Asterisk writes it.
 *
 * @param  array<string, string|list<string>>  $fields
 */
function amiReply(array $fields): string
{
    $message = '';

    foreach ($fields as $key => $values) {
        foreach ((array) $values as $value) {
            $message .= "{$key}: {$value}\r\n";
        }
    }

    return $message."\r\n";
}
