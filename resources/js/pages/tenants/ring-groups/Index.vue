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
import RingGroupController from '@/actions/App/Modules/Telephony/Http/Controllers/RingGroupController';
import Heading from '@/components/Heading.vue';
import ProvisioningBadge from '@/components/telephony/ProvisioningBadge.vue';
import ProvisioningStalledAlert from '@/components/telephony/ProvisioningStalledAlert.vue';
import RingGroupForm from '@/components/telephony/RingGroupForm.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes/tenant';
import { edit, index } from '@/routes/tenant/ring-groups';
import type {
    ProvisioningStall,
    ProvisioningState,
    RingGroupFormOptions,
    RingStrategy,
} from '@/types/telephony';
import { isInProgress } from '@/types/telephony';

type RingGroup = {
    id: number;
    number: string;
    name: string;
    strategy: RingStrategy;
    ring_seconds: number;
    status: 'active' | 'disabled';
    version: number;
    members: { number: string; name: string }[];
    failover: string;
    provisioning: ProvisioningState | null;
};

const props = defineProps<
    RingGroupFormOptions & {
        ringGroups: RingGroup[];
        hasNode: boolean;
        nextNumber: string | null;
        canManage: boolean;
        provisioningStalled: ProvisioningStall | null;
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
    ],
});

const options = computed<RingGroupFormOptions>(() => ({
    extensions: props.extensions,
    failoverGroups: props.failoverGroups,
    strategies: props.strategies,
    limits: props.limits,
}));

const strategyLabels = computed(
    () =>
        Object.fromEntries(
            props.strategies.map((strategy) => [
                strategy.value,
                strategy.label,
            ]),
        ) as Record<RingStrategy, string>,
);

const { start, stop } = usePoll(
    3000,
    { only: ['ringGroups', 'provisioningStalled'] },
    { autoStart: false },
);

watch(
    () => isInProgress(props.ringGroups.map((group) => group.provisioning)),
    (busy) => (busy ? start() : stop()),
    { immediate: true },
);

function reapply(group: RingGroup): void {
    router.visit(
        RingGroupController.reapply({
            tenant: tenant.value.slug,
            ringGroup: group.id,
        }),
        { preserveScroll: true },
    );
}

function remove(group: RingGroup): void {
    if (!confirm(`Delete ring group ${group.number} (${group.name})?`)) {
        return;
    }

    router.visit(
        RingGroupController.destroy({
            tenant: tenant.value.slug,
            ringGroup: group.id,
        }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Ring groups" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Ring groups"
            description="A number that rings several extensions of this tenant, all at once or one after another."
        />

        <ProvisioningStalledAlert
            v-if="provisioningStalled"
            :stall="provisioningStalled"
        />

        <Alert v-if="!hasNode">
            <AlertTitle>No PBX node yet</AlertTitle>
            <AlertDescription>
                A platform admin places this tenant on a node before ring groups
                can be created.
            </AlertDescription>
        </Alert>

        <Card v-if="canManage && hasNode">
            <CardHeader>
                <CardTitle>New ring group</CardTitle>
                <CardDescription>
                    A number of the tenant, like an extension; ring groups
                    usually take the 500s.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <RingGroupForm
                    :action="RingGroupController.store({ tenant: tenant.slug })"
                    :initial="{
                        number: nextNumber ?? '',
                        name: '',
                        strategy: 'ring_all',
                        ring_seconds: 20,
                        members: [],
                        failover_type: 'hangup',
                        failover_id: null,
                    }"
                    :options="options"
                    submit-label="Create"
                />
            </CardContent>
        </Card>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Number</th>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Members</th>
                        <th class="px-4 py-2 font-medium">No answer</th>
                        <th class="px-4 py-2 font-medium">PBX</th>
                        <th v-if="canManage" class="px-4 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="group in ringGroups"
                        :key="group.id"
                        class="border-t align-top"
                    >
                        <td class="px-4 py-2 font-mono font-medium">
                            {{ group.number }}
                        </td>
                        <td class="px-4 py-2">
                            <div>{{ group.name }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ strategyLabels[group.strategy] }},
                                {{ group.ring_seconds }} s
                            </div>
                            <Badge
                                v-if="group.status === 'disabled'"
                                variant="outline"
                                class="mt-1"
                                >Disabled</Badge
                            >
                        </td>
                        <td class="px-4 py-2 text-xs">
                            <span
                                v-if="group.members.length === 0"
                                class="text-muted-foreground"
                                >none</span
                            >
                            <span
                                v-for="(member, position) in group.members"
                                :key="member.number"
                                class="mr-2 inline-block"
                                :title="member.name"
                                ><span
                                    v-if="group.strategy === 'sequential'"
                                    class="text-muted-foreground"
                                    >{{ position + 1 }}.</span
                                >
                                <span class="font-mono">{{
                                    member.number
                                }}</span></span
                            >
                        </td>
                        <td class="px-4 py-2 text-xs">{{ group.failover }}</td>
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
                                                ringGroup: group.id,
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
                    <tr v-if="ringGroups.length === 0">
                        <td
                            :colspan="canManage ? 6 : 5"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No ring groups yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
