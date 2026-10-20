export type ProvisioningStatus =
    | 'pending'
    | 'running'
    | 'applied'
    | 'retrying'
    | 'failed'
    | 'superseded';

export type ProvisioningState = {
    status: ProvisioningStatus;
    error_code: string | null;
    error_message: string | null;
    next_attempt_at: string | null;
};

export type ProvisioningStall = {
    reason: 'relay' | 'worker';
    since: string;
};

export type RingStrategy = 'ring_all' | 'sequential';

export type FailoverType = 'hangup' | 'extension' | 'ring_group' | 'voicemail';

export type ExtensionOption = {
    id: number;
    number: string;
    name: string;
    status: 'active' | 'disabled';
    voicemail: boolean;
};

export type RingGroupOption = {
    id: number;
    number: string;
    name: string;
};

export type RingGroupFormOptions = {
    extensions: ExtensionOption[];
    failoverGroups: RingGroupOption[];
    strategies: { value: RingStrategy; label: string }[];
    limits: { members: number; min_seconds: number; max_seconds: number };
};

/**
 * Whether any of the states is still on its way to the PBX, so the page keeps polling.
 */
export function isInProgress(states: (ProvisioningState | null)[]): boolean {
    return states.some((state) =>
        ['pending', 'running', 'retrying'].includes(state?.status ?? ''),
    );
}
