<script setup lang="ts">
import {
    Head,
    router,
    setLayoutProps,
    usePage,
    usePoll,
} from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AuthorizationCodeController from '@/actions/App/Modules/Telephony/Http/Controllers/AuthorizationCodeController';
import CallingPermissionController from '@/actions/App/Modules/Telephony/Http/Controllers/CallingPermissionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import ProvisioningBadge from '@/components/telephony/ProvisioningBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes/tenant';
import { index } from '@/routes/tenant/calling';
import type { ProvisioningState } from '@/types/telephony';
import { isInProgress } from '@/types/telephony';

type Permission = 'allow' | 'code' | 'deny';
type Option = { value: string; label: string };
type TenantRef = { code: string; name: string };

type Profile = {
    id: number;
    name: string;
    categories: Record<string, Permission>;
    cross_tenant_scope: 'all' | 'selected';
    destinations: TenantRef[];
    is_default: boolean;
    extensions_count: number;
    version: number;
};

type AuthorizationCode = {
    id: number;
    label: string;
    categories: string[];
    status: 'active' | 'disabled' | 'expired';
    expires_at: string | null;
    user: { id: number; name: string } | null;
    version: number;
    provisioning: ProvisioningState | null;
};

const props = defineProps<{
    profiles: Profile[];
    codes: AuthorizationCode[];
    inbound: { mode: 'none' | 'all' | 'selected'; allowed: TenantRef[] };
    directory: (TenantRef & { dial: string })[];
    members: { id: number; name: string }[];
    categories: (Option & { needs_trunk: boolean })[];
    permissions: Option[];
    scopes: Option[];
    inboundModes: Option[];
    codeStatuses: Option[];
    limits: { destinations: number; code_min: number; code_max: number };
    tenantCode: string;
    hasNode: boolean;
    canManage: boolean;
}>();

const page = usePage();
const tenant = computed(() => page.props.tenant!);
const errors = computed(() => page.props.errors as Record<string, string>);

setLayoutProps({
    breadcrumbs: [
        {
            title: tenant.value.name,
            href: dashboard({ tenant: tenant.value.slug }),
        },
        {
            title: 'Calling permissions',
            href: index({ tenant: tenant.value.slug }),
        },
    ],
});

const categoryLabels = computed(() =>
    Object.fromEntries(props.categories.map((c) => [c.value, c.label])),
);

const { start, stop } = usePoll(
    3000,
    { only: ['codes'] },
    { autoStart: false },
);

watch(
    () => isInProgress(props.codes.map((code) => code.provisioning)),
    (busy) => (busy ? start() : stop()),
    { immediate: true },
);

/** The digits arrive once, as flash data, after creating or resetting a code. */
const revealed = ref<{ label: string; code: string } | null>(null);

watch(
    () =>
        (page.flash as { authorizationCode?: { label: string; code: string } })
            .authorizationCode,
    (code) => {
        if (code) {
            revealed.value = code;
        }
    },
    { immediate: true },
);

const visit = { preserveScroll: true, preserveState: false };

/* Calling profiles */

type ProfileDraft = {
    id: number | null;
    name: string;
    categories: Record<string, Permission>;
    cross_tenant_scope: 'all' | 'selected';
    destinations: string;
    version: number | null;
};

const draft = ref<ProfileDraft | null>(null);

function editProfile(profile: Profile | null): void {
    draft.value = {
        id: profile?.id ?? null,
        name: profile?.name ?? '',
        categories: Object.fromEntries(
            props.categories.map((c) => [
                c.value,
                profile?.categories[c.value] ?? 'deny',
            ]),
        ),
        cross_tenant_scope: profile?.cross_tenant_scope ?? 'all',
        destinations: (profile?.destinations ?? [])
            .map((d) => d.code)
            .join(', '),
        version: profile?.version ?? null,
    };
}

function codesOf(text: string): string[] {
    return text
        .split(/[\s,]+/)
        .map((code) => code.trim())
        .filter((code) => code !== '');
}

