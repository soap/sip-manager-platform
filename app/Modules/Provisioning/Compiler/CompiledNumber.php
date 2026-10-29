<?php

namespace App\Modules\Provisioning\Compiler;

use App\Modules\Tenancy\Models\Tenant;

/**
 * What a number should look like on its node: the OpenSIPS subscriber, the dialplan priorities of
 * its exten in the managed context, its voicemail box and its permission exten (null or empty =
 * none).
 *
 * The number is the exten, tenant code first (1042101); the subscriber and the mailbox belong to
 * the tenant's number (101) in its SIP domain and voicemail context, which say where to write or
 * remove them. Without a tenant (it was deleted) only the exten is known.
 *
 * The permission exten (1042p101, plan D7) answers the Gosub of the tenant's calling dialplan
 * with the flags of the extension's calling profile; only an active extension has one.
 */
final readonly class CompiledNumber
{
    /**
     * @param  array{username: string, domain: string, ha1: string, ha1_sha256: string}|null  $subscriber
     * @param  list<array{priority: int, app: string, appdata: string}>  $dialplan
     * @param  array{context: string, mailbox: string, password: string, fullname: string}|null  $voicemail
     * @param  list<array{priority: int, app: string, appdata: string}>  $permissions
     */
    public function __construct(
        public string $number,
        public string $state,
        public ?array $subscriber,
        public array $dialplan,
        public ?array $voicemail = null,
        public ?string $username = null,
        public ?string $sipDomain = null,
        public ?string $voicemailContext = null,
        public array $permissions = [],
    ) {}

    /**
     * The exten holding the caller's permissions: tenant code, "p", the tenant's number.
     */
    public function permissionExten(): string
    {
        return self::permissionExtenOf($this->number);
    }

    /**
     * @param  string  $routingNumber  tenant code and number, e.g. 1042101 → 1042p101
     */
    public static function permissionExtenOf(string $routingNumber): string
    {
        return substr($routingNumber, 0, Tenant::CODE_DIGITS).'p'.substr($routingNumber, Tenant::CODE_DIGITS);
    }

    /**
     * Over everything written, digests and PIN included, so the read-back proves the node holds it all.
     */
    public function checksum(): string
    {
        return hash('sha256', (string) json_encode([
            'subscriber' => $this->subscriber,
            'dialplan' => $this->dialplan,
            'voicemail' => $this->voicemail,
            'permissions' => $this->permissions,
        ]));
    }

    /**
     * For config_revisions.compiled: the rows without the digests and the PIN.
     *
     * @return array<string, mixed>
     */
    public function withoutSecrets(): array
    {
        return [
            'number' => $this->number,
            'state' => $this->state,
            'subscriber' => $this->subscriber === null ? null : ['username' => $this->subscriber['username'], 'domain' => $this->subscriber['domain']],
            'dialplan' => $this->dialplan,
            'voicemail' => $this->voicemail === null ? null : ['context' => $this->voicemail['context'], 'mailbox' => $this->voicemail['mailbox']],
            'permissions' => $this->permissions,
        ];
    }
}
