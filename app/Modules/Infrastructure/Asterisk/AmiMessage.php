<?php

namespace App\Modules\Infrastructure\Asterisk;

/**
 * One message of the Asterisk Manager Interface: "Key: Value" lines ended by a blank line. Keys
 * are matched without regard to case, as Asterisk does; a key that repeats (the Output lines of
 * Command) keeps every value, joined by newlines.
 */
final readonly class AmiMessage
{
    /**
     * @param  array<string, string>  $fields  keyed by lower-case name
     */
    public function __construct(public array $fields) {}

    /**
     * @param  list<string>  $lines  without line endings
     */
    public static function fromLines(array $lines): self
    {
        $fields = [];

        foreach ($lines as $line) {
            $separator = strpos($line, ':');

            // a line without a key is command output in the pre-14 "Response: Follows" format
            [$key, $value] = $separator === false
                ? ['output', $line]
                : [strtolower(trim(substr($line, 0, $separator))), ltrim(substr($line, $separator + 1))];

            $fields[$key] = isset($fields[$key]) ? $fields[$key]."\n".$value : $value;
        }

        return new self($fields);
    }

    public static function parse(string $block): self
    {
        return self::fromLines(array_values(array_filter(
            preg_split('/\r?\n/', $block) ?: [],
            fn (string $line): bool => $line !== '',
        )));
    }

    public function get(string $key): ?string
    {
        return $this->fields[strtolower($key)] ?? null;
    }

    public function actionId(): ?string
    {
        return $this->get('ActionID');
    }

    public function isEvent(): bool
    {
        return $this->get('Event') !== null;
    }

    /**
     * Success, or Follows and Goodbye, which also answer an action that worked.
     */
    public function isSuccess(): bool
    {
        return in_array(strtolower($this->get('Response') ?? ''), ['success', 'follows', 'goodbye'], true);
    }

    /**
     * Whether this answer starts a list of events (EventList: start), or ends one (EventList: Complete).
     */
    public function eventList(): ?string
    {
        $eventList = $this->get('EventList');

        return $eventList === null ? null : strtolower($eventList);
    }
}
