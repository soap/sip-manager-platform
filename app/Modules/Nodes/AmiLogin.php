<?php

namespace App\Modules\Nodes;

use RuntimeException;

/**
 * Logs in to an Asterisk Manager Interface and straight out again, to prove the address and
 * credentials work.
 */
class AmiLogin
{
    /**
     * @param  array{host: string, port: int, username: string, password: string}  $ami
     *
     * @throws RuntimeException when the node cannot be reached or refuses the login
     */
    public function attempt(array $ami, int $timeout = NodeConnections::TIMEOUT): void
    {
        $socket = @fsockopen($ami['host'], $ami['port'], $errorCode, $errorMessage, $timeout);

        if ($socket === false) {
            throw new RuntimeException("Could not connect to {$ami['host']}:{$ami['port']}: {$errorMessage}");
        }

        try {
            stream_set_timeout($socket, $timeout);

            $banner = (string) fgets($socket);

            if (! str_starts_with($banner, 'Asterisk Call Manager')) {
                throw new RuntimeException('The port does not answer as AMI.');
            }

            fwrite($socket, "Action: Login\r\nUsername: {$ami['username']}\r\nSecret: {$ami['password']}\r\nEvents: off\r\n\r\n");
            $response = $this->readResponse($socket);

            if (! str_contains($response, 'Response: Success')) {
                throw new RuntimeException('AMI refused the login.');
            }

            fwrite($socket, "Action: Logoff\r\n\r\n");
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function readResponse($socket): string
    {
        $response = '';

        while (($line = fgets($socket)) !== false) {
            if (trim($line) === '') {
                break;
            }

            $response .= $line;
        }

        if ($response === '' && stream_get_meta_data($socket)['timed_out']) {
            throw new RuntimeException('AMI did not answer the login in time.');
        }

        return $response;
    }
}
