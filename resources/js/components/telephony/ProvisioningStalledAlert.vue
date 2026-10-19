<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import type { ProvisioningStall } from '@/types/telephony';

const props = defineProps<{
    stall: ProvisioningStall;
}>();

const page = usePage();
const since = computed(() => new Date(props.stall.since).toLocaleTimeString());
</script>

<template>
    <Alert variant="destructive" data-test="provisioning-stalled">
        <AlertTitle>Changes are not reaching the PBX</AlertTitle>
        <AlertDescription v-if="page.props.auth.user.is_platform_admin">
            A change has waited since {{ since }}.
            <template v-if="stall.reason === 'relay'">
                The outbox is not being relayed: check that the scheduler runs
                (<code>php artisan schedule:work</code>, or cron with
                <code>schedule:run</code>).
            </template>
            <template v-else>
                Nothing works the provisioning queue: check that a worker runs
                with <code>php artisan queue:work --queue=provisioning</code>.
            </template>
            Locally, restart <code>composer run dev</code>. Until then, a new
            SIP password shown here does not work on the phone yet.
        </AlertDescription>
        <AlertDescription v-else>
            A change has waited since {{ since }}. Phones keep their old
            settings until it is written, so a new SIP password does not work
            yet. Tell your platform admin.
        </AlertDescription>
    </Alert>
</template>
