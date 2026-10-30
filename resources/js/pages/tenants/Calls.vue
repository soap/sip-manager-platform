<script setup lang="ts">
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { ArrowDownLeft, ArrowRight, ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes/tenant';
import { index } from '@/routes/tenant/calls';
import type { CallRecord } from '@/types/provisioning';

defineProps<{
    calls: CallRecord[];
    next_cursor: string | null;
    /** Whether this is an older page, so a link back to the newest calls is shown. */
    paged: boolean;
}>();

const page = usePage();
const tenant = computed(() => page.props.tenant!);

setLayoutProps({
    breadcrumbs: [
        {
            title: tenant.value.name,
            href: dashboard({ tenant: tenant.value.slug }),
        },
        { title: 'Call history', href: index({ tenant: tenant.value.slug }) },
    ],
});

const statuses: Record<
    CallRecord['status'],
    { label: string; variant: 'secondary' | 'outline' | 'destructive' }
> = {
    answered: { label: 'Answered', variant: 'secondary' },
    no_answer: { label: 'No answer', variant: 'outline' },
    busy: { label: 'Busy', variant: 'outline' },
    failed: { label: 'Failed', variant: 'destructive' },
    denied: { label: 'Denied', variant: 'destructive' },
};

const directions = {
    internal: { icon: ArrowRight, label: 'Within the tenant' },
    outgoing: { icon: ArrowUpRight, label: 'To another tenant, or refused' },
    incoming: { icon: ArrowDownLeft, label: 'From another tenant' },
};

const formatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'medium',
});

const formatDate = (value: string | null): string =>
    value ? formatter.format(new Date(value)) : '';

function formatSeconds(seconds: number): string {
    const minutes = Math.floor(seconds / 60);

    return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
}
</script>

<template>
    <Head title="Call history" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Call history"
            description="Calls placed or received by the tenant's numbers, newest first. Calls show up about a minute after they end."
        />

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Ended</th>
                        <th class="px-4 py-2 font-medium">From</th>
                        <th class="px-4 py-2 font-medium">To</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Talk time</th>
                        <th class="px-4 py-2 font-medium">
                            Authorization code
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="call in calls"
                        :key="call.id"
                        class="border-t"
                        data-test="call"
                    >
                        <td class="px-4 py-2 whitespace-nowrap tabular-nums">
                            {{ formatDate(call.ended_at) }}
                        </td>
                        <td class="px-4 py-2 font-mono">
                            {{ call.from ?? '—' }}
                        </td>
                        <td class="px-4 py-2">
                            <span
                                class="inline-flex items-center gap-1 font-mono"
                                :title="directions[call.direction].label"
                            >
                                <component
                                    :is="directions[call.direction].icon"
                                    class="size-3.5 text-muted-foreground"
                                    :aria-label="
                                        directions[call.direction].label
                                    "
                                />
                                {{ call.to ?? '—' }}
                            </span>
                        </td>
                        <td class="px-4 py-2">
                            <Badge :variant="statuses[call.status].variant">{{
                                statuses[call.status].label
                            }}</Badge>
                        </td>
                        <td class="px-4 py-2 tabular-nums">
                            {{
                                call.status === 'answered'
                                    ? formatSeconds(call.billsec)
                                    : '—'
                            }}
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ call.authorization_code ?? '' }}
                        </td>
                    </tr>
                    <tr v-if="calls.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No calls yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex gap-2">
            <Button v-if="paged" variant="outline" size="sm" as-child>
                <Link :href="index({ tenant: tenant.slug })">Newest calls</Link>
            </Button>
            <Button v-if="next_cursor" variant="outline" size="sm" as-child>
                <Link
                    :href="
                        index(
                            { tenant: tenant.slug },
                            { query: { cursor: next_cursor } },
                        )
                    "
                    >Older calls</Link
                >
            </Button>
        </div>
    </div>
</template>
