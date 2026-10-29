<script setup lang="ts">
import { Head, router, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import OperationController from '@/actions/App/Modules/Provisioning/Http/Controllers/Admin/OperationController';
import Heading from '@/components/Heading.vue';
import OperationsTable from '@/components/provisioning/OperationsTable.vue';
import ProvisioningStalledAlert from '@/components/telephony/ProvisioningStalledAlert.vue';
import { index } from '@/routes/admin/operations';
import type { Operation, OperationFilter } from '@/types/provisioning';
import type { ProvisioningStall } from '@/types/telephony';
import { isInProgress } from '@/types/telephony';

const props = defineProps<{
    filter: OperationFilter;
    operations: Operation[];
    provisioningStalled: ProvisioningStall | null;
}>();

setLayoutProps({
    breadcrumbs: [{ title: 'Operations', href: index() }],
});

const filterHref = (filter: OperationFilter) =>
    index(filter === 'all' ? {} : { query: { status: filter } });

const inProgress = computed(() => isInProgress(props.operations));

const { start, stop } = usePoll(
    3000,
    { only: ['operations', 'provisioningStalled'] },
    { autoStart: false },
);

watch(inProgress, (busy) => (busy ? start() : stop()), { immediate: true });

function retry(operation: Operation): void {
    router.visit(OperationController.retry({ operation: operation.id }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Operations" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Operations"
            description="The last 100 changes of every tenant written to the nodes, newest first."
        />

        <ProvisioningStalledAlert
            v-if="provisioningStalled"
            :stall="provisioningStalled"
        />

        <OperationsTable
            :operations="operations"
            :filter="filter"
            :filter-href="filterHref"
            show-tenant
            can-retry
            @retry="retry"
        />
    </div>
</template>
