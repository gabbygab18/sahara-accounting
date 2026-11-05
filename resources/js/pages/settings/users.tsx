import { Badge } from '@/components/glint/badge';
import { Button } from '@/components/glint/button';
import { Checkbox } from '@/components/glint/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/glint/dialog';
import { Input } from '@/components/glint/input';
import { Select } from '@/components/glint/select';
import HeadingSmall from '@/components/heading-small';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type Permission, type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users', href: '/settings/users' }];

type Role = 'admin' | 'staff' | 'custom';
type Preset = Exclude<Role, 'custom'>;

interface Account {
    id: number;
    name: string;
    email: string;
    role: Role;
    permissions: Permission[];
    disabled_at: string | null;
    records_count: number;
}

interface Checklist {
    labels: Record<Permission, string>;
    presets: Record<Preset, Permission[]>;
}

const ROLE_LABEL: Record<Role, string> = { admin: 'Admin', staff: 'Staff', custom: 'Custom' };

const PRESET_HINT: Record<Preset, string> = {
    admin: 'Everything.',
    staff: 'Adds and edits records and exports. Cannot delete, see the activity log or change settings.',
};

const GROUPS: [string, Permission[]][] = [
    ['View', ['dashboard', 'records']],
    ['Change', ['add', 'edit', 'delete', 'confirm']],
    ['Tools', ['export', 'history', 'settings']],
];

/** The preset whose boxes match exactly, or custom. */
const roleOf = (perms: Permission[], presets: Checklist['presets']): Role =>
    (Object.keys(presets) as Preset[]).find((r) => presets[r].length === perms.length && presets[r].every((p) => perms.includes(p))) ?? 'custom';

/** New account (no `user`) or changes to one. */
function UserDialog({
    user,
    self,
    checklist,
    open,
    onOpenChange,
}: {
    user?: Account;
    self?: boolean;
    checklist: Checklist;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { data, setData, post, put, processing, errors, reset, transform } = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        permissions: user?.permissions ?? checklist.presets.staff,
        password: '',
        active: !user?.disabled_at,
    });
    const role = roleOf(data.permissions, checklist.presets);
    transform((d) => ({ ...d, role }));
    const toggle = (p: Permission, on: boolean) => setData('permissions', on ? [...data.permissions, p] : data.permissions.filter((x) => x !== p));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset('password');
                onOpenChange(false);
            },
        };
        if (user) put(route('users.update', user.id), options);
        else post(route('users.store'), options);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{user ? `Edit ${user.name}` : 'New account'}</DialogTitle>
                    <DialogDescription>
                        {user ? 'Leave the password empty to keep it.' : 'Give the person this email and password to log in.'}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="u-name">Name</Label>
                        <Input id="u-name" required value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="u-email">Email</Label>
                        <Input
                            id="u-email"
                            type="email"
                            required
                            disabled={!!user}
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            error={errors.email}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label>Access</Label>
                        <Select
                            aria-label="Preset"
                            value={role}
                            onValueChange={(v) => v !== 'custom' && setData('permissions', checklist.presets[v as Preset])}
                            options={[
                                ...(Object.keys(checklist.presets) as Preset[]).map((r) => ({
                                    value: r,
                                    label: ROLE_LABEL[r],
                                    description: PRESET_HINT[r],
                                })),
                                ...(role === 'custom' ? [{ value: 'custom', label: 'Custom', description: 'Ticked by hand below.' }] : []),
                            ]}
                        />
                        <div className="grid gap-3 rounded-2xl border p-3 sm:grid-cols-2">
                            {GROUPS.map(([group, keys]) => (
                                <fieldset key={group} className="grid content-start gap-1.5">
                                    <legend className="text-muted-foreground mb-1 text-xs font-medium tracking-wider uppercase">{group}</legend>
                                    {keys.map((p) => (
                                        <Checkbox
                                            key={p}
                                            label={checklist.labels[p]}
                                            checked={data.permissions.includes(p)}
                                            // Nobody can take away their own way back into Settings.
                                            disabled={self && p === 'settings'}
                                            onChange={(e) => toggle(p, e.target.checked)}
                                        />
                                    ))}
                                </fieldset>
                            ))}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="u-password">{user ? 'New password' : 'Password'}</Label>
                        <Input
                            id="u-password"
                            type="text"
                            autoComplete="new-password"
                            required={!user}
                            placeholder="At least 8 characters"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            error={errors.password}
                        />
                    </div>
                    {user && (
                        <Checkbox
                            label="Active"
                            description="Untick to stop this account from logging in. What they added keeps their name."
                            checked={data.active}
                            onChange={(e) => setData('active', e.target.checked)}
                        />
                    )}
                    {(errors as Record<string, string>).user && <p className="text-destructive text-sm">{(errors as Record<string, string>).user}</p>}
                    <DialogFooter className="mt-1 flex-row">
                        <Button variant="outline" className="flex-1 sm:flex-none" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" className="flex-[2] sm:flex-none" loading={processing}>
                            {user ? 'Save' : 'Create account'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Users({
    users,
    permissions,
    presets,
}: {
    users: Account[];
    permissions: Checklist['labels'];
    presets: Checklist['presets'];
}) {
    const checklist = { labels: permissions, presets };
    const me = usePage<SharedData>().props.auth.user.id;
    const [editing, setEditing] = useState<Account | null>(null);
    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head title="Users" />
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <HeadingSmall title="Users" description="Who can log in, and what each account can see and do." />
                    <Button onClick={() => setAdding(true)}>New account</Button>
                </div>

                <ul className="divide-y rounded-2xl border">
                    {users.map((u) => (
                        <li key={u.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">
                                    {u.name} {u.id === me && <span className="text-muted-foreground text-xs">(you)</span>}
                                </p>
                                <p className="text-muted-foreground truncate text-sm">{u.email}</p>
                            </div>
                            <span className="text-muted-foreground text-xs">{u.records_count.toLocaleString()} added</span>
                            <Badge size="sm" variant={u.role === 'admin' ? 'default' : 'secondary'}>
                                {ROLE_LABEL[roleOf(u.permissions, presets)]}
                            </Badge>
                            {u.disabled_at && (
                                <Badge size="sm" variant="destructive">
                                    Deactivated
                                </Badge>
                            )}
                            <Button size="sm" variant="outline" onClick={() => setEditing(u)}>
                                Edit
                            </Button>
                        </li>
                    ))}
                </ul>
            </div>
            <UserDialog key="new" checklist={checklist} open={adding} onOpenChange={setAdding} />
            {editing && (
                <UserDialog
                    key={editing.id}
                    user={editing}
                    self={editing.id === me}
                    checklist={checklist}
                    open
                    onOpenChange={(o) => !o && setEditing(null)}
                />
            )}
        </>
    );
}

// Persistent layout: the sidebar and header stay mounted while pages change.
Users.layout = (page: React.ReactNode) => (
    <AppLayout breadcrumbs={breadcrumbs}>
        <SettingsLayout>{page}</SettingsLayout>
    </AppLayout>
);
