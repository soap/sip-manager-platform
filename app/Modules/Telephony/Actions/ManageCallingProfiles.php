<?php

namespace App\Modules\Telephony\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Provisioning\Actions\RequestProvisioning;
use App\Modules\Provisioning\Enums\OperationType;
use App\Modules\Provisioning\Models\ProvisioningOperation;
use App\Modules\Telephony\Enums\CallCategory;
use App\Modules\Telephony\Enums\CallPermission;
use App\Modules\Telephony\Enums\CrossTenantScope;
use App\Modules\Telephony\Exceptions\ProfileInUse;
use App\Modules\Telephony\Exceptions\StaleVersion;
use App\Modules\Telephony\Models\CallingProfile;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The calling profiles of the tenant in the context (plan D7). The node holds a profile as the
 * permission exten of each extension that uses it, so changing a profile writes those extensions
 * again; changing the default writes the extensions without a profile of their own.
 */
class ManageCallingProfiles
{
    public function __construct(
        private Audit $audit,
        private RequestProvisioning $provisioning,
        private TenantContext $context,
    ) {}

    /**
     * The profiles every new tenant starts with; "Internal only" is the default, so a new extension
     * calls nowhere outside the tenant until an admin says so.
     */
    public function createDefaults(Tenant $tenant): void
    {
        $code = CallPermission::Code->value;
        $allow = CallPermission::Allow->value;
        $domestic = [CallCategory::CrossTenant, CallCategory::Landline, CallCategory::Mobile, CallCategory::TollFree, CallCategory::Hotline];
        $every = fn (array $categories, string $permission): array => array_fill_keys(array_map(fn (CallCategory $category): string => $category->value, $categories), $permission);

        foreach ([
            ['Internal only', [], true],
            ['Internal, others with a code', $every($domestic, $code), false],
            ['Other tenants', $every([CallCategory::CrossTenant], $allow), false],
            ['Domestic', [...$every($domestic, $allow), CallCategory::International->value => $code], false],
            ['Everything', $every(CallCategory::cases(), $allow), false],
        ] as [$name, $categories, $isDefault]) {
            CallingProfile::withoutTenantScope()->create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'categories' => $categories,
                'is_default' => $isDefault,
            ]);
        }
    }

    /**
     * @param  array<string, CallPermission>  $categories  category value => permission
     * @param  list<string>  $destinationCodes  tenant codes, for the "selected" scope
     */
    public function create(string $name, array $categories, CrossTenantScope $scope, array $destinationCodes): CallingProfile
    {
        try {
            return DB::transaction(function () use ($name, $categories, $scope, $destinationCodes): CallingProfile {
                $profile = CallingProfile::query()->create([
                    'name' => $name,
                    'categories' => $this->stored($categories),
                    'cross_tenant_scope' => $scope,
                ]);
                $profile->destinations()->sync($this->destinationIds($scope, $destinationCodes));

                $this->audit->record('calling_profile.created', $profile, ['name' => $name, ...$this->summary($profile)]);

                return $profile;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Another profile has this name.']);
        }
    }

    /**
     * With a version, refuses a change made against an older copy (optimistic lock).
     *
     * @param  array<string, CallPermission>  $categories
     * @param  list<string>  $destinationCodes
     * @return list<ProvisioningOperation> one for each extension the change reaches
     *
     * @throws StaleVersion
     */
    public function update(CallingProfile $profile, string $name, array $categories, CrossTenantScope $scope, array $destinationCodes, ?int $expectedVersion = null): array
    {
        $before = $this->summary($profile);

        try {
            return DB::transaction(function () use ($profile, $name, $categories, $scope, $destinationCodes, $expectedVersion, $before): array {
                $updated = CallingProfile::query()
                    ->whereKey($profile->id)
                    ->when($expectedVersion !== null, fn ($query) => $query->where('version', $expectedVersion))
                    ->update([
                        'name' => $name,
                        'categories' => json_encode($this->stored($categories)),
                        'cross_tenant_scope' => $scope,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);

                if ($updated === 0) {
                    throw StaleVersion::of('profile', 'calling profile');
                }

                $profile->destinations()->sync($this->destinationIds($scope, $destinationCodes));
                $profile->refresh()->load('destinations');
                $after = $this->summary($profile);

                $this->audit->record('calling_profile.updated', $profile, ['name' => $name, 'from' => $before, 'to' => $after]);

                return $before === $after ? [] : $this->provisionUsers($profile);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Another profile has this name.']);
        }
    }

    /**
     * @return list<ProvisioningOperation> for the extensions that move from the old default to this one
     */
    public function makeDefault(CallingProfile $profile): array
    {
        if ($profile->is_default) {
            return [];
        }

        return DB::transaction(function () use ($profile): array {
            CallingProfile::query()->where('is_default', true)->update(['is_default' => false, 'version' => DB::raw('version + 1')]);
            $profile->update(['is_default' => true, 'version' => $profile->version + 1]);

            $this->audit->record('calling_profile.made_default', $profile, ['name' => $profile->name]);

            return $this->provisionUsers($profile, inheritedOnly: true);
        });
    }

    /**
     * @throws ProfileInUse
     */
    public function delete(CallingProfile $profile): void
    {
        if ($profile->is_default) {
            throw ProfileInUse::asDefault();
        }

        $users = Extension::query()->where('calling_profile_id', $profile->id)->count();

        if ($users > 0) {
            throw ProfileInUse::byExtensions($users);
        }

        DB::transaction(function () use ($profile): void {
            $profile->delete();

            $this->audit->record('calling_profile.deleted', $profile, ['name' => $profile->name]);
        });
    }

    /**
     * Requests the extensions the profile reaches: its own users and, for the default, those
     * without a profile (only those with $inheritedOnly).
     *
     * @return list<ProvisioningOperation>
     */
    private function provisionUsers(CallingProfile $profile, bool $inheritedOnly = false): array
    {
        if ($inheritedOnly && ! $profile->is_default) {
            return [];
        }

        return array_values(Extension::query()
            ->where(fn (Builder $query) => $query
                ->when(! $inheritedOnly, fn (Builder $query) => $query->orWhere('calling_profile_id', $profile->id))
                ->when($profile->is_default, fn (Builder $query) => $query->orWhereNull('calling_profile_id')))
            ->orderBy('extension_number')
            ->get()
            ->map(fn (Extension $extension): ProvisioningOperation => $this->provisioning->forNumber(
                $extension->pbx_node_id,
                $extension->tenant_id,
                $extension->extension_number,
                OperationType::CallingProfileChanged,
                Extension::MORPH,
                $extension->id,
            ))
            ->all());
    }

    /**
     * Denied categories are left out, so a new category is denied everywhere until allowed.
     *
     * @param  array<string, CallPermission>  $categories
     * @return array<string, string>
     */
    private function stored(array $categories): array
    {
        $stored = [];

        foreach (CallCategory::cases() as $category) {
            $permission = $categories[$category->value] ?? CallPermission::Deny;

            if ($permission !== CallPermission::Deny) {
                $stored[$category->value] = $permission->value;
            }
        }

        return $stored;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string> tenant ids
     */
    private function destinationIds(CrossTenantScope $scope, array $codes): array
    {
        if ($scope === CrossTenantScope::All) {
            return [];
        }

        $codes = array_values(array_unique($codes));

        if (count($codes) > CallingProfile::MAX_DESTINATIONS) {
            throw ValidationException::withMessages(['destinations' => 'A profile lists at most '.CallingProfile::MAX_DESTINATIONS.' tenants.']);
        }

        $tenants = Tenant::query()->whereIn('code', $codes)->whereKeyNot((string) $this->context->id())->pluck('id', 'code');
        $unknown = array_diff($codes, $tenants->keys()->map(fn (mixed $code): string => (string) $code)->all());

        if ($unknown !== []) {
            throw ValidationException::withMessages(['destinations' => 'No other tenant has the code '.implode(', ', $unknown).'.']);
        }

        return array_values($tenants->map(fn (mixed $id): string => (string) $id)->all());
    }

    /**
     * @return array{categories: array<string, string>, cross_tenant_scope: string, destinations: list<string>}
     */
    private function summary(CallingProfile $profile): array
    {
        return [
            'categories' => $profile->categories,
            'cross_tenant_scope' => $profile->cross_tenant_scope->value,
            'destinations' => array_values($profile->destinations->pluck('code')->sort()->map(fn (mixed $code): string => (string) $code)->all()),
        ];
    }
}
