<?php

namespace App\Modules\Nodes;

use App\Modules\Nodes\Exceptions\SipDeployerUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client of the SIP Deployer API (v1). Every call is a GET, so connection failures, rate limits
 * and server errors are retried; anything else is turned into SipDeployerUnavailable.
 */
class SipDeployer
{
    /**
     * Manifests of every node with OpenSIPS or Asterisk, in any status.
     *
     * @return list<array<string, mixed>>
     */
    public function nodes(): array
    {
        $nodes = $this->get('nodes', 'the node list');

        if (! array_is_list($nodes)) {
            throw SipDeployerUnavailable::unexpectedResponse('the node list');
        }

        return $nodes;
    }

    /**
     * Passwords of this application's users on the node, keyed by endpoint, with the
     * credentials_version they belong to.
     *
     * @return array<array-key, mixed>
     */
    public function credentials(int $deployerId): array
    {
        return $this->get("nodes/{$deployerId}/credentials", "the credentials of node {$deployerId}");
    }

    /**
     * @return array<array-key, mixed>
     */
    private function get(string $path, string $what): array
    {
        try {
            $data = $this->request()->get($path)->json('data');
        } catch (ConnectionException $exception) {
            throw SipDeployerUnavailable::unreachable($exception->getMessage());
        } catch (RequestException $exception) {
            throw SipDeployerUnavailable::refused($exception->response->status());
        }

        if (! is_array($data)) {
            throw SipDeployerUnavailable::unexpectedResponse($what);
        }

        return $data;
    }

    private function request(): PendingRequest
    {
        $url = config('services.sip_deployer.url');
        $token = config('services.sip_deployer.token');

        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw SipDeployerUnavailable::notConfigured();
        }

        return Http::baseUrl(rtrim($url, '/').'/api/v1/')
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->retry([200, 1000], 0, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException
                    && ($exception->response->serverError() || $exception->response->status() === 429)));
    }
}
