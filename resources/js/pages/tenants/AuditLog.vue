<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes/tenant';
import { index } from '@/routes/tenant/audit-log';
import type { AuditEntry } from '@/types';

defineProps<{
    entries: AuditEntry[];
}>();

const page = usePage();
const tenant = computed(() => page.props.tenant!);

setLayoutProps({
    breadcrumbs: [
        {
            title: tenant.value.name,
            href: dashboard({ tenant: tenant.value.slug }),
        },
        { title: 'Audit log', href: index({ tenant: tenant.value.slug }) },
    ],
});

const formatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const describe = (summary: Record<string, unknown>): string =>
    Object.entries(summary)
        .map(([key, value]) => `${key}: ${String(value)}`)
        .join(' · ');
</script>

<template>
    <Head title="Audit log" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Audit log"
            description="The last 100 changes in this tenant. Secrets are never recorded."
        />

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">When</th>
                        <th class="px-4 py-2 font-medium">Who</th>
                        <th class="px-4 py-2 font-medium">Action</th>
                        <th class="px-4 py-2 font-medium">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entry in entries"
                        :key="entry.id"
                        class="border-t align-top"
                    >
                        <td class="px-4 py-2 whitespace-nowrap tabular-nums">
                            {{ formatter.format(new Date(entry.created_at)) }}
                        </td>
                        <td class="px-4 py-2">{{ entry.actor ?? 'System' }}</td>
                        <td class="px-4 py-2 font-mono text-xs">
                            {{ entry.action }}
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ describe(entry.summary) }}
                        </td>
                    </tr>
                    <tr v-if="entries.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            Nothing has changed yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
