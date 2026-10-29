<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import ProvisioningBadge from '@/components/telephony/ProvisioningBadge.vue';
import { Button } from '@/components/ui/button';
import type { Operation, OperationFilter } from '@/types/provisioning';

defineProps<{
    operations: Operation[];
    filter: OperationFilter;
    /** The link of each filter, e.g. the page with ?status=failed. */
    filterHref: (
        filter: OperationFilter,
    ) => NonNullable<InertiaLinkProps['href']>;
    showTenant?: boolean;
    canRetry?: boolean;
}>();

const emit = defineEmits<{
    (e: 'retry', operation: Operation): void;
}>();

const filters: { value: OperationFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'In progress' },
    { value: 'failed', label: 'Failed' },
];

const types: Record<string, string> = {
    'extension.created': 'Extension created',
    'extension.updated': 'Extension changed',
    'extension.deleted': 'Extension deleted',
    'extension.credential_rotated': 'SIP password changed',
    'ring_group.created': 'Ring group created',
    'ring_group.updated': 'Ring group changed',
    'ring_group.deleted': 'Ring group deleted',
    'dependency.changed': 'Member or failover changed',
    'assignment.changed': 'Tenant status changed',
    'assignment.created': 'Tenant placed on node',
    'assignment.deleted': 'Tenant taken off node',
    'calling_profile.changed': 'Calling profile changed',
    'authorization_code.created': 'Authorization code created',
    'authorization_code.updated': 'Authorization code changed',
    'authorization_code.deleted': 'Authorization code deleted',
    'cross_tenant.changed': 'Cross-tenant calling changed',
    reapplied: 'Written again',
    reconciled: 'Drift fixed',
};

const formatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'medium',
});

const formatDate = (value: string | null): string =>
    value ? formatter.format(new Date(value)) : '';

function describe(operation: Operation): string {
    switch (operation.subject.kind) {
        case 'number':
            return `Number ${operation.subject.number}`;
        case 'domain':
            return 'SIP domain';
        case 'calling':
            return 'Calling permissions';
        default:
            return '—';
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <nav class="flex gap-2" aria-label="Filter operations">
            <Button
                v-for="option in filters"
                :key="option.value"
                :variant="option.value === filter ? 'secondary' : 'ghost'"
                size="sm"
                as-child
            >
                <Link
                    :href="filterHref(option.value)"
                    preserve-scroll
                    :aria-current="option.value === filter ? 'page' : undefined"
                    >{{ option.label }}</Link
                >
            </Button>
        </nav>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Asked</th>
                        <th v-if="showTenant" class="px-4 py-2 font-medium">
                            Tenant
                        </th>
                        <th class="px-4 py-2 font-medium">Change</th>
                        <th class="px-4 py-2 font-medium">Writes</th>
                        <th class="px-4 py-2 font-medium">Node</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Tries</th>
                        <th v-if="canRetry" class="px-4 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="operation in operations"
                        :key="operation.id"
                        class="border-t align-top"
                        data-test="operation"
                    >
                        <td
                            class="px-4 py-2 whitespace-nowrap tabular-nums"
                            :title="operation.id"
                        >
                            {{ formatDate(operation.created_at) }}
                        </td>
                        <td v-if="showTenant" class="px-4 py-2">
                            {{ operation.tenant?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-2">
                            {{ types[operation.type] ?? operation.type }}
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ describe(operation) }}
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ operation.node ?? '—' }}
                        </td>
                        <td class="px-4 py-2">
                            <ProvisioningBadge :provisioning="operation" />
                            <p
                                v-if="operation.error_message"
                                class="mt-1 max-w-md text-xs break-words text-muted-foreground"
                            >
                                {{ operation.error_message }}
                            </p>
                            <p
                                v-if="
                                    operation.status === 'retrying' &&
                                    operation.next_attempt_at
                                "
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                Next try
                                {{ formatDate(operation.next_attempt_at) }}
                            </p>
                        </td>
                        <td class="px-4 py-2 tabular-nums">
                            {{ operation.attempts }}
                        </td>
                        <td v-if="canRetry" class="px-4 py-2 text-right">
                            <Button
                                v-if="operation.retryable"
                                size="sm"
                                variant="outline"
                                @click="emit('retry', operation)"
                                >Retry</Button
                            >
                        </td>
                    </tr>
                    <tr v-if="operations.length === 0">
                        <td
                            :colspan="
                                6 + (showTenant ? 1 : 0) + (canRetry ? 1 : 0)
                            "
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            Nothing here.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
