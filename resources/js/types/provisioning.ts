import type { ProvisioningStatus } from '@/types/telephony';

export type OperationFilter = 'all' | 'active' | 'failed';

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