function saveProfile(): void {
    const profile = draft.value;

    if (!profile) {
        return;
    }

    const data = {
        name: profile.name,
        categories: profile.categories,
        cross_tenant_scope: profile.cross_tenant_scope,
        destinations:
            profile.cross_tenant_scope === 'selected'
                ? codesOf(profile.destinations)
                : [],
        version: profile.version,
    };
    const route =
        profile.id === null
            ? CallingPermissionController.storeProfile({
                  tenant: tenant.value.slug,
              })
            : CallingPermissionController.updateProfile({
                  tenant: tenant.value.slug,
                  profile: profile.id,
              });

    router.visit(route, {
        data,
        ...visit,
        onSuccess: () => (draft.value = null),
    });
}

function makeDefault(profile: Profile): void {
    router.visit(
        CallingPermissionController.makeDefault({
            tenant: tenant.value.slug,
            profile: profile.id,
        }),
        visit,
    );
}

function removeProfile(profile: Profile): void {
    if (!confirm(`Delete the calling profile ${profile.name}?`)) {
        return;
    }

    router.visit(
        CallingPermissionController.destroyProfile({
            tenant: tenant.value.slug,
            profile: profile.id,
        }),
        visit,
    );
}

function badgeVariant(
    permission: Permission,
): 'default' | 'secondary' | 'outline' {
    return permission === 'allow'
        ? 'default'
        : permission === 'code'
          ? 'secondary'
          : 'outline';
}

const permissionLabels = computed(() =>
    Object.fromEntries(props.permissions.map((p) => [p.value, p.label])),
);

/* Authorization codes */

const newCode = reactive({
    label: '',
    categories: [] as string[],
    user_id: '',
    expires_at: '',
    code: '',
});

const memberOptions = computed<Option[]>(() => [
    { value: '', label: 'Nobody in particular' },
    ...props.members.map((m) => ({ value: String(m.id), label: m.name })),
]);

function createCode(): void {
    router.visit(
        AuthorizationCodeController.store({ tenant: tenant.value.slug }),
        {
            data: {
                label: newCode.label,
                categories: newCode.categories,
                user_id:
                    newCode.user_id === '' ? null : Number(newCode.user_id),
                expires_at:
                    newCode.expires_at === '' ? null : newCode.expires_at,
                code: newCode.code === '' ? null : newCode.code,
            },
            ...visit,
            onSuccess: () =>
                Object.assign(newCode, {
                    label: '',
                    categories: [],
                    user_id: '',
                    expires_at: '',
                    code: '',
                }),
        },
    );
}

function setCodeStatus(
    code: AuthorizationCode,
    status: 'active' | 'disabled',
): void {
    router.visit(
        AuthorizationCodeController.update({
            tenant: tenant.value.slug,
            code: code.id,
        }),
        {
            data: {
                status,
                // an expired code comes back without its old expiry
                ...(code.status === 'expired' ? { expires_at: null } : {}),
                version: code.version,
            },
            ...visit,
        },
    );
}

function resetCode(code: AuthorizationCode): void {
    if (
        !confirm(
            `New digits for ${code.label}? The old ones stop working once the PBX has the new ones.`,
        )
    ) {
        return;
    }

    router.visit(
        AuthorizationCodeController.reset({
            tenant: tenant.value.slug,
            code: code.id,
        }),
        visit,
    );
}

function removeCode(code: AuthorizationCode): void {
    if (!confirm(`Delete the authorization code of ${code.label}?`)) {
        return;
    }

    router.visit(
        AuthorizationCodeController.destroy({
            tenant: tenant.value.slug,
            code: code.id,
        }),
        visit,
    );
}

function formatDate(value: string | null): string {
    return value === null ? '—' : new Date(value).toLocaleString();
}

/* Calls from other tenants */

const inboundDraft = reactive({
    mode: props.inbound.mode as string,
    allowed: props.inbound.allowed.map((t) => t.code).join(', '),
});

function saveInbound(): void {
    router.visit(
        CallingPermissionController.updateInbound({
            tenant: tenant.value.slug,
        }),
        {
            data: {
                mode: inboundDraft.mode,
                allowed:
                    inboundDraft.mode === 'selected'
                        ? codesOf(inboundDraft.allowed)
                        : [],
            },
            ...visit,
        },
    );
}
</script>

