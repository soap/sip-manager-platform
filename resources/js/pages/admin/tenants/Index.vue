<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import TenantController from '@/actions/App/Modules/Tenancy/Http/Controllers/Admin/TenantController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/admin/tenants';
import { dashboard as tenantDashboard } from '@/routes/tenant';
import type { TenantStatus } from '@/types';

defineProps<{
    tenants: {
        id: string;
        name: string;
        slug: string;
        status: TenantStatus;
        members_count: number;
        created_at: string;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tenants', href: index() }],
    },
});
</script>

<template>
    <Head title="Tenants" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Tenants"
            description="Every customer of the platform. Only platform admins see this page."
        />

        <Card>
            <CardHeader>
                <CardTitle>New tenant</CardTitle>
                <CardDescription>
                    The owner gets an email with a link to set a password, or is
                    added as owner if they already have an account.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="TenantController.store.form()"
                    reset-on-success
                    class="grid gap-4 md:grid-cols-2"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            name="name"
                            required
                            placeholder="Demo Company"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="slug">Slug</Label>
                        <Input
                            id="slug"
                            name="slug"
                            required
                            placeholder="demo-company"
                        />
                        <InputError :message="errors.slug" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="owner_name">Owner name</Label>
                        <Input id="owner_name" name="owner_name" required />
                        <InputError :message="errors.owner_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="owner_email">Owner email</Label>
                        <Input
                            id="owner_email"
                            name="owner_email"
                            type="email"
                            required
                        />
                        <InputError :message="errors.owner_email" />
                    </div>
                    <div class="md:col-span-2">
                        <Button
                            :disabled="processing"
                            data-test="create-tenant-button"
                            >Create tenant</Button
                        >
                    </div>
                </Form>
            </CardContent>
        </Card>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Slug</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Members
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="tenant in tenants"
                        :key="tenant.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">
                            <Link
                                :href="tenantDashboard({ tenant: tenant.slug })"
                                class="font-medium underline-offset-4 hover:underline"
                                >{{ tenant.name }}</Link
                            >
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">
                            {{ tenant.slug }}
                        </td>
                        <td class="px-4 py-2">
                            <Badge
                                :variant="
                                    tenant.status === 'active'
                                        ? 'secondary'
                                        : 'destructive'
                                "
                                class="capitalize"
                                >{{ tenant.status }}</Badge
                            >
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ tenant.members_count }}
                        </td>
                    </tr>
                    <tr v-if="tenants.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No tenants yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
