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

type ExtensionStatus = 'active' | 'disabled';

type ProvisioningStatus =
    | 'pending'
    | 'running'
    | 'applied'
    | 'retrying'
    | 'failed'
    | 'superseded';

type Extension = {
    id: number;
    extension_number: string;
    display_name: string;
    status: ExtensionStatus;
    version: number;
    provisioning: {
        status: ProvisioningStatus;
        error_code: string | null;
        error_message: string | null;
        next_attempt_at: string | null;
    } | null;
};

type SipServer = {
    domain: string | null;
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
        prefix: string;
        numbers: string;
        status: 'active' | 'suspended';
        server: SipServer;
    } | null;
    extensions: Extension[];
    nextNumber: string | null;
    statuses: { value: ExtensionStatus; label: string }[];
    canManage: boolean;
    provisioningStalled: { reason: 'relay' | 'worker'; since: string } | null;
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

const inProgress = computed(() =>
    props.extensions.some((extension) =>
        ['pending', 'running', 'retrying'].includes(
            extension.provisioning?.status ?? '',
        ),
    ),
);

const { start, stop } = usePoll(
    3000,
    { only: ['extensions', 'provisioningStalled'] },
    { autoStart: false },
);

watch(inProgress, (busy) => (busy ? start() : stop()), { immediate: true });

const provisioningLabels: Record<ProvisioningStatus, string> = {
    pending: 'Waiting',
    running: 'Writing',
    applied: 'On PBX',
    retrying: 'Retrying',
    failed: 'Failed',
    superseded: 'Waiting',
};

function provisioningVariant(
    status: ProvisioningStatus | undefined,
): 'secondary' | 'outline' | 'destructive' {
    if (status === 'applied') {
        return 'secondary';
    }

    return status === 'failed' ? 'destructive' : 'outline';
}

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

        <Alert
            v-if="provisioningStalled"
            variant="destructive"
            data-test="provisioning-stalled"
        >
            <AlertTitle>Changes are not reaching the PBX</AlertTitle>
            <AlertDescription v-if="page.props.auth.user.is_platform_admin">
                A change has waited since
                {{ new Date(provisioningStalled.since).toLocaleTimeString() }}.
                <template v-if="provisioningStalled.reason === 'relay'">
                    The outbox is not being relayed: check that the scheduler
                    runs (<code>php artisan schedule:work</code>, or cron with
                    <code>schedule:run</code>).
                </template>
                <template v-else>
                    Nothing works the provisioning queue: check that a worker
                    runs with
                    <code>php artisan queue:work --queue=provisioning</code>.
                </template>
                Locally, restart <code>composer run dev</code>. Until then, a
                new SIP password shown here does not work on the phone yet.
            </AlertDescription>
            <AlertDescription v-else>
                A change has waited since
                {{ new Date(provisioningStalled.since).toLocaleTimeString() }}.
                Phones keep their old settings until it is written, so a new SIP
                password does not work yet. Tell your platform admin.
            </AlertDescription>
        </Alert>

        <Alert v-if="!assignment">
            <AlertTitle>No PBX node yet</AlertTitle>
            <AlertDescription>
                A platform admin places this tenant on a node and gives it a
                prefix before extensions can be created.
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
                >SIP server
                <span class="font-mono text-foreground"
                    >{{ assignment.server.domain }}:{{
                        assignment.server.port
                    }}</span
                ></span
            >
            <span
                >Numbers
                <span class="font-mono text-foreground">{{
                    assignment.numbers
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

        <Card v-if="canManage && assignment">
            <CardHeader>
                <CardTitle>New extension</CardTitle>
                <CardDescription>
                    Five digits: prefix {{ assignment.prefix }} and three more.
                    The number cannot change later; phones register with it.
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
                            :placeholder="`${assignment.prefix}001`"
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
            :message="errors.extension ?? errors.display_name ?? errors.status"
        />

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Number</th>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">PBX</th>
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
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <Badge
                                    :variant="
                                        provisioningVariant(
                                            extension.provisioning?.status,
                                        )
                                    "
                                    :title="
                                        extension.provisioning?.error_message ??
                                        undefined
                                    "
                                    >{{
                                        extension.provisioning
                                            ? provisioningLabels[
                                                  extension.provisioning.status
                                              ]
                                            : 'Not written'
                                    }}</Badge
                                >
                                <span
                                    v-if="extension.provisioning?.error_code"
                                    class="max-w-48 truncate text-xs text-muted-foreground"
                                    :title="
                                        extension.provisioning.error_message ??
                                        undefined
                                    "
                                    >{{
                                        extension.provisioning.error_code
                                    }}</span
                                >
                            </div>
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
                            :colspan="canManage ? 5 : 4"
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
