<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { AmiListener } from '@/types/provisioning';

const props = defineProps<{
    listener: AmiListener;
}>();

const formatDate = (value: string): string => new Date(value).toLocaleString();

const state = computed((): { label: string; failing: boolean } => {
    if (!props.listener.alive) {
        return { label: 'not running', failing: true };
    }

    if (props.listener.status === 'connected') {
        return { label: 'connected', failing: false };
    }

    return { label: props.listener.status, failing: true };
});

const summary = computed(() => {
    const listener = props.listener;

    if (!listener.alive) {
        return 'No listener is running. Start php artisan ami:listen and a worker for the events queue.';
    }

    if (listener.status !== 'connected') {
        return listener.last_error ?? 'Connecting…';
    }

    const since = listener.connected_at
        ? `since ${formatDate(listener.connected_at)}`
        : '';
    const last = listener.last_event_at
        ? `, last at ${formatDate(listener.last_event_at)}`
        : '';

    return `${listener.events_count} call events ${since}${last}.`;
});
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-2 rounded-lg border p-3 text-sm"
        data-test="ami-listener"
    >
        <span class="font-medium">AMI events</span>
        <Badge :variant="state.failing ? 'destructive' : 'secondary'">{{
            state.label
        }}</Badge>
        <span class="text-muted-foreground">{{ summary }}</span>
    </div>
</template>
