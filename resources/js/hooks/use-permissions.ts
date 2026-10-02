import { usePage } from '@inertiajs/react';

/**
 * Access the current user's abilities in the active company.
 * Super admins are always granted.
 */
export function usePermissions() {
    const { auth } = usePage().props;

    const can = (ability: string): boolean =>
        auth.isSuperAdmin || auth.permissions.includes(ability);

    const canAny = (abilities: string[]): boolean =>
        auth.isSuperAdmin || abilities.some((a) => auth.permissions.includes(a));

    return {
        permissions: auth.permissions,
        isSuperAdmin: auth.isSuperAdmin,
        can,
        canAny,
    };
}
