<script setup lang="ts">
import { Form, Head, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ApiTokenController from '@/actions/App/Modules/Tenancy/Http/Controllers/ApiTokenController';
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
import { index } from '@/routes/tenant/api-tokens';
import type { ApiAbilityOption, ApiTokenSummary } from '@/types';

const props = defineProps<{
    tokens: ApiTokenSummary[];
    abilities: ApiAbilityOption[];
    expiryDays: number[];
    apiBaseUrl: string;
}>();

const page = usePage();
const tenant = computed(() => page.props.tenant!);

setLayoutProps({
    breadcrumbs: [
        {
            title: tenant.value.name,
            href: dashboard({ tenant: tenant.value.slug }),
        },
        { title: 'API tokens', href: index({ tenant: tenant.value.slug }) },
    ],
});

const expiryOptions = computed(() => [
    { value: '', label: 'Never' },
    ...props.expiryDays.map((days) => ({
        value: String(days),
        label: `${days} days`,
    })),
]);

const formatter = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const formatDate = (value: string | null, empty: string): string =>
    value ? formatter.format(new Date(value)) : empty;

type IssuedToken = { name: string; token: string };

/** The plain token arrives once, as flash data; only its hash is stored. */
const issued = ref<IssuedToken | null>(null);

watch(
    () => (page.flash as { apiToken?: IssuedToken }).apiToken,
    (token) => {
        if (token) {
            issued.value = token;
        }
    },
    { immediate: true },
);

function revoke(token: ApiTokenSummary): void {
    if (
        !confirm(
            `Revoke "${token.name}"? Clients using it stop working at once.`,
        )
    ) {
        return;
    }

    router.visit(
        ApiTokenController.destroy({
            tenant: tenant.value.slug,
            token: token.id,
        }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="API tokens" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="API tokens"
            description="Let other systems read and change this tenant's extensions through the API. A token acts as the person who created it, with their role."
        />

        <Card v-if="issued" class="border-amber-500/60">
            <CardHeader>
                <CardTitle>Token "{{ issued.name }}"</CardTitle>
                <CardDescription>
                    Copy it now. It is shown only this once; create a new one if
                    it gets lost.
                </CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4">
                <p
                    class="font-mono text-sm break-all select-all"
                    data-test="api-token"
                >
                    {{ issued.token }}
                </p>
                <p class="text-sm text-muted-foreground">
                    Send it as
                    <code class="font-mono"
                        >Authorization: Bearer &lt;token&gt;</code
                    >
                    to <code class="font-mono">{{ apiBaseUrl }}</code
                    >, e.g. <code class="font-mono">GET /extensions</code>.
                </p>
                <div>
                    <Button variant="outline" @click="issued = null"
                        >I have saved it</Button
                    >
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Create token</CardTitle>
                <CardDescription>
                    Read tokens can only look; write tokens can also create,
                    change and delete extensions.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="
                        ApiTokenController.store.form({ tenant: tenant.slug })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    class="grid gap-4 md:grid-cols-[1fr_10rem_10rem_auto] md:items-end"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            name="name"
                            placeholder="e.g. CRM"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ability">Access</Label>
                        <NativeSelect
                            id="ability"
                            name="ability"
                            :options="abilities"
                            default-value="read"
                        />
                        <InputError :message="errors.ability" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="expires_in_days">Expires</Label>
                        <NativeSelect
                            id="expires_in_days"
                            name="expires_in_days"
                            :options="expiryOptions"
                            default-value=""
                        />
                        <InputError :message="errors.expires_in_days" />
                    </div>
                    <Button
                        :disabled="processing"
                        data-test="create-token-button"
                        >Create</Button
                    >
                </Form>
            </CardContent>
        </Card>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Access</th>
                        <th class="px-4 py-2 font-medium">Acts as</th>
                        <th class="px-4 py-2 font-medium">Last used</th>
                        <th class="px-4 py-2 font-medium">Expires</th>
                        <th class="px-4 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="tokens.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No API tokens yet.
                        </td>
                    </tr>
                    <tr
                        v-for="token in tokens"
                        :key="token.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2 font-medium">{{ token.name }}</td>
                        <td class="px-4 py-2">
                            {{
                                token.abilities.includes('write')
                                    ? 'Read and write'
                                    : 'Read'
                            }}
                        </td>
                        <td class="px-4 py-2">{{ token.user ?? '—' }}</td>
                        <td class="px-4 py-2">
                            {{ formatDate(token.last_used_at, 'Never') }}
                        </td>
                        <td class="px-4 py-2">
                            {{ formatDate(token.expires_at, 'Never') }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-destructive"
                                @click="revoke(token)"
                                >Revoke</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
