<script setup lang="ts">
import {
    Head,
    router,
    setLayoutProps,
    usePage,
    usePoll,
} from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import OperationController from '@/actions/App/Modules/Provisioning/Http/Controllers/OperationController';
import Heading from '@/components/Heading.vue';
import OperationsTable from '@/components/provisioning/OperationsTable.vue';
import ProvisioningStalledAlert from '@/components/telephony/ProvisioningStalledAlert.vue';
import { dashboard } from '@/routes/tenant';
import { index } from '@/routes/tenant/operations';
import type { Operation, OperationFilter } from '@/types/provisioning';
import type { ProvisioningStall } from '@/types/telephony';
import { isInProgress } from '@/types/telephony';

const props = defineProps<{
    filter: OperationFilter;
    operations: Operation[];
    canRetry: boolean;
    provisioningStalled: ProvisioningStall | null;
}>();

const page = usePage();
const tenant = computed(() => page.props.tenant!);

setLayoutProps({
    breadcrumbs: [
        {
            title: tenant.value.name,
            href: dashboard({ tenant: tenant.value.slug }),
        },
        { title: 'Operations', href: index({ tenant: tenant.value.slug }) },
    ],
});

const filterHref = (filter: OperationFilter) =>
    index(
        { tenant: tenant.value.slug },
        filter === 'all' ? {} : { query: { status: filter } },
    );

const inProgress = computed(() => isInProgress(props.operations));

const { start, stop } = usePoll(
    3000,
    { only: ['operations', 'provisioningStalled'] },
    { autoStart: false },
);

watch(inProgress, (busy) => (busy ? start() : stop()), { immediate: true });

function retry(operation: Operation): void {
    router.visit(
        OperationController.retry({
            tenant: tenant.value.slug,
            operation: operation.id,
        }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Operations" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Operations"
            description="The last 100 changes written to the PBX, newest first. A failed change can be written again."
        />

        <ProvisioningStalledAlert
            v-if="provisioningStalled"
            :stall="provisioningStalled"
        />

        <OperationsTable
            :operations="operations"
            :filter="filter"
            :filter-href="filterHref"
            :can-retry="canRetry"
            @retry="retry"
        />
    </div>
</template>
