<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AssignmentController from '@/actions/App/Modules/Nodes/Http/Controllers/Admin/AssignmentController';
import NodeController from '@/actions/App/Modules/Nodes/Http/Controllers/Admin/NodeController';
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

import { Label } from '@/components/ui/label';
import { index } from '@/routes/admin/nodes';
import { index as tenantExtensions } from '@/routes/tenant/extensions';

type NodeService = {
    release: string | null;
    version: string | null;
    status: string;
    features?: string[];
};

type Assignment = {
    id: number;
    tenant: { name: string; slug: string };
    code: string;
    sip_domain: string;
    status: 'active' | 'suspended';
    extensions_count: number;
};

const props = defineProps<{
    deployerConfigured: boolean;
    unassignedTenants: { value: string; label: string }[];
    assignmentStatuses: { value: string; label: string }[];
    nodes: {
        id: number;
        deployer_server_id: number;
        name: string;
        sip_domain: string | null;
        environment: string;
        status: string;
        region: string | null;
        management_address: string | null;
        opensips: NodeService | null;
        asterisk: NodeService | null;
        endpoints: string[];
        credentials: string[];
        connection_checks: Record<
            string,
            { ok: boolean; message: string }
        > | null;
        checked_at: string | null;
        synced_at: string | null;
        removed_at: string | null;
        tenant_routing: boolean;
        assignments: Assignment[];
    }[];
}>();

const page = usePage();
const pageErrors = computed(() => page.props.errors as Record<string, string>);
const activeNodes = computed(() =>
    props.nodes.filter((node) => !node.removed_at),
);

function unassign(assignment: Assignment, nodeName: string): void {
    if (!confirm(`Take ${assignment.tenant.name} off ${nodeName}?`)) {
        return;
    }

    router.visit(AssignmentController.destroy({ assignment: assignment.id }), {
        preserveScroll: true,
    });
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'SIP nodes', href: index() }],
    },
});

const endpointLabels: Record<string, string> = {
    opensips_db: 'OpenSIPS DB',
    asterisk_db: 'Asterisk DB',
    ami: 'AMI',
    ari: 'ARI',
};

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : 'never';
}
</script>

