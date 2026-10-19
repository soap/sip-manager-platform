<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { ProvisioningState, ProvisioningStatus } from '@/types/telephony';

defineProps<{
    provisioning: ProvisioningState | null;
}>();

const labels: Record<ProvisioningStatus, string> = {
    pending: 'Waiting',
    running: 'Writing',
    applied: 'On PBX',
    retrying: 'Retrying',
    failed: 'Failed',
    superseded: 'Waiting',
};

function variant(
    status: ProvisioningStatus | undefined,
): 'secondary' | 'outline' | 'destructive' {
    if (status === 'applied') {
        return 'secondary';
    }

    return status === 'failed' ? 'destructive' : 'outline';
}
</script>

<template>
    <div class="flex items-center gap-2">
        <Badge
            :variant="variant(provisioning?.status)"
            :title="provisioning?.error_message ?? undefined"
            >{{
                provisioning ? labels[provisioning.status] : 'Not written'
            }}</Badge
        >
        <span
            v-if="provisioning?.error_code"
            class="max-w-48 truncate text-xs text-muted-foreground"
            :title="provisioning.error_message ?? undefined"
            >{{ provisioning.error_code }}</span
        >
    </div>
</template>
