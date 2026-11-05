import { type Permission, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/** Whether this account has the box ticked on Settings → Users. The server enforces the same checks. */
export function useCan(permission: Permission): boolean {
    return usePage<SharedData>().props.auth.can.includes(permission);
}
