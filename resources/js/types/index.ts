/** Keys from the checklist on Settings → Users (App\Models\User::PERMISSIONS). */
export type Permission = 'dashboard' | 'records' | 'add' | 'edit' | 'delete' | 'confirm' | 'export' | 'history' | 'settings';

export interface Auth {
    user: User;
    can: Permission[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'staff' | 'custom';
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
