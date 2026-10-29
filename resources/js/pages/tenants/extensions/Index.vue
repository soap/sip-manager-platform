<script setup lang="ts">
import {
    Form,
    Head,
    router,
    setLayoutProps,
    usePage,
    usePoll,
} from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ExtensionController from '@/actions/App/Modules/Telephony/Http/Controllers/ExtensionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import ProvisioningBadge from '@/components/telephony/ProvisioningBadge.vue';
import ProvisioningStalledAlert from '@/components/telephony/ProvisioningStalledAlert.vue';
import RegistrationBadge from '@/components/telephony/RegistrationBadge.vue';
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
import { index } from '@/routes/tenant/extensions';
import type {
    ProvisioningStall,
    ProvisioningState,
    RegistrationState,
} from '@/types/telephony';
import { isInProgress } from '@/types/telephony';

type ExtensionStatus = 'active' | 'disabled';

type Extension = {
    id: number;
    extension_number: string;
    display_name: string;
    status: ExtensionStatus;
    voicemail_enabled: boolean;
    /** null: the tenant's default profile */
    calling_profile_id: number | null;
    version: number;
    provisioning: ProvisioningState | null;
};

type SipServer = {
    domain: string;
    /** The node, for phones whose domain is not in DNS yet. */
    proxy: string | null;
    port: number;
    transports: string[];
};

type SipCredential = SipServer & {
    extension_number: string;
    username: string;
    password: string;
};

const props = defineProps<{
    assignment: {
        node: string;
        code: string;
        status: 'active' | 'suspended';
        voicemail: boolean;
        server: SipServer;
    } | null;
    extensions: Extension[];
    /** Deferred: read live from the node after the page shows, keyed by extension id. */
    registrations?: Record<number, RegistrationState>;
    nextNumber: string | null;
    statuses: { value: ExtensionStatus; label: string }[];
    callingProfiles: { id: number; name: string; is_default: boolean }[];
    canManage: boolean;
    provisioningStalled: ProvisioningStall | null;
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
        { title: 'Extensions', href: index({ tenant: tenant.value.slug }) },
    ],
});

/**
 * The SIP password arrives once, as flash data, after creating or rotating; it is kept on screen
 * until dismissed and never sent again.
 */
const revealed = ref<SipCredential | null>(null);

watch(
    () => (page.flash as { sipCredential?: SipCredential }).sipCredential,
    (credential) => {
        if (credential) {
            revealed.value = credential;
        }
    },
    { immediate: true },
);

type VoicemailPin = {
    extension_number: string;
    pin: string;
    feature_code: string;
};

/** Like the SIP password: a new voicemail PIN arrives once, as flash data. */
const revealedPin = ref<VoicemailPin | null>(null);

watch(
    () => (page.flash as { voicemailPin?: VoicemailPin }).voicemailPin,
    (pin) => {
        if (pin) {
            revealedPin.value = pin;
        }
    },
    { immediate: true },
);

function setVoicemail(extension: Extension, event: Event): void {
    const checkbox = event.target as HTMLInputElement;
    const enabled = checkbox.checked;

    if (
        !enabled &&
        !confirm(
            `Turn voicemail of ${extension.extension_number} off? Unanswered calls will no longer leave a message.`,
        )
    ) {
        checkbox.checked = true;

        return;
    }

    router.visit(
        ExtensionController.voicemail({
            tenant: tenant.value.slug,
            extension: extension.id,
        }),
        { data: { enabled }, preserveScroll: true },
    );
}

function resetPin(extension: Extension): void {
    if (
        !confirm(`Give the mailbox of ${extension.extension_number} a new PIN?`)
    ) {
        return;
    }

    router.visit(
        ExtensionController.resetVoicemailPin({
            tenant: tenant.value.slug,
            extension: extension.id,
        }),
        { preserveScroll: true },
    );
}

const inProgress = computed(() =>
    isInProgress(props.extensions.map((extension) => extension.provisioning)),
);

const { start, stop } = usePoll(
    3000,
    { only: ['extensions', 'provisioningStalled'] },
    { autoStart: false },
);

watch(inProgress, (busy) => (busy ? start() : stop()), { immediate: true });

// phones register and drop off on their own, so their state is read again every 30 seconds
usePoll(30000, { only: ['registrations'] });

function update(extension: Extension, changes: Partial<Extension>): void {
    router.visit(
        ExtensionController.update({
            tenant: tenant.value.slug,
            extension: extension.id,
        }),
        {
            data: {
                display_name: extension.display_name,
                status: extension.status,
                version: extension.version,
                ...changes,
            },
            preserveScroll: true,
            preserveState: false,
        },
    );
}

/** "" is the tenant's default profile. */
const profileOptions = computed(() => [
    {
        value: '',
        label: `Default (${props.callingProfiles.find((profile) => profile.is_default)?.name ?? 'internal only'})`,
    },
    ...props.callingProfiles.map((profile) => ({
        value: String(profile.id),
        label: profile.name,
    })),
]);

