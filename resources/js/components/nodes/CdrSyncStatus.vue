<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { CdrSync } from '@/types/provisioning';

const props = defineProps<{
    sync: CdrSync;
}>();

const formatDate = (value: string): string => new Date(value).toLocaleString();

const summary = computed(() => {
    if (props.sync.last_error) {
        return props.sync.last_error;
    }

    if (!props.sync.synced_until) {
        return 'Not copied yet.';
    }

    return `Copied calls that ended before ${formatDate(props.sync.synced_until)}; ${props.sync.last_count} rows in the last run.`;
});
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-2 rounded-lg border p-3 text-sm"
        data-test="cdr-sync"
    >
        <span class="font-medium">CDR</span>
        <Badge :variant="sync.last_error ? 'destructive' : 'secondary'">{{
            sync.last_error ? 'failing' : 'syncing'
        }}</Badge>
        <span class="text-muted-foreground">{{ summary }}</span>
    </div>
</template>