<template>
    <Head title="Calling permissions" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Calling permissions"
            description="Where each extension may call beyond this tenant: a calling profile per extension, and authorization codes that let a caller past categories set to “With a code”."
        />

        <Alert>
            <AlertTitle>No trunk to the phone network yet</AlertTitle>
            <AlertDescription>
                Landline, mobile, toll-free, hotline, international and premium
                calls are refused for now, whatever the profile says. Calls to
                other tenants work today: dial 8, their code, then the number.
            </AlertDescription>
        </Alert>

        <Card v-if="revealed" class="border-amber-500/60">
            <CardHeader>
                <CardTitle
                    >Authorization code of {{ revealed.label }}</CardTitle
                >
                <CardDescription>
                    Give it to its owner. It is shown only this once; set new
                    digits if it gets lost. The caller dials the number, hears
                    “please enter your password”, then enters the code and #.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <div
                    class="font-mono text-2xl tracking-widest select-all"
                    data-test="authorization-code"
                >
                    {{ revealed.code }}
                </div>
                <div>
                    <Button variant="outline" @click="revealed = null"
                        >I have saved it</Button
                    >
                </div>
            </CardContent>
        </Card>

        <InputError
            :message="
                errors.profile ??
                errors.name ??
                errors.categories ??
                errors.destinations ??
                errors.authorization_code ??
                errors.status ??
                errors.expires_at ??
                errors.code ??
                errors.label ??
                errors.allowed ??
                errors.allowed_tenants
            "
        />

        <!-- Calling profiles -->
        <Card>
            <CardHeader>
                <CardTitle>Calling profiles</CardTitle>
                <CardDescription>
                    Each extension uses one, or the default. Changing a profile
                    writes every extension that uses it to the PBX again.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/50 text-left text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-2 font-medium">Profile</th>
                                <th
                                    v-for="category in categories"
                                    :key="category.value"
                                    class="px-2 py-2 font-medium"
                                >
                                    {{ category.label }}
                                </th>
                                <th v-if="canManage" class="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="profile in profiles"
                                :key="profile.id"
                                class="border-t align-top"
                            >
                                <td class="px-4 py-2">
                                    <div class="font-medium">
                                        {{ profile.name }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ profile.extensions_count }}
                                        extension(s)
                                    </div>
                                    <Badge
                                        v-if="profile.is_default"
                                        variant="secondary"
                                        class="mt-1"
                                        >Default</Badge
                                    >
                                </td>
                                <td
                                    v-for="category in categories"
                                    :key="category.value"
                                    class="px-2 py-2"
                                >
                                    <Badge
                                        :variant="
                                            badgeVariant(
                                                profile.categories[
                                                    category.value
                                                ],
                                            )
                                        "
                                        >{{
                                            permissionLabels[
                                                profile.categories[
                                                    category.value
                                                ]
                                            ]
                                        }}</Badge
                                    >
                                    <div
                                        v-if="
                                            category.value === 'cross_tenant' &&
                                            profile.categories.cross_tenant !==
                                                'deny' &&
                                            profile.cross_tenant_scope ===
                                                'selected'
                                        "
                                        class="mt-1 font-mono text-xs text-muted-foreground"
                                    >
                                        {{
                                            profile.destinations
                                                .map((d) => d.code)
                                                .join(', ') || 'none'
                                        }}
                                    </div>
                                </td>
                                <td
                                    v-if="canManage"
                                    class="px-4 py-2 text-right"
                                >
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            v-if="!profile.is_default"
                                            size="sm"
                                            variant="ghost"
                                            @click="makeDefault(profile)"
                                            >Make default</Button
                                        >
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            @click="editProfile(profile)"
                                            >Edit</Button
                                        >
                                        <Button
                                            v-if="
                                                !profile.is_default &&
                                                profile.extensions_count === 0
                                            "
                                            size="sm"
                                            variant="ghost"
                                            class="text-destructive"
                                            @click="removeProfile(profile)"
                                            >Delete</Button
                                        >
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="profiles.length === 0">
                                <td
                                    :colspan="categories.length + 2"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No profiles: extensions call within this
                                    tenant only.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="canManage && !draft">
                    <Button variant="outline" @click="editProfile(null)"
                        >New profile</Button
                    >
                </div>

                <form
                    v-if="draft"
                    class="grid gap-4 rounded-xl border p-4"
                    @submit.prevent="saveProfile"
                >
                    <div class="grid gap-2 md:max-w-sm">
                        <Label for="profile_name">Name</Label>
                        <Input
                            id="profile_name"
                            v-model="draft.name"
                            required
                            maxlength="100"
                        />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div
                            v-for="category in categories"
                            :key="category.value"
                            class="grid gap-1"
                        >
                            <Label :for="`permission_${category.value}`">
                                {{ category.label }}
                                <span
                                    v-if="category.needs_trunk"
                                    class="text-xs font-normal text-muted-foreground"
                                    >(needs a trunk)</span
                                >
                            </Label>
                            <NativeSelect
                                :id="`permission_${category.value}`"
                                v-model="draft.categories[category.value]"
                                :options="permissions"
                            />
                        </div>
                    </div>
                    <div
                        v-if="draft.categories.cross_tenant !== 'deny'"
                        class="grid gap-3 md:grid-cols-2"
                    >
                        <div class="grid gap-1">
                            <Label for="cross_tenant_scope"
                                >Other tenants it may call</Label
                            >
                            <NativeSelect
                                id="cross_tenant_scope"
                                v-model="draft.cross_tenant_scope"
                                :options="scopes"
                            />
                        </div>
                        <div
                            v-if="draft.cross_tenant_scope === 'selected'"
                            class="grid gap-1"
                        >
                            <Label for="destinations"
                                >Tenant codes (at most
                                {{ limits.destinations }})</Label
                            >
                            <Input
                                id="destinations"
                                v-model="draft.destinations"
                                class="font-mono"
                                placeholder="1042, 1050"
                            />
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <Button type="submit">{{
                            draft.id === null ? 'Create' : 'Save'
                        }}</Button>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="draft = null"
                            >Cancel</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>

        <!-- Authorization codes -->
        <Card>
            <CardHeader>
                <CardTitle>Authorization codes</CardTitle>
                <CardDescription>
                    {{ limits.code_min }} to {{ limits.code_max }} digits. Any
                    phone of this tenant can use a code for the categories it
                    unlocks, where the phone's profile says “With a code”. Three
                    wrong tries end the call.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <Alert v-if="!hasNode">
                    <AlertDescription>
                        Codes reach the PBX once a platform admin places this
                        tenant on a node.
                    </AlertDescription>
                </Alert>

                <form
                    v-if="canManage"
                    class="grid gap-4 rounded-xl border p-4"
                    @submit.prevent="createCode"
                >
                    <div class="grid gap-3 md:grid-cols-4">
                        <div class="grid gap-1">
                            <Label for="code_label">Owner / label</Label>
                            <Input
                                id="code_label"
                                v-model="newCode.label"
                                required
                                maxlength="100"
                                placeholder="Teacher Anna"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="code_user">Member</Label>
                            <NativeSelect
                                id="code_user"
                                v-model="newCode.user_id"
                                :options="memberOptions"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="code_expires">Expires (optional)</Label>
                            <Input
                                id="code_expires"
                                v-model="newCode.expires_at"
                                type="datetime-local"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="code_digits"
                                >Digits (empty: made up)</Label
                            >
                            <Input
                                id="code_digits"
                                v-model="newCode.code"
                                inputmode="numeric"
                                :maxlength="limits.code_max"
                                class="font-mono"
                                autocomplete="off"
                            />
                        </div>
                    </div>
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium">
                            Unlocks
                        </legend>
                        <div class="flex flex-wrap gap-4">
                            <label
                                v-for="category in categories"
                                :key="category.value"
                                class="flex items-center gap-2 text-sm"
                            >
                                <input
                                    v-model="newCode.categories"
                                    type="checkbox"
                                    class="size-4 accent-primary"
                                    :value="category.value"
                                />
                                {{ category.label }}
                            </label>
                        </div>
                    </fieldset>
                    <div>
                        <Button
                            type="submit"
                            :disabled="newCode.categories.length === 0"
                            >Create code</Button
                        >
                    </div>
                </form>

                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/50 text-left text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-2 font-medium">
                                    Owner / label
                                </th>
                                <th class="px-4 py-2 font-medium">Unlocks</th>
                                <th class="px-4 py-2 font-medium">Status</th>
                                <th class="px-4 py-2 font-medium">Expires</th>
                                <th class="px-4 py-2 font-medium">PBX</th>
                                <th v-if="canManage" class="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="code in codes"
                                :key="code.id"
                                class="border-t"
                            >
                                <td class="px-4 py-2">
                                    <div>{{ code.label }}</div>
                                    <div
                                        v-if="code.user"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ code.user.name }}
                                    </div>
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        authcode:{{ code.id }}
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-xs">
                                    {{
                                        code.categories
                                            .map((c) => categoryLabels[c] ?? c)
                                            .join(', ')
                                    }}
                                </td>
                                <td class="px-4 py-2">
                                    <Badge
                                        :variant="
                                            code.status === 'active'
                                                ? 'secondary'
                                                : 'outline'
                                        "
                                        class="capitalize"
                                        >{{ code.status }}</Badge
                                    >
                                </td>
                                <td class="px-4 py-2 text-xs">
                                    {{ formatDate(code.expires_at) }}
                                </td>
                                <td class="px-4 py-2">
                                    <ProvisioningBadge
                                        :provisioning="code.provisioning"
                                    />
                                </td>
                                <td
                                    v-if="canManage"
                                    class="px-4 py-2 text-right"
                                >
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            v-if="code.status === 'active'"
                                            size="sm"
                                            variant="outline"
                                            @click="
                                                setCodeStatus(code, 'disabled')
                                            "
                                            >Disable</Button
                                        >
                                        <Button
                                            v-else
                                            size="sm"
                                            variant="outline"
                                            @click="
                                                setCodeStatus(code, 'active')
                                            "
                                            >Enable</Button
                                        >
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            @click="resetCode(code)"
                                            >New digits</Button
                                        >
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            class="text-destructive"
                                            @click="removeCode(code)"
                                            >Delete</Button
                                        >
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="codes.length === 0">
                                <td
                                    :colspan="canManage ? 6 : 5"
                                    class="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No authorization codes yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <!-- Other tenants -->
        <div class="grid gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Calls from other tenants</CardTitle>
                    <CardDescription>
                        Who may call this tenant's numbers, as 8{{ tenantCode }}
                        and the number. Their profiles must allow it too.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="grid gap-4" @submit.prevent="saveInbound">
                        <div class="grid gap-1">
                            <Label for="inbound_mode">Take calls from</Label>
                            <NativeSelect
                                id="inbound_mode"
                                v-model="inboundDraft.mode"
                                :options="inboundModes"
                                :disabled="!canManage"
                            />
                        </div>
                        <div
                            v-if="inboundDraft.mode === 'selected'"
                            class="grid gap-1"
                        >
                            <Label for="inbound_allowed">Tenant codes</Label>
                            <Input
                                id="inbound_allowed"
                                v-model="inboundDraft.allowed"
                                class="font-mono"
                                placeholder="1042, 1050"
                                :disabled="!canManage"
                            />
                        </div>
                        <div v-if="canManage">
                            <Button type="submit">Save</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Tenants you can call</CardTitle>
                    <CardDescription>
                        Tenants on this PBX that take calls from this one. Dial
                        the prefix, then their number.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ul class="grid gap-2 text-sm">
                        <li
                            v-for="entry in directory"
                            :key="entry.code"
                            class="flex justify-between gap-4"
                        >
                            <span>{{ entry.name }}</span>
                            <span class="font-mono">{{ entry.dial }}…</span>
                        </li>
                        <li
                            v-if="directory.length === 0"
                            class="text-muted-foreground"
                        >
                            No tenant takes calls from this one yet.
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