<template>
    <Head title="SIP nodes" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="SIP nodes"
                description="OpenSIPS and Asterisk servers provisioned by SIP Deployer. They sync every five minutes."
            />

            <Form v-bind="NodeController.sync.form()" v-slot="{ processing }">
                <Button
                    :disabled="processing || !deployerConfigured"
                    data-test="sync-nodes-button"
                    >Sync from SIP Deployer</Button
                >
            </Form>
        </div>

        <Alert v-if="!deployerConfigured" variant="destructive">
            <AlertTitle>SIP Deployer is not configured</AlertTitle>
            <AlertDescription>
                Set SIP_DEPLOYER_URL and SIP_DEPLOYER_TOKEN. The token is issued
                to this application's API client in SIP Deployer, with the
                nodes:read and credentials:read abilities.
            </AlertDescription>
        </Alert>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Node</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Services</th>
                        <th class="px-4 py-2 font-medium">Endpoints</th>
                        <th class="px-4 py-2 font-medium">Synced</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="node in nodes"
                        :key="node.id"
                        class="border-t align-top"
                        :class="{ 'opacity-60': node.removed_at }"
                    >
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ node.name }}</div>
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ node.sip_domain }}
                            </div>
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ node.management_address }} · #{{
                                    node.deployer_server_id
                                }}
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-if="node.removed_at"
                                    variant="destructive"
                                    >Removed</Badge
                                >
                                <Badge
                                    v-else
                                    :variant="
                                        node.status === 'active'
                                            ? 'secondary'
                                            : 'destructive'
                                    "
                                    class="capitalize"
                                    >{{ node.status }}</Badge
                                >
                                <Badge variant="outline" class="capitalize">{{
                                    node.environment
                                }}</Badge>
                            </div>
                        </td>
                        <td class="px-4 py-2 text-xs">
                            <div v-if="node.opensips">
                                OpenSIPS {{ node.opensips.release }}
                                <span class="text-muted-foreground"
                                    >({{ node.opensips.status }})</span
                                >
                            </div>
                            <div v-if="node.asterisk">
                                Asterisk
                                {{
                                    node.asterisk.version ??
                                    node.asterisk.release
                                }}
                                <span class="text-muted-foreground"
                                    >({{ node.asterisk.status }})</span
                                >
                                <div
                                    v-if="node.asterisk.features?.length"
                                    class="text-muted-foreground"
                                >
                                    {{ node.asterisk.features.join(', ') }}
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            <ul class="grid gap-1 text-xs">
                                <li
                                    v-for="endpoint in node.endpoints"
                                    :key="endpoint"
                                    class="flex items-center gap-2"
                                >
                                    <span
                                        class="size-2 shrink-0 rounded-full"
                                        :class="
                                            node.connection_checks?.[endpoint]
                                                ? node.connection_checks[
                                                      endpoint
                                                  ].ok
                                                    ? 'bg-green-500'
                                                    : 'bg-red-500'
                                                : 'bg-muted-foreground/40'
                                        "
                                    />
                                    <span class="shrink-0 whitespace-nowrap">{{
                                        endpointLabels[endpoint] ?? endpoint
                                    }}</span>
                                    <span
                                        v-if="
                                            !node.credentials.includes(endpoint)
                                        "
                                        class="text-destructive"
                                        >no credentials</span
                                    >
                                    <span
                                        v-else-if="
                                            node.connection_checks?.[endpoint]
                                        "
                                        class="max-w-sm truncate text-muted-foreground"
                                        :title="
                                            node.connection_checks[endpoint]
                                                .message
                                        "
                                        >{{
                                            node.connection_checks[endpoint]
                                                .message
                                        }}</span
                                    >
                                </li>
                            </ul>
                            <div
                                v-if="node.checked_at"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                Checked {{ formatDate(node.checked_at) }}
                            </div>
                        </td>
                        <td class="px-4 py-2 text-xs text-muted-foreground">
                            {{ formatDate(node.synced_at) }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Form
                                v-if="!node.removed_at"
                                v-bind="
                                    NodeController.check.form({
                                        node: node.id,
                                    })
                                "
                                v-slot="{ processing }"
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="processing"
                                    >Check connections</Button
                                >
                            </Form>
                        </td>
                    </tr>
                    <tr v-if="nodes.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No nodes yet. Sync from SIP Deployer once it has
                            provisioned a server with OpenSIPS or Asterisk.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Heading
            variant="small"
            title="Tenants"
            description="Every tenant has its own SIP domain, so its numbers are its own. In the node's dialplan a tenant's numbers start with its code (code 1042, number 201 → 1042201)."
        />

        <Card v-for="node in activeNodes" :key="node.id">
            <CardHeader>
                <CardTitle>{{ node.name }}</CardTitle>
                <CardDescription class="font-mono">{{
                    node.sip_domain
                }}</CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <p v-if="!node.tenant_routing" class="text-sm text-destructive">
                    This node serves one shared SIP domain. Reinstall OpenSIPS
                    and Asterisk from SIP Deployer, then sync, before adding
                    tenants.
                </p>

                <div
                    v-for="assignment in node.assignments"
                    :key="assignment.id"
                    class="grid gap-2 rounded-lg border p-3"
                >
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <Link
                            :href="
                                tenantExtensions({
                                    tenant: assignment.tenant.slug,
                                })
                            "
                            class="font-medium underline-offset-4 hover:underline"
                            >{{ assignment.tenant.name }}</Link
                        >
                        <span class="font-mono text-muted-foreground">{{
                            assignment.sip_domain
                        }}</span>
                        <span class="text-muted-foreground"
                            >code
                            <span class="font-mono">{{
                                assignment.code
                            }}</span></span
                        >
                        <span class="text-muted-foreground"
                            >{{ assignment.extensions_count }} extensions</span
                        >
                    </div>
                    <Form
                        v-bind="
                            AssignmentController.update.form({
                                assignment: assignment.id,
                            })
                        "
                        :options="{ preserveScroll: true }"
                        class="grid gap-2 sm:grid-cols-[9rem_auto_auto] sm:items-start"
                        v-slot="{ processing }"
                    >
                        <NativeSelect
                            name="status"
                            aria-label="Status"
                            :options="assignmentStatuses"
                            :default-value="assignment.status"
                        />
                        <Button variant="outline" :disabled="processing"
                            >Save</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            class="text-destructive"
                            :disabled="assignment.extensions_count > 0"
                            :title="
                                assignment.extensions_count > 0
                                    ? 'Delete the tenant\'s extensions first'
                                    : undefined
                            "
                            @click="unassign(assignment, node.name)"
                            >Remove</Button
                        >
                    </Form>
                </div>

                <p
                    v-if="node.assignments.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No tenants on this node yet.
                </p>

                <Form
                    v-if="unassignedTenants.length > 0 && node.tenant_routing"
                    v-bind="AssignmentController.store.form({ node: node.id })"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    class="grid gap-2 border-t pt-4 sm:grid-cols-[1fr_auto] sm:items-end"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-1">
                        <Label :for="`tenant-${node.id}`">Add tenant</Label>
                        <NativeSelect
                            :id="`tenant-${node.id}`"
                            name="tenant_id"
                            :options="unassignedTenants"
                            :default-value="unassignedTenants[0]?.value"
                        />
                        <InputError
                            :message="errors.tenant_id ?? errors.pbx_node_id"
                        />
                    </div>
                    <Button :disabled="processing">Assign</Button>
                </Form>
            </CardContent>
        </Card>

        <InputError :message="pageErrors.assignment" />
    </div>
</template>
