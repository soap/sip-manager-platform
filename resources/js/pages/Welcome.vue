<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    AudioLines,
    Building2,
    Cable,
    Database,
    GitBranch,
    Hand,
    Headset,
    ListTree,
    LockKeyhole,
    Network,
    PhoneCall,
    PlugZap,
    Scale,
    Server,
    ShieldCheck,
    Smartphone,
    UserCog,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

type Feature = {
    icon: Component;
    title: string;
    description: string;
    points: string[];
    isAvailable: boolean;
};

type FeatureGroup = {
    label: string;
    title: string;
    features: Feature[];
};

const page = usePage();

const featureGroups: FeatureGroup[] = [
    {
        label: 'Carrier layer',
        title: 'Scale and connectivity',
        features: [
            {
                icon: Scale,
                title: 'Load balancing for Asterisk',
                description:
                    'OpenSIPS spreads tenants across a pool of Asterisk nodes, so one PBX never becomes the bottleneck.',
                points: [
                    'Tenant-aware routing to the node that owns it',
                    'Health checks and failover only to provisioned peers',
                    'Add nodes from SIP Deployer without downtime',
                ],
                isAvailable: true,
            },
            {
                icon: Cable,
                title: 'Shared trunk',
                description:
                    'One platform-grade carrier trunk serves every tenant, with DID routing and caller ID kept separate per tenant.',
                points: [
                    'Per-tenant DID ranges and outbound caller ID',
                    'Calling categories decide who may dial out',
                    'Usage attributed to each tenant in CDR',
                ],
                isAvailable: false,
            },
            {
                icon: PlugZap,
                title: 'Bring your own trunk',
                description:
                    'Tenants plug in their own SIP provider and keep their existing numbers and rates.',
                points: [
                    'Registration or IP-authenticated trunks',
                    'Route outbound per profile: own trunk or shared',
                    'Credentials stay inside the tenant boundary',
                ],
                isAvailable: false,
            },
        ],
    },
    {
        label: 'Tenant PBX',
        title: 'Everything an office phone system needs',
        features: [
            {
                icon: Users,
                title: 'Ring groups',
                description:
                    'Ring several extensions at once or in order, then fall back to voicemail or another destination.',
                points: [
                    'Ring-all and hunt strategies',
                    'Timeout and fallback destination',
                ],
                isAvailable: true,
            },
            {
                icon: Hand,
                title: 'Pickup groups',
                description:
                    'Answer a colleague’s ringing phone from your own desk with a feature code.',
                points: [
                    'Group-wide and directed pickup',
                    'Scoped to the tenant only',
                ],
                isAvailable: false,
            },
            {
                icon: UserCog,
                title: 'Calling profiles',
                description:
                    'Decide who may call where: internal, other tenants, national, mobile or international.',
                points: [
                    'Allow, deny or require an authorization code',
                    'Cross-tenant calling with inbound allowlists',
                ],
                isAvailable: true,
            },
        ],
    },
    {
        label: 'Customer experience',
        title: 'Answer every call the right way',
        features: [
            {
                icon: ListTree,
                title: 'IVR',
                description:
                    'Multi-level voice menus with prompts, business hours and DTMF routing to any destination.',
                points: [
                    'Time conditions and holiday schedules',
                    'Route to extension, ring group, queue or voicemail',
                    'Upload or record prompts per tenant',
                ],
                isAvailable: false,
            },
            {
                icon: Headset,
                title: 'Contact center',
                description:
                    'Queues, agents and live monitoring for teams that handle high call volumes.',
                points: [
                    'Queue strategies, wrap-up time and announcements',
                    'Agent login and pause from the phone or web',
                    'Real-time wallboard over AMI events and CDR',
                ],
                isAvailable: false,
            },
        ],
    },
];

