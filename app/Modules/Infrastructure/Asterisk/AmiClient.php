<?php

namespace App\Modules\Infrastructure\Asterisk;

use App\Modules\Infrastructure\Asterisk\Exceptions\AmiActionFailed;
use App\Modules\Infrastructure\Asterisk\Exceptions\AmiUnavailable;
use App\Modules\Nodes\NodeConnections;
use InvalidArgumentException;
use Throwable;

/**
 * A small blocking client for the Asterisk Manager Interface (TCP 5038): one action at a time, each
 * answered by the message with its ActionID. Logs in with events off, so unrelated events are rare
 * and skipped. For the long-running event listener of Phase 3 see plan v0.2 §7.
 */
class AmiClient
{
    private int $sequence = 0;

    /**
     * @param  resource  $socket  a stream connected to AMI that nothing has read from yet
     */
    public function __construct(private $socket) {}

    /**
     * Connects and logs in.
     *
     * @param  array{host: string, port: int, username: string, password: string}  $ami
     *
     * @throws AmiUnavailable when the node cannot be reached
     * @throws AmiActionFailed when it refuses the login
     */
    public static function connect(array $ami, int $timeout = NodeConnections::TIMEOUT): self
    {
        $socket = @fsockopen($ami['host'], $ami['port'], $errorCode, $errorMessage, $timeout);

        if ($socket === false) {
            throw AmiUnavailable::cannotConnect($ami['host'], $ami['port'], $errorMessage);
        }

        stream_set_timeout($socket, $timeout);
        $client = new self($socket);

        try {
            $client->login($ami['username'], $ami['password']);
        } catch (Throwable $exception) {
            $client->close();

            throw $exception;
        }

        return $client;
    }

    /**
     * Reads the "Asterisk Call Manager/x.y" banner and logs in without events.
     */
    public function login(string $username, string $password): void
    {
        $banner = fgets($this->socket);

        if ($banner === false) {
            throw $this->readFailure();
        }

        if (! str_starts_with($banner, 'Asterisk Call Manager')) {
            throw AmiUnavailable::notAmi();
        }

        $this->action('Login', ['Username' => $username, 'Secret' => $password, 'Events' => 'off']);
    }

    /**
     * Sends an action and waits for its answer, and for the events it lists when it starts a list.
     *
     * @param  array<string, string|int>  $fields
     *
     * @throws AmiActionFailed when Asterisk answers with an error
     */
    public function action(string $action, array $fields = []): AmiResponse
    {
        $actionId = 'sip-manager-'.++$this->sequence;

        $this->write(['Action' => $action, 'ActionID' => $actionId, ...$fields]);

        $message = $this->readFor($actionId);

        if (! $message->isSuccess()) {
            throw AmiActionFailed::for($action, $message->get('Message'));
        }

        $events = [];

        if ($message->eventList() === 'start') {
            while (($event = $this->readFor($actionId))->eventList() !== 'complete') {
                $events[] = $event;
            }
        }

        return new AmiResponse($message, $events);
    }

    /**
     * The value of a global variable or of a dialplan function, e.g. DIALPLAN_EXISTS(ctx,exten,1).
     */
    public function getvar(string $variable): string
    {
        return $this->action('Getvar', ['Variable' => $variable])->get('Value') ?? '';
    }

    /**
     * Runs a CLI command (needs the "command" write privilege) and returns its output lines.
     *
     * @return list<string>
     */
    public function command(string $command): array
    {
        return $this->action('Command', ['Command' => $command])->output();
    }

    /**
     * Logs off when the connection still works, then closes it.
     */
    public function logoff(): void
    {
        try {
            $this->action('Logoff');
        } catch (Throwable) {
            // the connection ends either way
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /**
     * @param  array<string, string|int>  $fields
     */
    private function write(array $fields): void
    {
        $message = '';

        foreach ($fields as $key => $value) {
            $value = (string) $value;

            if (preg_match('/[\r\n]/', $key.$value) === 1) {
                throw new InvalidArgumentException("The AMI field {$key} must be a single line.");
            }

            $message .= "{$key}: {$value}\r\n";
        }

        if (@fwrite($this->socket, $message."\r\n") === false) {
            throw AmiUnavailable::closed();
        }
    }

    /**
     * The next message for this action; events and answers to other actions are skipped.
     */
    private function readFor(string $actionId): AmiMessage
    {
        do {
            $message = $this->read();
        } while ($message->actionId() !== $actionId);

        return $message;
    }

    private function read(): AmiMessage
    {
        $lines = [];

        while (true) {
            $line = fgets($this->socket);

            if ($line === false) {
                throw $this->readFailure();
            }

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                if ($lines === []) {
                    continue;
                }

                return AmiMessage::fromLines($lines);
            }

            $lines[] = $line;
        }
    }

    private function readFailure(): AmiUnavailable
    {
        return stream_get_meta_data($this->socket)['timed_out'] ? AmiUnavailable::timedOut() : AmiUnavailable::closed();
    }
}
