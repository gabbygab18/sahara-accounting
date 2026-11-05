import { StretchRail } from '@/components/glint/stretch-rail';
import Heading from '@/components/heading';
import { Separator } from '@/components/ui/separator';
import { useCan } from '@/hooks/use-can';
import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

const NAV = [
    { title: 'Profile', url: '/settings/profile' },
    { title: 'Password', url: '/settings/password' },
    { title: 'Appearance', url: '/settings/appearance' },
];
// With "Users and Google Sheets settings" ticked.
const ADMIN_NAV = [
    { title: 'Users', url: '/settings/users' },
    { title: 'Google Sheets', url: '/settings/sheets' },
];

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const path = usePage().url.split('?')[0];
    const nav = useCan('settings') ? [...NAV, ...ADMIN_NAV] : NAV;
    // Fetch the other settings tabs ahead of time so switching between them is instant.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => nav.forEach((n) => router.prefetch(n.url, { method: 'get' }, { cacheFor: ['30s', '10m'] })), []);

    return (
        <div className="bg-card rounded-3xl px-4 py-6 shadow-sm sm:px-6">
            <Heading title="Settings" description="Manage your profile and account settings" />

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    {/* Same elastic red rail as the main sidebar marks the page you are on. */}
                    <StretchRail
                        items={nav.map(({ title }) => ({ label: title }))}
                        active={nav.findIndex((n) => n.url === path)}
                        color="var(--primary)"
                        onSelect={(i) => router.visit(nav[i].url)}
                        className="[&_[aria-current=page]]:bg-muted [&_[aria-current=page]]:font-semibold"
                    />
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
