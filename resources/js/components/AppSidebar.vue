<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    KeyRound,
    Phone,
    PhoneForwarded,
    PhoneOutgoing,
    LayoutGrid,
    History,
    ListChecks,
    ScrollText,
    Server,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as adminNodes } from '@/routes/admin/nodes';
import { index as adminOperations } from '@/routes/admin/operations';
import { index as adminTenants } from '@/routes/admin/tenants';
import { dashboard as tenantDashboard } from '@/routes/tenant';
import { index as apiTokens } from '@/routes/tenant/api-tokens';
import { index as auditLog } from '@/routes/tenant/audit-log';
import { index as calling } from '@/routes/tenant/calling';
import { index as calls } from '@/routes/tenant/calls';
import { index as extensions } from '@/routes/tenant/extensions';
import { index as ringGroups } from '@/routes/tenant/ring-groups';
import { index as members } from '@/routes/tenant/members';
import { index as operations } from '@/routes/tenant/operations';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    ...(page.props.auth.user.is_platform_admin
        ? [
              { title: 'All tenants', href: adminTenants(), icon: ShieldCheck },
              { title: 'SIP nodes', href: adminNodes(), icon: Server },
              {
                  title: 'Operations',
                  href: adminOperations(),
                  icon: ListChecks,
              },
          ]
        : []),
]);

const tenantNavItems = computed<NavItem[]>(() => {
    const tenant = page.props.tenant;

    if (!tenant) {
        return [];
    }

    return [
        {
            title: 'Overview',
            href: tenantDashboard({ tenant: tenant.slug }),
            icon: Building2,
        },
        {
            title: 'Members',
            href: members({ tenant: tenant.slug }),
            icon: Users,
        },
        {
            title: 'Extensions',
            href: extensions({ tenant: tenant.slug }),
            icon: Phone,
        },
        {
            title: 'Ring groups',
            href: ringGroups({ tenant: tenant.slug }),
            icon: PhoneForwarded,
        },
        {
            title: 'Calling permissions',
            href: calling({ tenant: tenant.slug }),
            icon: PhoneOutgoing,
        },
        {
            title: 'Call history',
            href: calls({ tenant: tenant.slug }),
            icon: History,
        },
        {
            title: 'Operations',
            href: operations({ tenant: tenant.slug }),
            icon: ListChecks,
        },
        ...(tenant.canManageMembers
            ? [
                  {
                      title: 'API tokens',
                      href: apiTokens({ tenant: tenant.slug }),
                      icon: KeyRound,
                  },
                  {
                      title: 'Audit log',
                      href: auditLog({ tenant: tenant.slug }),
                      icon: ScrollText,
                  },
              ]
            : []),
    ];
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="tenantNavItems.length > 0"
                :items="tenantNavItems"
                :label="page.props.tenant?.name"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
