<script setup lang="ts">
import {
    Head,
    Link,
    router,
    setLayoutProps,
    usePage,
    usePoll,
} from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import PickupGroupController from '@/actions/App/Modules/Telephony/Http/Controllers/PickupGroupController';
import Heading from '@/components/Heading.vue';
import PickupGroupForm from '@/components/telephony/PickupGroupForm.vue';
import ProvisioningBadge from '@/components/telephony/ProvisioningBadge.vue';
import ProvisioningStalledAlert from '@/components/telephony/ProvisioningStalledAlert.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes/tenant';
import { edit, index } from '@/routes/tenant/pickup-groups';
import type {
    PickupExtensionOption,
    ProvisioningStall,
    ProvisioningState,
} from '@/types/telephony';
import { isInProgress } from '@/types/telephony';

type PickupGroup = {
    id: number;
    name: string;
    version: number;
    members: { number: string; name: string }[];
    provisioning: ProvisioningState | null;
};

const props = defineProps<{
    pickupGroups: PickupGroup[];
    extensions: PickupExtensionOption[];
    featureCode: string;
    hasNode: boolean;
    canManage: boolean;
    provisioningStalled: ProvisioningStall | null;
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
    ],
});

const { start, stop } = usePoll(
    3000,
    { only: ['pickupGroups', 'provisioningStalled'] },
    { autoStart: false },
);

watch(
    () => isInProgress(props.pickupGroups.map((group) => group.provisioning)),
    (busy) => (busy ? start() : stop()),
    { immediate: true },
);

function reapply(group: PickupGroup): void {
    router.visit(
        PickupGroupController.reapply({
            tenant: tenant.value.slug,
            pickupGroup: group.id,
        }),
        { preserveScroll: true },
    );
}

function remove(group: PickupGroup): void {
    if (!confirm(`Delete pickup group ${group.name}?`)) {
        return;
    }

    router.visit(
        PickupGroupController.destroy({
            tenant: tenant.value.slug,
            pickupGroup: group.id,
        }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Pickup groups" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Pickup groups"
            :description="`Extensions that can answer each other's ringing phones by dialing ${featureCode}.`"
        />

        <ProvisioningStalledAlert
            v-if="provisioningStalled"
            :stall="provisioningStalled"
        />

        <Alert v-if="!hasNode">
            <AlertTitle>No PBX node yet</AlertTitle>
            <AlertDescription>
                A platform admin places this tenant on a node before pickup
                groups can be created.
            </AlertDescription>
        </Alert>

        <Card v-if="canManage && hasNode">
            <CardHeader>
                <CardTitle>New pickup group</CardTitle>
                <CardDescription>
                    When a member's phone rings, any other member dials
                    <span class="font-mono">{{ featureCode }}</span> to answer
                    it. Calls to a ring group with a member in the group can be
                    picked up too.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <PickupGroupForm
                    :action="
                        PickupGroupController.store({ tenant: tenant.slug })
                    "
                    :initial="{ name: '', members: [] }"
                    :extensions="extensions"
                    submit-label="Create"
                />
            </CardContent>
        </Card>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Members</th>
                        <th class="px-4 py-2 font-medium">PBX</th>
                        <th v-if="canManage" class="px-4 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="group in pickupGroups"
                        :key="group.id"
                        class="border-t align-top"
                    >
                        <td class="px-4 py-2 font-medium">{{ group.name }}</td>
                        <td class="px-4 py-2 text-xs">
                            <span
                                v-if="group.members.length === 0"
                                class="text-muted-foreground"
                                >none</span
                            >
                            <span
                                v-for="member in group.members"
                                :key="member.number"
                                class="mr-2 inline-block font-mono"
                                :title="member.name"
                                >{{ member.number }}</span
                            >
                        </td>
                        <td class="px-4 py-2">
                            <ProvisioningBadge
                                :provisioning="group.provisioning"
                            />
                        </td>
                        <td v-if="canManage" class="px-4 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button
                                    v-if="
                                        group.provisioning?.status === 'failed'
                                    "
                                    size="sm"
                                    variant="outline"
                                    @click="reapply(group)"
                                    >Retry</Button
                                >
                                <Button size="sm" variant="outline" as-child>
                                    <Link
                                        :href="
                                            edit({
                                                tenant: tenant.slug,
                                                pickupGroup: group.id,
                                            })
                                        "
                                        >Edit</Link
                                    >
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="text-destructive"
                                    @click="remove(group)"
                                    >Delete</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="pickupGroups.length === 0">
                        <td
                            :colspan="canManage ? 4 : 3"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No pickup groups yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
