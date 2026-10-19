<?php

namespace App\Modules\Audit;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Records changes in the audit log. Values under keys that look like secrets are replaced
 * before anything is stored, so a password or SIP secret never reaches the log.
 */
class Audit
{
    public const REDACTED = '[redacted]';

    /** Keys whose values are never stored, matched case-insensitively anywhere in the key. */
    private const SECRET_KEYS = ['password', 'secret', 'token', 'ha1', 'authorization', 'credential'];

    public function __construct(private TenantContext $context, private Request $request) {}

    /**
     * @param  array<string, mixed>  $summary
     */
    public function record(string $action, ?Model $subject = null, array $summary = [], ?Tenant $tenant = null, ?User $actor = null): AuditLog
    {
        $actor ??= $this->request->user();

        return AuditLog::query()->create([
            'tenant_id' => $tenant?->getKey() ?? $this->context->id(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'summary' => $summary === [] ? null : self::redact($summary),
            'ip_address' => $this->request->ip(),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }

    private static function isSecretKey(string $key): bool
    {
        $key = strtolower($key);

        // a PIN (e.g. voicemail_pin), but not words that merely contain "pin" such as "mapping"
        if (preg_match('/(^|_)pin$/', $key) === 1) {
            return true;
        }

        foreach (self::SECRET_KEYS as $secret) {
            if (str_contains($key, $secret)) {
                return true;
            }
        }

        return false;
    }
}