function setCallingProfile(extension: Extension, value: string): void {
    router.visit(
        ExtensionController.callingProfile({
            tenant: tenant.value.slug,
            extension: extension.id,
        }),
        {
            data: {
                calling_profile_id: value === '' ? null : Number(value),
                version: extension.version,
            },
            preserveScroll: true,
            preserveState: false,
        },
    );
}

function rename(extension: Extension): void {
    const name = prompt(
        `Name of extension ${extension.extension_number}`,
        extension.display_name,
    );

    if (name && name !== extension.display_name) {
        update(extension, { display_name: name });
    }
}

function rotate(extension: Extension): void {
    if (
        !confirm(
            `Give ${extension.extension_number} a new SIP password? The phone stops registering until it gets the new one.`,
        )
    ) {
        return;
    }

    router.visit(
        ExtensionController.rotateCredential({
            tenant: tenant.value.slug,
            extension: extension.id,
        }),
        { preserveScroll: true },
    );
}

function reapply(extension: Extension): void {
    router.visit(
        ExtensionController.reapply({
            tenant: tenant.value.slug,
            extension: extension.id,
        }),
        { preserveScroll: true },
    );
}

function remove(extension: Extension): void {
    if (
        !confirm(
            `Delete extension ${extension.extension_number} (${extension.display_name})?`,
        )
    ) {
        return;
    }

    router.visit(
        ExtensionController.destroy({
            tenant: tenant.value.slug,
            extension: extension.id,
        }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Extensions" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Extensions"
            description="Internal numbers of this tenant. Each one is a SIP account on the tenant's PBX node; phones of this tenant can call each other."
        />

        <ProvisioningStalledAlert
            v-if="provisioningStalled"
            :stall="provisioningStalled"
        />

        <Alert v-if="!assignment">
            <AlertTitle>No PBX node yet</AlertTitle>
            <AlertDescription>
                A platform admin places this tenant on a node before extensions
                can be created.
            </AlertDescription>
        </Alert>

        <div
            v-else
            class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-muted-foreground"
        >
            <span
                >Node
                <span class="font-medium text-foreground">{{
                    assignment.node
                }}</span></span
            >
            <span
                >SIP domain
                <span class="font-mono text-foreground"
                    >{{ assignment.server.domain }}:{{
                        assignment.server.port
                    }}</span
                ></span
            >
            <span
                >Tenant code
                <span class="font-mono text-foreground">{{
                    assignment.code
                }}</span></span
            >
            <Badge
                v-if="assignment.status === 'suspended'"
                variant="destructive"
                >Suspended</Badge
            >
        </div>

        <Card v-if="revealed" class="border-amber-500/60">
            <CardHeader>
                <CardTitle
                    >SIP account of {{ revealed.extension_number }}</CardTitle
                >
                <CardDescription>
                    Enter these in the phone now. The password is shown only
                    this once; rotate it if it gets lost.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <dl
                    class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]"
                >
                    <dt class="text-muted-foreground">
                        SIP server / domain (realm)
                    </dt>
                    <dd class="font-mono">{{ revealed.domain }}</dd>
                    <template v-if="revealed.proxy">
                        <dt class="text-muted-foreground">
                            Outbound proxy (if the domain is not in DNS)
                        </dt>
                        <dd class="font-mono">{{ revealed.proxy }}</dd>
                    </template>
                    <dt class="text-muted-foreground">Port / transport</dt>
                    <dd class="font-mono">
                        {{ revealed.port }} ·
                        {{ revealed.transports.join(', ').toUpperCase() }}
                    </dd>
                    <dt class="text-muted-foreground">User name / auth user</dt>
                    <dd class="font-mono">{{ revealed.username }}</dd>
                    <dt class="text-muted-foreground">Password</dt>
                    <dd class="font-mono select-all" data-test="sip-password">
                        {{ revealed.password }}
                    </dd>
                </dl>
                <div>
                    <Button variant="outline" @click="revealed = null"
                        >I have saved it</Button
                    >
                </div>
            </CardContent>
        </Card>

        <Card v-if="revealedPin" class="border-amber-500/60">
            <CardHeader>
                <CardTitle
                    >Voicemail PIN of
                    {{ revealedPin.extension_number }}</CardTitle
                >
                <CardDescription>
                    Give it to the person who uses the phone. It is shown only
                    this once; set a new one if it gets lost. A PIN changed from
                    the phone's voicemail menu is put back to this one when the
                    extension is written to the PBX again.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <dl
                    class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]"
                >
                    <dt class="text-muted-foreground">PIN</dt>
                    <dd class="font-mono select-all" data-test="voicemail-pin">
                        {{ revealedPin.pin }}
                    </dd>
                    <dt class="text-muted-foreground">Listen to messages</dt>
                    <dd>
                        Dial
                        <span class="font-mono">{{
                            revealedPin.feature_code
                        }}</span>
                        from the phone (set it as the phone's voicemail number)
                    </dd>
                </dl>
                <div>
                    <Button variant="outline" @click="revealedPin = null"
                        >I have saved it</Button
                    >
                </div>
            </CardContent>
        </Card>

        <Card v-if="canManage && assignment">
            <CardHeader>
                <CardTitle>New extension</CardTitle>
                <CardDescription>
                    2 to 5 digits, not starting with 0, 8 or 9 (and no 1xx). The
                    number cannot change later; phones register with it.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="
                        ExtensionController.store.form({
                            tenant: tenant.slug,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    class="grid gap-4 md:grid-cols-[10rem_1fr_auto] md:items-end"
                    v-slot="{ errors: formErrors, processing }"
                >
                    <div class="grid gap-2">
                        <Label for="extension_number">Number</Label>
                        <Input
                            id="extension_number"
                            name="extension_number"
                            inputmode="numeric"
                            maxlength="5"
                            required
                            :default-value="nextNumber ?? ''"
                            placeholder="201"
                        />
                        <InputError :message="formErrors.extension_number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="display_name">Name</Label>
                        <Input
                            id="display_name"
                            name="display_name"
                            required
                            placeholder="Reception"
                        />
                        <InputError :message="formErrors.display_name" />
                    </div>
                    <Button
                        :disabled="processing || assignment.status !== 'active'"
                        data-test="create-extension-button"
                        >Create</Button
                    >
                </Form>
            </CardContent>
        </Card>

        <InputError
            :message="
                errors.extension ??
                errors.calling_profile_id ??
                errors.voicemail ??
                errors.display_name ??
                errors.status
            "
        />

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Number</th>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th
                            v-if="assignment?.voicemail"
                            class="px-4 py-2 font-medium"
                        >
                            Voicemail
                        </th>
                        <th class="px-4 py-2 font-medium">Calling</th>
                        <th class="px-4 py-2 font-medium">PBX</th>
                        <th class="px-4 py-2 font-medium">Phone</th>
                        <th v-if="canManage" class="px-4 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="extension in extensions"
                        :key="extension.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2 font-mono font-medium">
                            {{ extension.extension_number }}
                        </td>
                        <td class="px-4 py-2">{{ extension.display_name }}</td>
                        <td class="px-4 py-2">
                            <NativeSelect
                                v-if="canManage"
                                class="w-32"
                                :options="statuses"
                                :model-value="extension.status"
                                @update:model-value="
                                    (status) =>
                                        update(extension, {
                                            status: status as ExtensionStatus,
                                        })
                                "
                            />
                            <Badge
                                v-else
                                :variant="
                                    extension.status === 'active'
                                        ? 'secondary'
                                        : 'outline'
                                "
                                class="capitalize"
                                >{{ extension.status }}</Badge
                            >
                        </td>
                        <td v-if="assignment?.voicemail" class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <label
                                    class="flex items-center gap-2"
                                    :class="{ 'cursor-pointer': canManage }"
                                >
                                    <input
                                        type="checkbox"
                                        class="size-4 accent-primary"
                                        :checked="extension.voicemail_enabled"
                                        :disabled="!canManage"
                                        :aria-label="`Voicemail of ${extension.extension_number}`"
                                        @change="
                                            setVoicemail(extension, $event)
                                        "
                                    />
                                    <span class="text-xs">{{
                                        extension.voicemail_enabled
                                            ? 'On'
                                            : 'Off'
                                    }}</span>
                                </label>
                                <Button
                                    v-if="
                                        canManage && extension.voicemail_enabled
                                    "
                                    size="sm"
                                    variant="ghost"
                                    @click="resetPin(extension)"
                                    >New PIN</Button
                                >
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            <NativeSelect
                                v-if="canManage"
                                class="w-48"
                                :aria-label="`Calling profile of ${extension.extension_number}`"
                                :options="profileOptions"
                                :model-value="
                                    extension.calling_profile_id === null
                                        ? ''
                                        : String(extension.calling_profile_id)
                                "
                                @update:model-value="
                                    (value) =>
                                        setCallingProfile(extension, value)
                                "
                            />
                            <span v-else class="text-xs">{{
                                profileOptions.find(
                                    (option) =>
                                        option.value ===
                                        String(
                                            extension.calling_profile_id ?? '',
                                        ),
                                )?.label
                            }}</span>
                        </td>
                        <td class="px-4 py-2">
                            <ProvisioningBadge
                                :provisioning="extension.provisioning"
                            />
                        </td>
                        <td class="px-4 py-2">
                            <span
                                v-if="extension.status === 'disabled'"
                                class="text-xs text-muted-foreground"
                                >—</span
                            >
                            <RegistrationBadge
                                v-else
                                :registration="registrations?.[extension.id]"
                            />
                        </td>
                        <td v-if="canManage" class="px-4 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button
                                    v-if="
                                        extension.provisioning?.status ===
                                        'failed'
                                    "
                                    size="sm"
                                    variant="outline"
                                    @click="reapply(extension)"
                                    >Retry</Button
                                >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="rename(extension)"
                                    >Rename</Button
                                >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="rotate(extension)"
                                    >New password</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="text-destructive"
                                    @click="remove(extension)"
                                    >Delete</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="extensions.length === 0">
                        <td
                            :colspan="
                                (canManage ? 7 : 6) +
                                (assignment?.voicemail ? 1 : 0)
                            "
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No extensions yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
