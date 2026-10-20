<script setup lang="ts">
import type { UrlMethodPair } from '@inertiajs/core';
import { useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    FailoverType,
    RingGroupFormOptions,
    RingStrategy,
} from '@/types/telephony';

type RingGroupFields = {
    number?: string;
    name: string;
    strategy: RingStrategy;
    ring_seconds: number;
    members: number[];
    failover_type: FailoverType;
    failover_id: number | null;
    status?: 'active' | 'disabled';
    version?: number;
};

const props = defineProps<{
    action: UrlMethodPair;
    initial: RingGroupFields;
    options: RingGroupFormOptions;
    statuses?: { value: string; label: string }[];
    submitLabel: string;
}>();

const form = useForm<RingGroupFields>({ ...props.initial });
const creating = computed(() => props.initial.number !== undefined);

const extensionsById = computed(
    () =>
        new Map(
            props.options.extensions.map((extension) => [
                extension.id,
                extension,
            ]),
        ),
);

const addable = computed(() =>
    props.options.extensions
        .filter((extension) => !form.members.includes(extension.id))
        .map((extension) => ({
            value: String(extension.id),
            label: `${extension.number} · ${extension.name}${extension.status === 'disabled' ? ' (disabled)' : ''}`,
        })),
);

const toAdd = ref('');

function addMember(): void {
    const id = Number(toAdd.value || addable.value[0]?.value);

    if (id && !form.members.includes(id)) {
        form.members.push(id);
    }

    toAdd.value = '';
}

function move(index: number, by: -1 | 1): void {
    const [member] = form.members.splice(index, 1);
    form.members.splice(index + by, 0, member);
}

/** Failover as one select: "hangup", "extension:12" or "ring_group:3". */
const failover = computed({
    get: (): string =>
        form.failover_type === 'hangup'
            ? 'hangup'
            : `${form.failover_type}:${form.failover_id}`,
    set: (value: string): void => {
        const [type, id] = value.split(':');
        form.failover_type = type as FailoverType;
        form.failover_id = id ? Number(id) : null;
    },
});

const failoverOptions = computed(() => [
    { value: 'hangup', label: 'Hang up' },
    ...props.options.extensions.map((extension) => ({
        value: `extension:${extension.id}`,
        label: `Extension ${extension.number} · ${extension.name}`,
    })),
    ...props.options.extensions
        .filter((extension) => extension.voicemail)
        .map((extension) => ({
            value: `voicemail:${extension.id}`,
            label: `Voicemail of ${extension.number} · ${extension.name}`,
        })),
    ...props.options.failoverGroups.map((group) => ({
        value: `ring_group:${group.id}`,
        label: `Ring group ${group.number} · ${group.name}`,
    })),
]);

function submit(): void {
    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => {
            if (creating.value) {
                form.reset();
            }
        },
    });
}
</script>

<template>
    <form class="grid gap-4" @submit.prevent="submit">
        <div class="grid gap-4 md:grid-cols-[10rem_1fr]">
            <div v-if="creating" class="grid gap-2">
                <Label for="number">Number</Label>
                <Input
                    id="number"
                    v-model="form.number"
                    inputmode="numeric"
                    maxlength="5"
                    required
                />
                <InputError :message="form.errors.number" />
            </div>
            <div class="grid gap-2" :class="{ 'md:col-span-2': !creating }">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    placeholder="Sales"
                />
                <InputError :message="form.errors.name" />
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <div class="grid gap-2">
                <Label for="strategy">Ringing</Label>
                <NativeSelect
                    id="strategy"
                    v-model="form.strategy"
                    :options="options.strategies"
                />
                <InputError :message="form.errors.strategy" />
            </div>
            <div class="grid gap-2">
                <Label for="ring_seconds">{{
                    form.strategy === 'ring_all'
                        ? 'Ring time (seconds)'
                        : 'Ring time per member (seconds)'
                }}</Label>
                <Input
                    id="ring_seconds"
                    v-model.number="form.ring_seconds"
                    type="number"
                    :min="options.limits.min_seconds"
                    :max="options.limits.max_seconds"
                    required
                />
                <InputError :message="form.errors.ring_seconds" />
            </div>
            <div v-if="statuses" class="grid gap-2">
                <Label for="status">Status</Label>
                <NativeSelect
                    id="status"
                    v-model="form.status"
                    :options="statuses"
                />
                <InputError :message="form.errors.status" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label
                >Members ({{ form.members.length }} of up to
                {{ options.limits.members }})</Label
            >
            <ol
                v-if="form.members.length > 0"
                class="grid gap-1 rounded-lg border p-2 text-sm"
            >
                <li
                    v-for="(id, index) in form.members"
                    :key="id"
                    class="flex items-center gap-2"
                >
                    <span class="w-6 text-right text-muted-foreground"
                        >{{ index + 1 }}.</span
                    >
                    <span class="font-mono">{{
                        extensionsById.get(id)?.number
                    }}</span>
                    <span class="flex-1 truncate">{{
                        extensionsById.get(id)?.name
                    }}</span>
                    <span
                        v-if="extensionsById.get(id)?.status === 'disabled'"
                        class="text-xs text-muted-foreground"
                        >disabled: skipped</span
                    >
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        :disabled="index === 0"
                        aria-label="Move up"
                        @click="move(index, -1)"
                        ><ArrowUp class="size-4"
                    /></Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        :disabled="index === form.members.length - 1"
                        aria-label="Move down"
                        @click="move(index, 1)"
                        ><ArrowDown class="size-4"
                    /></Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label="Remove"
                        @click="form.members.splice(index, 1)"
                        ><X class="size-4"
                    /></Button>
                </li>
            </ol>
            <p v-else class="text-sm text-muted-foreground">
                No members yet: calls go straight to the failover.
            </p>
            <div
                v-if="
                    addable.length > 0 &&
                    form.members.length < options.limits.members
                "
                class="flex gap-2"
            >
                <NativeSelect
                    v-model="toAdd"
                    :options="addable"
                    aria-label="Extension to add"
                />
                <Button type="button" variant="outline" @click="addMember"
                    >Add</Button
                >
            </div>
            <InputError :message="form.errors.members" />
        </div>

        <div class="grid gap-2">
            <Label for="failover">When nobody answers</Label>
            <NativeSelect
                id="failover"
                v-model="failover"
                :options="failoverOptions"
            />
            <InputError :message="form.errors.failover_id" />
        </div>

        <InputError
            :message="(form.errors as Record<string, string>).ring_group"
        />

        <div>
            <Button :disabled="form.processing" data-test="save-ring-group">{{
                submitLabel
            }}</Button>
        </div>
    </form>
</template>
