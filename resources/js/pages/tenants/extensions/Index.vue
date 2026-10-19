<script setup lang="ts">
import { Form, Head, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ExtensionController from '@/actions/App/Modules/Telephony/Http/Controllers/ExtensionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes/tenant';
import { index } from '@/routes/tenant/extensions';

type ExtensionStatus = 'active' | 'disabled';

type Extension = {
    id: number;
    extension_number: string;
    display_name: string;
    status: ExtensionStatus;
};

defineProps<{
    assignment: {
        node: string;
        sip_domain: string | null;
        number_range_start: number;
        number_range_end: number;
        status: 'active' | 'suspended';
    } | null;
    extensions: Extension[];
    nextNumber: string | null;
    statuses: { value: ExtensionStatus; label: string }[];
    canManage: boolean;
}>();

const page = usePage();
const tenant = computed(() => page.props.tenant!);
const errors = computed(() => page.props.errors as Record<string, string>);

setLayoutProps({
    breadcrumbs: [
        {
            title: tenant.value.name,
            href: dashboard({ tenant: tenant.value.slug }),
        },
        { title: 'Extensions', href: index({ tenant: tenant.value.slug }) },
    ],
});

function update(extension: Extension, changes: Partial<Extension>): void {
    router.visit(
        ExtensionController.update({
            tenant: tenant.value.slug,
            extension: extension.id,
        }),
        {
            data: {
                display_name: extension.display_name,
                status: extension.status,
                ...changes,
            },
            preserveScroll: true,
            preserveState: false,
        },
    );
}

function rename(extension: Extension): void {
    const name = prompt(
        `Name of extension ${extension.extension_number}`,
        extension.display_name,
    );

    if (name && name !== extension.display_name) {
        update(extension, { display_name: name });
    }
}

function remove(extension: Extension): void {
    if (
        !confirm(
            `Delete extension ${extension.extension_number} (${extension.display_name})?`,
        )
    ) {
        return;
    }

    router.visit(
        ExtensionController.destroy({
            tenant: tenant.value.slug,
            extension: extension.id,
        }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Extensions" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Extensions"
            description="Internal numbers of this tenant. Each one becomes a SIP account on the tenant's PBX node."
        />

        <Alert v-if="!assignment">
            <AlertTitle>No PBX node yet</AlertTitle>
            <AlertDescription>
                A platform admin places this tenant on a node and gives it a
                block of numbers before extensions can be created.
            </AlertDescription>
        </Alert>

        <div
            v-else
            class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-muted-foreground"
        >
            <span
                >Node
                <span class="font-medium text-foreground">{{
                    assignment.node
                }}</span></span
            >
            <span
                >SIP domain
                <span class="font-mono text-foreground">{{
                    assignment.sip_domain
                }}</span></span
            >
            <span
                >Numbers
                <span class="font-mono text-foreground"
                    >{{ assignment.number_range_start }}–{{
                        assignment.number_range_end
                    }}</span
                ></span
            >
            <Badge
                v-if="assignment.status === 'suspended'"
                variant="destructive"
                >Suspended</Badge
            >
        </div>

        <Card v-if="canManage && assignment">
            <CardHeader>
                <CardTitle>New extension</CardTitle>
                <CardDescription>
                    The number cannot change later; phones register with it.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="
                        ExtensionController.store.form({
                            tenant: tenant.slug,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    class="grid gap-4 md:grid-cols-[10rem_1fr_auto] md:items-end"
                    v-slot="{ errors: formErrors, processing }"
                >
                    <div class="grid gap-2">
                        <Label for="extension_number">Number</Label>
                        <Input
                            id="extension_number"
                            name="extension_number"
                            inputmode="numeric"
                            required
                            :default-value="nextNumber ?? ''"
                            :placeholder="String(assignment.number_range_start)"
                        />
                        <InputError :message="formErrors.extension_number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="display_name">Name</Label>
                        <Input
                            id="display_name"
                            name="display_name"
                            required
                            placeholder="Reception"
                        />
                        <InputError :message="formErrors.display_name" />
                    </div>
                    <Button
                        :disabled="processing || assignment.status !== 'active'"
                        data-test="create-extension-button"
                        >Create</Button
                    >
                </Form>
            </CardContent>
        </Card>

        <InputError :message="errors.display_name ?? errors.status" />

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Number</th>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th v-if="canManage" class="px-4 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="extension in extensions"
                        :key="extension.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2 font-mono font-medium">
                            {{ extension.extension_number }}
                        </td>
                        <td class="px-4 py-2">{{ extension.display_name }}</td>
                        <td class="px-4 py-2">
                            <NativeSelect
                                v-if="canManage"
                                class="w-32"
                                :options="statuses"
                                :model-value="extension.status"
                                @update:model-value="
                                    (status) =>
                                        update(extension, {
                                            status: status as ExtensionStatus,
                                        })
                                "
                            />
                            <Badge
                                v-else
                                :variant="
                                    extension.status === 'active'
                                        ? 'secondary'
                                        : 'outline'
                                "
                                class="capitalize"
                                >{{ extension.status }}</Badge
                            >
                        </td>
                        <td v-if="canManage" class="px-4 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="rename(extension)"
                                    >Rename</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="text-destructive"
                                    @click="remove(extension)"
                                    >Delete</Button
                                >
                            </div>
                        </td>
                    </tr>
                    <tr v-if="extensions.length === 0">
                        <td
                            :colspan="canManage ? 4 : 3"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No extensions yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
