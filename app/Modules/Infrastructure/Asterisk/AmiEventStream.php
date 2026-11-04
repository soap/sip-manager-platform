<?php

namespace App\Modules\Infrastructure\Asterisk;

use App\Modules\Infrastructure\Asterisk\Exceptions\AmiActionFailed;
use App\Modules\Infrastructure\Asterisk\Exceptions\AmiUnavailable;
use App\Modules\Nodes\NodeConnections;
use Throwable;

/**
 * A long-lived AMI connection that receives events (plan §7): logged in with an event mask, then
 * read without blocking whenever stream_select says the socket has data. Ping keeps it honest, as
 * a connection that silently died would otherwise look like a quiet node.
 */
final class AmiEventStream
{
    private string $buffer = '';

    private int $pings = 0;

    private bool $closed = false;

    /**
     * @param  resource  $socket  logged in and not blocking, see open()
     */
    private function __construct(private $socket) {}

    /**
     * Connects and logs in with the event mask, e.g. "call,user".
     *
     * @param  array{host: string, port: int, username: string, password: string}  $ami
     *
     * @throws AmiUnavailable when the node cannot be reached
     * @throws AmiActionFailed when it refuses the login
     */
    public static function connect(array $ami, string $events, int $timeout = NodeConnections::TIMEOUT): self
    {
        $socket = @fsockopen($ami['host'], $ami['port'], $errorCode, $errorMessage, $timeout);

        if ($socket === false) {
            throw AmiUnavailable::cannotConnect($ami['host'], $ami['port'], $errorMessage);
        }

        stream_set_timeout($socket, $timeout);

        return self::open($socket, $ami['username'], $ami['password'], $events);
    }

    /**
     * Logs in over a stream connected to AMI that nothing has read from yet.
     *
     * @param  resource  $socket
     */
    public static function open($socket, string $username, string $password, string $events): self
    {
        try {
            (new AmiClient($socket))->login($username, $password, $events);
        } catch (Throwable $exception) {
            fclose($socket);

            throw $exception;
        }

        stream_set_blocking($socket, false);

        return new self($socket);
    }

    /**
     * The socket to wait on with stream_select.
     *
     * @return resource
     */
    public function socket()
    {
        return $this->socket;
    }

    /**
     * The messages that have arrived since the last call, without waiting for more. Reads until
     * the socket has nothing left, so no data waits in PHP's buffer where stream_select cannot see it.
     *
     * @return list<AmiMessage>
     */
    public function read(): array
    {
        while (is_resource($this->socket) && ($chunk = @fread($this->socket, 65536)) !== false && $chunk !== '') {
            $this->buffer .= $chunk;
        }

        $this->closed = ! is_resource($this->socket) || feof($this->socket);
        $messages = [];

        while (($end = strpos($this->buffer, "\r\n\r\n")) !== false) {
            $block = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 4);

            if (trim($block) !== '') {
                $messages[] = AmiMessage::parse($block);
            }
        }

        return $messages;
    }

    /**
     * Whether Asterisk closed the connection, as seen by the last read().
     */
    public function closed(): bool
    {
        return $this->closed;
    }

    /**
     * Sends Ping and returns its ActionID, which the answer carries.
     */
    public function ping(): string
    {
        $actionId = 'sip-manager-ping-'.++$this->pings;

        if (@fwrite($this->socket, AmiClient::encode(['Action' => 'Ping', 'ActionID' => $actionId])) === false) {
            $this->closed = true;
        }

        return $actionId;
    }

    /**
     * Logs off without waiting for the answer, and closes the connection.
     */
    public function close(): void
    {
        if (! is_resource($this->socket)) {
            return;
        }

        @fwrite($this->socket, AmiClient::encode(['Action' => 'Logoff']));
        fclose($this->socket);
    }
}
