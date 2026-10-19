<script setup lang="ts">
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes/tenant';
import { index as members } from '@/routes/tenant/members';

defineProps<{
    membersCount: number;
}>();

const page = usePage();
const tenant = computed(() => page.props.tenant!);

setLayoutProps({
    breadcrumbs: [
        {
            title: tenant.value.name,
            href: dashboard({ tenant: tenant.value.slug }),
        },
    ],
});
</script>

<template>
    <Head :title="tenant.name" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            :title="tenant.name"
            :description="`You are ${tenant.role ?? 'a platform admin'} here.`"
        />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Card>
                <CardHeader>
                    <CardDescription>Members</CardDescription>
                    <CardTitle class="text-3xl tabular-nums">{{
                        membersCount
                    }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <Link
                        :href="members({ tenant: tenant.slug })"
                        class="text-sm font-medium underline-offset-4 hover:underline"
                        >View members</Link
                    >
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Extensions</CardDescription>
                    <CardTitle class="text-base font-medium"
                        >Coming with provisioning</CardTitle
                    >
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    Extensions, devices and call records appear here once the
                    PBX node is connected.
                </CardContent>
            </Card>
        </div>
    </div>
</template>
