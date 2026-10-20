<?php

namespace App\Modules\Infrastructure\Asterisk;

/**
 * Asterisk's answer to one action, with the events it sent for an action that lists something
 * (e.g. QueueStatus, PJSIPShowEndpoint), in order and without the closing "Complete" event.
 */
final readonly class AmiResponse
{
    /**
     * @param  list<AmiMessage>  $events
     */
    public function __construct(public AmiMessage $message, public array $events = []) {}

    public function get(string $key): ?string
    {
        return $this->message->get($key);
    }

    /**
     * The Output lines of a Command action.
     *
     * @return list<string>
     */
    public function output(): array
    {
        $output = $this->message->get('Output');

        return $output === null ? [] : explode("\n", $output);
    }
}
