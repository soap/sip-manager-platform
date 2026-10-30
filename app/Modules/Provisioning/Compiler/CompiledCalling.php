<?php

namespace App\Modules\Provisioning\Compiler;

/**
 * What a tenant's calling dialplan should look like on its node (plan D7): every exten it owns in
 * the managed context, by name. The tenant owns the extens starting with "_<code>" (the patterns of
 * what its phones dial beyond their own numbers), "<code>a" (its authorization codes and the
 * subroutine that asks for one), "<code>x" (one per tenant that takes its calls) and
 * "<code>denied" (where its refused calls go). Writing it replaces all of them, so an exten left
 * out is removed.
 */
final readonly class CompiledCalling
{
    /**
     * @param  array<string, list<array{priority: int, app: string, appdata: string}>>  $extens
     * @param  array<string, int>  $codeExtens  exten of each authorization code => the code's id
     */
    public function __construct(
        public string $tenantCode,
        public string $state,
        public array $extens,
        public array $codeExtens = [],
        public ?string $salt = null,
    ) {}

    /**
     * @return list<string>
     */
    public function ownedPrefixes(): array
    {
        return self::prefixesOf($this->tenantCode);
    }

    /**
     * @return list<string>
     */
    public static function prefixesOf(string $tenantCode): array
    {
        return ['_'.$tenantCode, $tenantCode.'a', $tenantCode.'x', NumberCompiler::deniedExten($tenantCode)];
    }

    /**
     * A number Asterisk should find under the exten: the exten itself, or for a pattern a number
     * it matches (_10420[2-7]XXXXXXX → 104202000000).
     */
    public static function probeNumber(string $exten): string
    {
        if (! str_starts_with($exten, '_')) {
            return $exten;
        }

        return strtr((string) preg_replace('/\[(.)[^\]]*\]/', '$1', substr($exten, 1)), ['X' => '0', 'Z' => '1', 'N' => '2', '.' => '0', '!' => '']);
    }

    /**
     * Over every row, so the read-back proves the node holds them all.
     */
    public function checksum(): string
    {
        return self::checksumOf($this->extens);
    }

    /**
     * @param  array<string, list<array{priority: int, app: string, appdata: string}>>  $extens
     */
    public static function checksumOf(array $extens): string
    {
        ksort($extens, SORT_STRING);

        return hash('sha256', (string) json_encode($extens));
    }

    /**
     * For config_revisions.compiled: the rows with each code's hash replaced by its id and without
     * the tenant's salt, so a revision does not help anyone guess the codes.
     *
     * @return array<string, mixed>
     */
    public function withoutSecrets(): array
    {
        $extens = [];

        foreach ($this->extens as $exten => $priorities) {
            $name = isset($this->codeExtens[$exten]) ? "{$this->tenantCode}a<code {$this->codeExtens[$exten]}>" : $exten;

            $extens[$name] = $this->salt === null ? $priorities : array_map(
                fn (array $priority): array => [...$priority, 'appdata' => str_replace($this->salt, '<salt>', $priority['appdata'])],
                $priorities,
            );
        }

        return ['tenant' => $this->tenantCode, 'state' => $this->state, 'extens' => $extens];
    }
}
