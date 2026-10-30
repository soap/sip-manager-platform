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

/** One call of the tenant from the CDR, as the call history lists it. */
export type CallRecord = {
    id: number;
    /** Internal: both ends are the tenant's; outgoing: to another tenant, or refused before the number was known; incoming: from another tenant. */
    direction: 'internal' | 'outgoing' | 'incoming';
    from: string | null;
    to: string | null;
    status: 'answered' | 'no_answer' | 'busy' | 'failed' | 'denied';
    started_at: string | null;
    answered_at: string | null;
    ended_at: string;
    duration: number;
    billsec: number;
    authorization_code: string | null;
};

/** How far the CDR of a node has been copied. */
export type CdrSync = {
    synced_until: string | null;
    last_run_at: string | null;
    last_success_at: string | null;
    last_count: number;
    last_error: string | null;
};
