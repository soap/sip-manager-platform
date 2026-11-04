<script setup lang="ts">
import type { UrlMethodPair } from '@inertiajs/core';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PickupExtensionOption } from '@/types/telephony';

type PickupGroupFields = {
    name: string;
    members: number[];
    version?: number;
};

const props = defineProps<{
    action: UrlMethodPair;
    initial: PickupGroupFields;
    extensions: PickupExtensionOption[];
    /** The group being edited; its own members are not "in another group". */
    groupId?: number;
    submitLabel: string;
}>();

const form = useForm<PickupGroupFields>({ ...props.initial });

const choices = computed(() =>
    props.extensions.map((extension) => ({
        ...extension,
        elsewhere:
            extension.pickup_group_id !== null &&
            extension.pickup_group_id !== props.groupId,
    })),
);

function toggle(id: number, checked: boolean): void {
    form.members = checked
        ? [...form.members, id]
        : form.members.filter((member) => member !== id);
}

function submit(): void {
    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => {
            if (props.groupId === undefined) {
                form.reset();
            }
        },
    });
}
</script>

<template>
    <form class="grid gap-4" @submit.prevent="submit">
        <div class="grid gap-2 md:max-w-sm">
            <Label for="name">Name</Label>
            <Input id="name" v-model="form.name" required placeholder="Sales" />
            <InputError :message="form.errors.name" />
        </div>

        <div class="grid gap-2">
            <Label>Members ({{ form.members.length }})</Label>
            <p
                v-if="choices.length === 0"
                class="text-sm text-muted-foreground"
            >
                This tenant has no extensions yet.
            </p>
            <div
                v-else
                class="grid gap-1 rounded-lg border p-2 text-sm sm:grid-cols-2 lg:grid-cols-3"
            >
                <label
                    v-for="extension in choices"
                    :key="extension.id"
                    class="flex items-center gap-2 rounded px-2 py-1"
                    :class="
                        extension.elsewhere
                            ? 'cursor-not-allowed opacity-60'
                            : 'cursor-pointer hover:bg-muted/50'
                    "
                >
                    <Checkbox
                        :model-value="form.members.includes(extension.id)"
                        :disabled="extension.elsewhere"
                        @update:model-value="
                            toggle(extension.id, $event === true)
                        "
                    />
                    <span class="font-mono">{{ extension.number }}</span>
                    <span class="flex-1 truncate">{{ extension.name }}</span>
                    <span
                        v-if="extension.elsewhere"
                        class="text-xs text-muted-foreground"
                        >in {{ extension.pickup_group }}</span
                    >
                    <span
                        v-else-if="extension.status === 'disabled'"
                        class="text-xs text-muted-foreground"
                        >disabled</span
                    >
                </label>
            </div>
            <p class="text-xs text-muted-foreground">
                An extension can be in one pickup group only.
            </p>
            <InputError :message="form.errors.members" />
        </div>

        <InputError
            :message="(form.errors as Record<string, string>).pickup_group"
        />

        <div>
            <Button :disabled="form.processing" data-test="save-pickup-group">{{
                submitLabel
            }}</Button>
        </div>
    </form>
</template>
