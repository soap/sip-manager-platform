<?php

namespace App\Modules\Provisioning;

use App\Modules\Infrastructure\Asterisk\Exceptions\AmiActionFailed;
use App\Modules\Infrastructure\Asterisk\Exceptions\AmiUnavailable;
use App\Modules\Nodes\Exceptions\NodeNotConnectable;
use App\Modules\Provisioning\Exceptions\ProvisioningRejected;
use App\Modules\Provisioning\Exceptions\ReadBackMismatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

/**
 * Sorts a failure into one worth retrying (the node is briefly unreachable, a lock, a race) and one
 * that needs a person first (plan v0.2 §6.5). Messages never carry passwords: MariaDB and PDO name
 * the user and host, not the secret.
 */
class ErrorClassifier
{
    /** MariaDB client and server codes that pass: can't connect, gone away, lost, lock wait, deadlock. */
    private const RETRYABLE_DATABASE_CODES = [2002, 2003, 2006, 2013, 1205, 1213];

    private const ACCESS_DENIED = 1045;

    /**
     * @return array{retryable: bool, code: string, message: string}
     */
    public function classify(Throwable $exception): array
    {
        $message = Str::limit($exception->getMessage(), 500);

        if ($exception instanceof ProvisioningRejected) {
            return ['retryable' => false, 'code' => $exception->errorCode, 'message' => $message];
        }

        if ($exception instanceof ReadBackMismatch) {
            return ['retryable' => true, 'code' => 'read_back_mismatch', 'message' => $message];
        }

        if ($exception instanceof NodeNotConnectable) {
            // the next node sync may bring the missing credentials
            return ['retryable' => true, 'code' => 'node_not_connectable', 'message' => $message];
        }

        if ($exception instanceof AmiUnavailable) {
            return ['retryable' => true, 'code' => 'node_unreachable', 'message' => $message];
        }

        if ($exception instanceof AmiActionFailed) {
            return $exception->isLogin()
                ? ['retryable' => false, 'code' => 'node_auth_failed', 'message' => $message]
                : ['retryable' => false, 'code' => 'ami_error', 'message' => $message];
        }

        if ($exception instanceof QueryException || $exception instanceof PDOException) {
            $code = $this->databaseCode($exception);

            if ($code === self::ACCESS_DENIED) {
                return ['retryable' => false, 'code' => 'node_auth_failed', 'message' => $message];
            }

            if (in_array($code, self::RETRYABLE_DATABASE_CODES, true)) {
                return ['retryable' => true, 'code' => 'node_unreachable', 'message' => $message];
            }

            return ['retryable' => false, 'code' => 'database_error', 'message' => $message];
        }

        return ['retryable' => false, 'code' => 'unexpected', 'message' => $message];
    }

    /**
     * The MariaDB error number: from errorInfo after a query, or "[2002]" in the message when the
     * connection itself failed.
     */
    private function databaseCode(QueryException|PDOException $exception): ?int
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        if (is_int($driverCode)) {
            return $driverCode;
        }

        return preg_match('/\[(\d{4})\]/', $exception->getMessage(), $matches) === 1 ? (int) $matches[1] : null;
    }
}
