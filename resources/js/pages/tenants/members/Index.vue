<script setup lang="ts">
import { Form, Head, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import MemberController from '@/actions/App/Modules/Tenancy/Http/Controllers/MemberController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
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
import { dashboard } from '@/routes/tenant';
import { index } from '@/routes/tenant/members';
import type { Member, RoleOption, TenantRole } from '@/types';

const props = defineProps<{
    members: Member[];
    roles: RoleOption[];
    canManage: boolean;
}>();

const page = usePage();
const tenant = computed(() => page.props.tenant!);

setLayoutProps({
    breadcrumbs: [
        {
            title: tenant.value.name,
            href: dashboard({ tenant: tenant.value.slug }),
        },
        { title: 'Members', href: index({ tenant: tenant.value.slug }) },
    ],
});

const roleLabel = (role: TenantRole): string =>
    props.roles.find((option) => option.value === role)?.label ?? role;

function changeRole(member: Member, role: string): void {
    router.visit(
        MemberController.update({
            tenant: tenant.value.slug,
            membership: member.id,
        }),
        // a fresh page state puts the select back if the change is refused
        { data: { role }, preserveScroll: true, preserveState: false },
    );
}

function remove(member: Member): void {
    if (!confirm(`Remove ${member.name} from ${tenant.value.name}?`)) {
        return;
    }

    router.visit(
        MemberController.destroy({
            tenant: tenant.value.slug,
            membership: member.id,
        }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Members" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Members"
            description="People who can sign in to this tenant."
        />

        <Card v-if="canManage">
            <CardHeader>
                <CardTitle>Add member</CardTitle>
                <CardDescription>
                    New people get an email with a link to set their password.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="
                        MemberController.store.form({ tenant: tenant.slug })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    class="grid gap-4 md:grid-cols-[1fr_1fr_10rem_auto] md:items-end"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" name="name" required />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="email">Email</Label>
                        <Input id="email" name="email" type="email" required />
                        <InputError :message="errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="role">Role</Label>
                        <NativeSelect
                            id="role"
                            name="role"
                            :options="roles"
                            default-value="member"
                        />
                        <InputError :message="errors.role" />
                    </div>
                    <Button :disabled="processing" data-test="add-member-button"
                        >Add</Button
                    >
                </Form>
            </CardContent>
        </Card>

        <InputError
            :message="
                (page.props.errors as Record<string, string>).role ??
                (page.props.errors as Record<string, string>).member
            "
        />

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Email</th>
                        <th class="px-4 py-2 font-medium">Role</th>
                        <th v-if="canManage" class="px-4 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="member in members"
                        :key="member.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2 font-medium">{{ member.name }}</td>
                        <td class="px-4 py-2">{{ member.email }}</td>
                        <td class="px-4 py-2">
                            <NativeSelect
                                v-if="canManage"
                                :options="roles"
                                :model-value="member.role"
                                class="w-36"
                                :aria-label="`Role of ${member.name}`"
                                @update:model-value="
                                    (role) => role && changeRole(member, role)
                                "
                            />
                            <span v-else>{{ roleLabel(member.role) }}</span>
                        </td>
                        <td v-if="canManage" class="px-4 py-2 text-right">
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-destructive"
                                @click="remove(member)"
                                >Remove</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
