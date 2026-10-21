<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { RegistrationState } from '@/types/telephony';

const props = defineProps<{
    registration: RegistrationState | undefined;
}>();

const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

function ago(value: string | null): string | null {
    if (!value) {
        return null;
    }

    const seconds = Math.round((new Date(value).getTime() - Date.now()) / 1000);

    if (Math.abs(seconds) < 60) {
        return relative.format(seconds, 'second');
    }

    if (Math.abs(seconds) < 3600) {
        return relative.format(Math.round(seconds / 60), 'minute');
    }

    if (Math.abs(seconds) < 86400) {
        return relative.format(Math.round(seconds / 3600), 'hour');
    }

    return relative.format(Math.round(seconds / 86400), 'day');
}

const latest = computed(() => props.registration?.contacts[0] ?? null);

/** Every registered phone, one per line, for the tooltip. */
const details = computed(() =>
    (props.registration?.contacts ?? [])
        .map((contact) =>
            [
                contact.user_agent || 'Unknown phone',
                contact.address,
                ago(contact.registered_at),
            ]
                .filter(Boolean)
                .join(' · '),
        )
        .join('\n'),
);
</script>

<template>
    <div
        v-if="!registration"
        class="h-5 w-24 animate-pulse rounded-md bg-muted"
    />
    <div
        v-else-if="registration.status === 'registered' && latest"
        class="flex flex-col gap-0.5"
        :title="details"
    >
        <span class="flex items-center gap-1.5 text-xs font-medium">
            <span class="size-2 rounded-full bg-emerald-500" />
            Registered
            <span
                v-if="registration.contacts.length > 1"
                class="text-muted-foreground"
                >×{{ registration.contacts.length }}</span
            >
        </span>
        <span class="max-w-56 truncate text-xs text-muted-foreground">
            {{ latest.user_agent || 'Unknown phone' }} ·
            {{ ago(latest.registered_at) ?? latest.address }}
        </span>
    </div>
    <Badge
        v-else-if="registration.status === 'unknown'"
        variant="outline"
        title="The PBX node could not be asked."
        >Unknown</Badge
    >
    <span
        v-else
        class="flex items-center gap-1.5 text-xs text-muted-foreground"
    >
        <span class="size-2 rounded-full bg-muted-foreground/40" />
        Not registered
    </span>
</template>