const principles = [
    {
        icon: Building2,
        title: 'Domain per tenant',
        description:
            'Every tenant registers on its own SIP domain, so 1001@acme and 1001@beta never collide.',
    },
    {
        icon: LockKeyhole,
        title: 'Fail closed',
        description:
            'If the tenant, policy or route cannot be confirmed, the new call is rejected.',
    },
    {
        icon: ShieldCheck,
        title: 'No direct dialling',
        description:
            'Clients can never choose an Asterisk IP or port; every call goes through trusted routing data.',
    },
    {
        icon: GitBranch,
        title: 'Versioned provisioning',
        description:
            'Desired state lives in the control plane and is pushed through an outbox, then read back for verification.',
    },
];
</script>

<template>
    <Head title="Multi-tenant cloud PBX" />
    <div class="min-h-screen bg-background text-foreground">
        <header
            class="sticky top-0 z-20 border-b border-border/60 bg-background/80 backdrop-blur"
        >
            <nav
                class="mx-auto flex h-16 max-w-6xl items-center gap-6 px-4 sm:px-6"
            >
                <div class="flex items-center">
                    <AppLogo />
                </div>
                <div
                    class="hidden items-center gap-6 text-sm text-muted-foreground md:flex"
                >
                    <a href="#features" class="hover:text-foreground"
                        >Features</a
                    >
                    <a href="#architecture" class="hover:text-foreground"
                        >Architecture</a
                    >
                    <a href="#principles" class="hover:text-foreground"
                        >Security</a
                    >
                </div>
                <div class="ml-auto">
                    <Button v-if="page.props.auth.user" as-child size="sm">
                        <Link :href="dashboard()">Dashboard</Link>
                    </Button>
                    <Button v-else as-child size="sm" variant="outline">
                        <Link :href="login()">Log in</Link>
                    </Button>
                </div>
            </nav>
        </header>

        <main>
            <section class="relative overflow-hidden">
                <div
                    class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,var(--color-muted),transparent_65%)]"
                />
                <div
                    class="relative mx-auto max-w-6xl px-4 pt-20 pb-16 sm:px-6 lg:pt-28"
                >
                    <Badge variant="secondary" class="mb-6">
                        OpenSIPS 3.6 · Asterisk · RTPengine
                    </Badge>
                    <h1
                        class="max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl lg:text-6xl"
                    >
                        One voice platform for every tenant you serve.
                    </h1>
                    <p
                        class="mt-6 max-w-2xl text-lg text-pretty text-muted-foreground"
                    >
                        {{ page.props.name }} runs a multi-tenant cloud PBX on a
                        load-balanced Asterisk pool: shared or bring-your-own
                        trunks, ring groups, calling permissions, IVR and
                        contact center, managed from a single control plane.
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <Button v-if="page.props.auth.user" as-child size="lg">
                            <Link :href="dashboard()">
                                Open dashboard
                                <ArrowRight />
                            </Link>
                        </Button>
                        <Button v-else as-child size="lg">
                            <Link :href="login()">
                                Log in
                                <ArrowRight />
                            </Link>
                        </Button>
                        <Button as-child size="lg" variant="outline">
                            <a href="#architecture">See the architecture</a>
                        </Button>
                    </div>

                    <dl
                        class="mt-16 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-4"
                    >
                        <div class="bg-background p-5">
                            <dt class="text-sm text-muted-foreground">
                                Tenancy
                            </dt>
                            <dd class="mt-1 font-medium">SIP domain each</dd>
                        </div>
                        <div class="bg-background p-5">
                            <dt class="text-sm text-muted-foreground">
                                PBX nodes
                            </dt>
                            <dd class="mt-1 font-medium">Horizontal pool</dd>
                        </div>
                        <div class="bg-background p-5">
                            <dt class="text-sm text-muted-foreground">
                                Trunks
                            </dt>
                            <dd class="mt-1 font-medium">Shared or BYO</dd>
                        </div>
                        <div class="bg-background p-5">
                            <dt class="text-sm text-muted-foreground">
                                Automation
                            </dt>
                            <dd class="mt-1 font-medium">Web UI + API v1</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <section
                id="features"
                class="mx-auto max-w-6xl scroll-mt-20 px-4 py-20 sm:px-6"
            >
                <div class="max-w-2xl">
                    <p class="text-sm font-medium text-muted-foreground">
                        Features
                    </p>
                    <h2
                        class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
                    >
                        From the carrier edge to the agent’s headset
                    </h2>
                </div>

                <div class="mt-14 space-y-16">
                    <div v-for="group in featureGroups" :key="group.label">
                        <div class="mb-6 flex items-baseline gap-3">
                            <span
                                class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                                >{{ group.label }}</span
                            >
                            <h3 class="text-lg font-semibold">
                                {{ group.title }}
                            </h3>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                            <article
                                v-for="feature in group.features"
                                :key="feature.title"
                                class="flex flex-col rounded-xl border border-border bg-card p-6 text-card-foreground transition-colors hover:border-foreground/20"
                            >
                                <div class="flex items-start justify-between">
                                    <div
                                        class="flex size-10 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                                    >
                                        <component
                                            :is="feature.icon"
                                            class="size-5"
                                        />
                                    </div>
                                    <Badge
                                        v-if="!feature.isAvailable"
                                        variant="outline"
                                        class="text-muted-foreground"
                                    >
                                        Coming soon
                                    </Badge>
                                </div>
                                <h4 class="mt-5 font-semibold">
                                    {{ feature.title }}
                                </h4>
                                <p class="mt-2 text-sm text-muted-foreground">
                                    {{ feature.description }}
                                </p>
                                <ul class="mt-4 space-y-2 text-sm">
                                    <li
                                        v-for="point in feature.points"
                                        :key="point"
                                        class="flex gap-2"
                                    >
                                        <span
                                            class="mt-2 size-1.5 shrink-0 rounded-full bg-foreground/40"
                                        />
                                        <span>{{ point }}</span>
                                    </li>
                                </ul>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="architecture"
                class="scroll-mt-16 border-y border-border bg-muted/40"
            >
                <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                    <div class="max-w-2xl">
                        <p class="text-sm font-medium text-muted-foreground">
                            Architecture
                        </p>
                        <h2
                            class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
                        >
                            Control plane and data plane, kept apart
                        </h2>
                        <p class="mt-4 text-muted-foreground">
                            The management app owns tenants, numbers and
                            policies and provisions them ahead of time. OpenSIPS
                            and Asterisk handle every SIP message at low latency
                            without calling back into the app.
                        </p>
                    </div>

                    <div class="mt-12 grid gap-6 lg:grid-cols-[1fr_16rem]">
                        <div class="space-y-3">
                            <div
                                class="rounded-xl border border-border bg-background p-5"
                            >
                                <div
                                    class="flex items-center gap-2 text-sm font-medium"
                                >
                                    <Smartphone class="size-4" />
                                    Endpoints
                                </div>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    IP phones, softphones and WebRTC clients
                                    register to
                                    <code class="font-mono text-foreground"
                                        >tenant.your-domain</code
                                    >
                                </p>
                            </div>

                            <div
                                class="flex justify-center text-muted-foreground"
                            >
                                <span class="h-6 border-l border-dashed" />
                            </div>

                            <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                                <div
                                    class="rounded-xl border-2 border-foreground/80 bg-background p-5"
                                >
                                    <div
                                        class="flex items-center gap-2 text-sm font-medium"
                                    >
                                        <Network class="size-4" />
                                        OpenSIPS edge
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <Badge variant="secondary"
                                            >Registrar</Badge
                                        >
                                        <Badge variant="secondary"
                                            >Digest auth</Badge
                                        >
                                        <Badge variant="secondary"
                                            >Tenant routing</Badge
                                        >
                                        <Badge variant="secondary"
                                            >Load balancer</Badge
                                        >
                                        <Badge variant="secondary"
                                            >Failover</Badge
                                        >
                                    </div>
                                </div>
                                <div
                                    class="rounded-xl border border-border bg-background p-5 sm:w-48"
                                >
                                    <div
                                        class="flex items-center gap-2 text-sm font-medium"
                                    >
                                        <AudioLines class="size-4" />
                                        RTPengine
                                    </div>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        Media relay and NAT traversal
                                    </p>
                                </div>
                            </div>

                            <div
                                class="flex justify-center text-muted-foreground"
                            >
                                <span class="h-6 border-l border-dashed" />
                            </div>

                            <div
                                class="rounded-xl border border-border bg-background p-5"
                            >
                                <div
                                    class="flex items-center gap-2 text-sm font-medium"
                                >
                                    <Server class="size-4" />
                                    Asterisk pool
                                </div>
                                <div class="mt-3 grid gap-2 sm:grid-cols-3">
                                    <div
                                        v-for="node in ['A', 'B', 'C']"
                                        :key="node"
                                        class="rounded-lg border border-dashed border-border p-3 text-sm"
                                    >
                                        <div class="font-medium">
                                            Node {{ node }}
                                        </div>
                                        <div
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            Dialplan · ring groups · IVR ·
                                            queues · voicemail
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="flex justify-center text-muted-foreground"
                            >
                                <span class="h-6 border-l border-dashed" />
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div
                                    class="rounded-xl border border-border bg-background p-5"
                                >
                                    <div
                                        class="flex items-center gap-2 text-sm font-medium"
                                    >
                                        <Cable class="size-4" />
                                        Shared trunk
                                    </div>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        Platform carrier, DIDs split per tenant
                                    </p>
                                </div>
                                <div
                                    class="rounded-xl border border-border bg-background p-5"
                                >
                                    <div
                                        class="flex items-center gap-2 text-sm font-medium"
                                    >
                                        <PlugZap class="size-4" />
                                        Tenant trunks
                                    </div>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        Each tenant’s own SIP provider
                                    </p>
                                </div>
                            </div>
                            <div
                                class="flex items-center justify-center gap-2 pt-1 text-sm text-muted-foreground"
                            >
                                <PhoneCall class="size-4" />
                                PSTN and mobile networks
                            </div>
                        </div>

                        <aside
                            class="flex flex-col rounded-xl bg-primary p-5 text-primary-foreground"
                        >
                            <div
                                class="flex items-center gap-2 text-sm font-medium"
                            >
                                <Database class="size-4" />
                                Control plane
                            </div>
                            <p class="mt-1 text-sm opacity-80">
                                {{ page.props.name }}
                            </p>
                            <ul class="mt-5 space-y-3 text-sm">
                                <li>Tenants, domains and members</li>
                                <li>Extensions, ring groups and profiles</li>
                                <li>Node inventory from SIP Deployer</li>
                                <li>Outbox → provisioning queue</li>
                                <li>Read-back over AMI</li>
                                <li>CDR, events and audit log</li>
                            </ul>
                            <div
                                class="mt-auto border-t border-primary-foreground/20 pt-4 text-xs opacity-80"
                            >
                                Writes to OpenSIPS and Asterisk realtime
                                databases. Never in the live call path.
                            </div>
                        </aside>
                    </div>
                </div>
            </section>

            <section
                id="principles"
                class="mx-auto max-w-6xl scroll-mt-20 px-4 py-20 sm:px-6"
            >
                <div class="max-w-2xl">
                    <p class="text-sm font-medium text-muted-foreground">
                        Security by design
                    </p>
                    <h2
                        class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
                    >
                        Tenants share hardware, never data
                    </h2>
                </div>
                <div class="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="principle in principles" :key="principle.title">
                        <component
                            :is="principle.icon"
                            class="size-5 text-muted-foreground"
                        />
                        <h3 class="mt-3 font-semibold">
                            {{ principle.title }}
                        </h3>
                        <p class="mt-2 text-sm text-muted-foreground">
                            {{ principle.description }}
                        </p>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-border">
            <div
                class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6"
            >
                <span>{{ page.props.name }}</span>
                <span>Multi-tenant voice on OpenSIPS and Asterisk</span>
            </div>
        </footer>
    </div>
</template>
