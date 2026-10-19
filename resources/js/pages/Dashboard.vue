<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Building2, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as adminTenants } from '@/routes/admin/tenants';
import { dashboard as tenantDashboard } from '@/routes/tenant';
import type { TenantRole, TenantStatus } from '@/types';

defineProps<{
    tenants: {
        id: string;
        name: string;
        slug: string;
        status: TenantStatus;
        role: TenantRole;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const page = usePage();
const isPlatformAdmin = computed(() => page.props.auth.user.is_platform_admin);
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Your tenants"
            description="Choose a tenant to manage its members and, later, its extensions."
        />

        <div
            v-if="tenants.length > 0"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <Link
                v-for="tenant in tenants"
                :key="tenant.id"
                :href="tenantDashboard({ tenant: tenant.slug })"
                class="rounded-xl focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            >
                <Card class="h-full transition-colors hover:bg-accent/50">
                    <CardHeader>
                        <div class="flex items-center gap-2">
                            <Building2 class="size-4 text-muted-foreground" />
                            <CardTitle>{{ tenant.name }}</CardTitle>
                        </div>
                        <CardDescription class="flex items-center gap-2">
                            <span class="capitalize">{{ tenant.role }}</span>
                            <Badge
                                v-if="tenant.status === 'suspended'"
                                variant="destructive"
                                >Suspended</Badge
                            >
                        </CardDescription>
                    </CardHeader>
                </Card>
            </Link>
        </div>

        <p v-else class="text-sm text-muted-foreground">
            You are not a member of any tenant yet. Ask the owner of your tenant
            to add you.
        </p>

        <Link
            v-if="isPlatformAdmin"
            :href="adminTenants()"
            class="inline-flex w-fit items-center gap-2 text-sm font-medium underline-offset-4 hover:underline"
        >
            <ShieldCheck class="size-4" />
            Manage all tenants
        </Link>
    </div>
</template>
