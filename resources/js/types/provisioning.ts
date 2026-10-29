import type { ProvisioningStatus } from '@/types/telephony';

export type OperationFilter = 'all' | 'active' | 'failed';

/** The newest comparison of a node's rows with the desired state. */
export type ReconcileRun = {
    status: 'running' | 'clean' | 'drift' | 'failed';
    trigger: string;
    targets_checked: number;
    missing_count: number;
    changed_count: number;
    unexpected_count: number;
    fixed_count: number;
    drift: {
        kind: 'missing' | 'changed' | 'unexpected';
        target: string | null;
        item: string;
    }[];
    error: string | null;
    started_at: string;
    finished_at: string | null;
};

/** One change on its way to a node, as the Operations pages list it. */
export type Operation = {
    id: string;
    type: string;
    status: ProvisioningStatus;
    /** What it writes: a number of the tenant, its SIP domain or its calling dialplan. */
    subject: {
        kind: 'number' | 'domain' | 'calling' | 'other';
        number: string | null;
    };
    tenant: { name: string; slug: string } | null;
    node: string | null;
    attempts: number;
    error_code: string | null;
    error_message: string | null;
    next_attempt_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    retryable: boolean;
};
