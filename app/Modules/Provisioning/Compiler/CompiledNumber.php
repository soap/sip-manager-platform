<?php

namespace App\Modules\Provisioning\Compiler;

/**
 * What a number should look like on its node: the OpenSIPS subscriber, the dialplan priorities of
 * its exten in the managed context and its voicemail box (null or empty = none).
 *
 * The number is the exten, tenant code first (1042101); the subscriber and the mailbox belong to
 * the tenant's number (101) in its SIP domain and voicemail context, which say where to write or
 * remove them. Without a tenant (it was deleted) only the exten is known.
 */
final readonly class CompiledNumber
{
    /**
     * @param  array{username: string, domain: string, ha1: string, ha1_sha256: string}|null  $subscriber
     * @param  list<array{priority: int, app: string, appdata: string}>  $dialplan
     * @param  array{context: string, mailbox: string, password: string, fullname: string}|null  $voicemail
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
    ) {}

    /**
     * Over everything written, digests and PIN included, so the read-back proves the node holds it all.
     */
    public function checksum(): string
    {
        return hash('sha256', (string) json_encode(['subscriber' => $this->subscriber, 'dialplan' => $this->dialplan, 'voicemail' => $this->voicemail]));
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
        ];
    }
}
