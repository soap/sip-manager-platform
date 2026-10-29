<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed } from 'vue';
import NodeController from '@/actions/App/Modules/Nodes/Http/Controllers/Admin/NodeController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { ReconcileRun } from '@/types/provisioning';

const props = defineProps<{
    nodeId: number;
    run: ReconcileRun | null;
}>();

const kindLabels: Record<ReconcileRun['drift'][number]['kind'], string> = {
    missing: 'Missing',
    changed: 'Changed',
    unexpected: 'Unexpected',
};

const triggerLabels: Record<string, string> = {
    schedule: 'on schedule',
    sync: 'after a service changed version',
    manual: 'by hand',
};

const summary = computed(() => {
    const run = props.run;

    if (!run) {
        return 'Not compared yet.';
    }

    switch (run.status) {
        case 'running':
            return 'Comparing…';
        case 'failed':
            return run.error ?? 'Could not read the node.';
        case 'clean':
            return `All ${run.targets_checked} targets match.`;
        default:
            return `${run.missing_count} missing, ${run.changed_count} changed, ${run.unexpected_count} unexpected; ${run.fixed_count} targets written again.`;
    }
});

function variant(
    status: ReconcileRun['status'] | undefined,
): 'secondary' | 'outline' | 'destructive' {
    if (status === 'clean') {
        return 'secondary';
    }

    return status === 'drift' || status === 'failed'
        ? 'destructive'
        : 'outline';
}

const formatDate = (value: string): string => new Date(value).toLocaleString();
</script>

<template>
    <div class="grid gap-3 rounded-lg border p-3" data-test="drift-report">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="font-medium">Drift</span>
                <Badge :variant="variant(run?.status)">{{
                    run?.status ?? 'unknown'
                }}</Badge>
                <span class="text-muted-foreground">{{ summary }}</span>
            </div>
            <Form
                v-bind="NodeController.reconcile.form({ node: nodeId })"
                v-slot="{ processing }"
            >
                <Button size="sm" variant="outline" :disabled="processing"
                    >Reconcile now</Button
                >
            </Form>
        </div>

        <p v-if="run" class="text-xs text-muted-foreground">
            Compared {{ formatDate(run.started_at) }}
            {{ triggerLabels[run.trigger] ?? run.trigger }}. Missing and changed
            rows are written again; unexpected ones are only reported.
        </p>

        <ul
            v-if="run && run.drift.length > 0"
            class="grid max-h-64 gap-1 overflow-y-auto font-mono text-xs"
        >
            <li
                v-for="(entry, position) in run.drift"
                :key="position"
                class="flex flex-wrap gap-2"
            >
                <span
                    class="w-20 shrink-0 font-sans"
                    :class="
                        entry.kind === 'unexpected'
                            ? 'text-muted-foreground'
                            : 'text-destructive'
                    "
                    >{{ kindLabels[entry.kind] }}</span
                >
                <span>{{ entry.item }}</span>
                <span v-if="entry.target" class="text-muted-foreground">{{
                    entry.target
                }}</span>
            </li>
        </ul>
    </div>
</template>
