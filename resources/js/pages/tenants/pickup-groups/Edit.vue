<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PickupGroupController from '@/actions/App/Modules/Telephony/Http/Controllers/PickupGroupController';
import Heading from '@/components/Heading.vue';
import PickupGroupForm from '@/components/telephony/PickupGroupForm.vue';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard } from '@/routes/tenant';
import { edit, index } from '@/routes/tenant/pickup-groups';
import type { PickupExtensionOption } from '@/types/telephony';

const props = defineProps<{
    pickupGroup: {
        id: number;
        name: string;
        version: number;
        member_ids: number[];
    };
    extensions: PickupExtensionOption[];
}>();

const page = usePage();
const tenant = computed(() => page.props.tenant!);

setLayoutProps({
    breadcrumbs: [
        {
            title: tenant.value.name,
            href: dashboard({ tenant: tenant.value.slug }),
        },
        { title: 'Pickup groups', href: index({ tenant: tenant.value.slug }) },
        {
            title: props.pickupGroup.name,
            href: edit({
                tenant: tenant.value.slug,
                pickupGroup: props.pickupGroup.id,
            }),
        },
    ],
});
</script>

<template>
    <Head :title="`Pickup group ${pickupGroup.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            :title="`Pickup group ${pickupGroup.name}`"
            description="Saving writes the group to the PBX again."
        />

        <Card>
            <CardContent class="pt-6">
                <PickupGroupForm
                    :action="
                        PickupGroupController.update({
                            tenant: tenant.slug,
                            pickupGroup: pickupGroup.id,
                        })
                    "
                    :initial="{
                        name: pickupGroup.name,
                        members: [...pickupGroup.member_ids],
                        version: pickupGroup.version,
                    }"
                    :extensions="extensions"
                    :group-id="pickupGroup.id"
                    submit-label="Save"
                />
            </CardContent>
        </Card>
    </div>
</template>
