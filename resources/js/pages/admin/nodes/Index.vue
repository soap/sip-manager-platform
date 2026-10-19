<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import NodeController from '@/actions/App/Modules/Nodes/Http/Controllers/Admin/NodeController';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/admin/nodes';

type NodeService = {
    release: string | null;
    version: string | null;
    status: string;
    features?: string[];
};

defineProps<{
    deployerConfigured: boolean;
    nodes: {
        id: number;
        deployer_id: number;
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
    }[];
}>();

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
                                    node.deployer_id
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
    </div>
</template>
