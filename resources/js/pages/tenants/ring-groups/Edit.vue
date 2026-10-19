<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import RingGroupController from '@/actions/App/Modules/Telephony/Http/Controllers/RingGroupController';
import Heading from '@/components/Heading.vue';
import RingGroupForm from '@/components/telephony/RingGroupForm.vue';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard } from '@/routes/tenant';
import { edit, index } from '@/routes/tenant/ring-groups';
import type {
    FailoverType,
    RingGroupFormOptions,
    RingStrategy,
} from '@/types/telephony';

const props = defineProps<
    RingGroupFormOptions & {
        ringGroup: {
            id: number;
            number: string;
            name: string;
            strategy: RingStrategy;
            ring_seconds: number;
            status: 'active' | 'disabled';
            version: number;
            member_ids: number[];
            failover_type: FailoverType;
            failover_id: number | null;
        };
        statuses: { value: string; label: string }[];
    }
>();

const page = usePage();
const tenant = computed(() => page.props.tenant!);

setLayoutProps({
    breadcrumbs: [
        {
            title: tenant.value.name,
            href: dashboard({ tenant: tenant.value.slug }),
        },
        { title: 'Ring groups', href: index({ tenant: tenant.value.slug }) },
        {
            title: props.ringGroup.number,
            href: edit({
                tenant: tenant.value.slug,
                ringGroup: props.ringGroup.id,
            }),
        },
    ],
});
</script>

<template>
    <Head :title="`Ring group ${ringGroup.number}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            :title="`Ring group ${ringGroup.number}`"
            description="The number cannot change. Saving writes the group to the PBX again."
        />

        <Card>
            <CardContent class="pt-6">
                <RingGroupForm
                    :action="
                        RingGroupController.update({
                            tenant: tenant.slug,
                            ringGroup: ringGroup.id,
                        })
                    "
                    :initial="{
                        name: ringGroup.name,
                        strategy: ringGroup.strategy,
                        ring_seconds: ringGroup.ring_seconds,
                        members: [...ringGroup.member_ids],
                        failover_type: ringGroup.failover_type,
                        failover_id: ringGroup.failover_id,
                        status: ringGroup.status,
                        version: ringGroup.version,
                    }"
                    :options="{
                        extensions,
                        failoverGroups,
                        strategies,
                        limits,
                    }"
                    :statuses="statuses"
                    submit-label="Save"
                />
            </CardContent>
        </Card>
    </div>
</template>
