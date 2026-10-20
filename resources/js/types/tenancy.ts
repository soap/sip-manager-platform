export type TenantRole = 'owner' | 'admin' | 'member';

export type TenantStatus = 'active' | 'suspended';

/** The tenant of the current page, shared with every tenant page. */
export type CurrentTenant = {
    id: string;
    name: string;
    slug: string;
    role: TenantRole | null;
    canManageMembers: boolean;
};

export type RoleOption = {
    value: TenantRole;
    label: string;
};

export type Member = {
    id: number;
    name: string;
    email: string;
    role: TenantRole;
};

export type AuditEntry = {
    id: number;
    action: string;
    actor: string | null;
    summary: Record<string, unknown>;
    ip_address: string | null;
    created_at: string;
};

export type ApiAbility = 'read' | 'write';

export type ApiAbilityOption = {
    value: ApiAbility;
    label: string;
};

export type ApiTokenSummary = {
    id: number;
    name: string;
    abilities: ApiAbility[];
    user: string | null;
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string | null;
};
