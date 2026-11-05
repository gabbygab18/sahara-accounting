import AppLogoIcon from '@/components/app-logo-icon';
import { InstallPrompt } from '@/components/install-prompt';
import { Receipt } from '@/icons';
import { Link } from '@inertiajs/react';

interface AuthLayoutProps {
    children: React.ReactNode;
    title?: string;
    description?: string;
}

const STEPS = ['Log in with your account', 'Add POs, DRs, invoices and ORs', 'See who has paid and who has not'];

/** Two-panel card: brand gradient with the steps on the left, the form on the right. */
export default function AuthSplitLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh items-center justify-center bg-[#1f1f21] p-3 sm:p-6 lg:p-10">
            {/* .light keeps the card readable when the device is in dark mode. */}
            <div className="light grid w-full max-w-6xl overflow-hidden rounded-[1.75rem] bg-white p-2 text-[#1b1b1d] shadow-2xl lg:grid-cols-2 lg:rounded-[2.25rem]">
                <aside className="relative flex min-h-56 flex-col overflow-hidden rounded-[1.4rem] bg-[radial-gradient(circle_at_28%_22%,#8fb2ff_0%,transparent_42%),radial-gradient(circle_at_80%_75%,#3f74f5_0%,transparent_48%),linear-gradient(155deg,#133aa8_0%,#1a45dc_45%,#0a1d5c_100%)] p-6 text-white sm:p-8 lg:min-h-[640px] lg:rounded-[1.9rem] lg:p-11">
                    <Link href={route('home')} className="flex items-center gap-2.5">
                        <AppLogoIcon className="size-9" />
                        <span className="text-lg font-semibold tracking-tight">Sahara Construction Supply</span>
                    </Link>

                    <div className="mt-auto pt-10">
                        <span className="inline-flex items-center gap-2 rounded-full bg-white/20 px-4 py-2 text-sm font-medium backdrop-blur">
                            Accounting <Receipt className="size-4" />
                        </span>
                        <h2 className="mt-5 text-4xl leading-[1.02] font-black sm:text-5xl lg:text-6xl">Welcome back</h2>
                        <p className="mt-4 text-white/80">Every invoice and payment, per company and project, in one place.</p>

                        <ol className="mt-8 hidden grid-cols-3 gap-3 sm:grid">
                            {STEPS.map((step, i) => (
                                <li
                                    key={step}
                                    className={
                                        i === 0
                                            ? 'flex min-h-36 flex-col rounded-2xl bg-white p-4 text-[#1b1b1d]'
                                            : 'flex min-h-36 flex-col rounded-2xl border border-white/20 bg-white/15 p-4 text-white/85 backdrop-blur'
                                    }
                                >
                                    <span
                                        className={
                                            i === 0
                                                ? 'grid size-7 place-items-center rounded-full bg-[#1a45dc] text-sm font-semibold text-white'
                                                : 'grid size-7 place-items-center rounded-full border border-white/60 text-sm'
                                        }
                                    >
                                        {i + 1}
                                    </span>
                                    <span className="mt-auto text-sm leading-snug font-medium">{step}</span>
                                </li>
                            ))}
                        </ol>
                    </div>
                </aside>

                <main className="flex items-center justify-center px-5 py-10 sm:px-10 lg:px-16">
                    <div className="w-full max-w-sm">
                        <h1 className="text-center text-3xl font-black sm:text-4xl">{title}</h1>
                        {description && <p className="mt-3 text-center text-sm text-[#55585c]">{description}</p>}
                        <div className="mt-9">{children}</div>
                    </div>
                </main>
            </div>
            <InstallPrompt />
        </div>
    );
}
