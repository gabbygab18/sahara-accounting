import { Eye, EyeOff, LoaderCircle } from '@/icons';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import { Checkbox } from '@/components/glint/checkbox';
import AuthLayout from '@/layouts/auth-layout';

type LoginForm = {
    email: string;
    password: string;
    remember: boolean;
};

const field =
    'h-12 w-full rounded-xl border border-transparent bg-[#f4f4f2] px-4 text-[15px] text-[#1b1b1d] outline-none transition placeholder:text-[#a8abaf] focus:border-[#1a45dc]/40 focus:bg-white focus:ring-4 focus:ring-[#1a45dc]/10';
const label = 'mb-2 block text-sm font-semibold';

// "Forgot password?" is left out: mail is not set up (MAIL_MAILER=log), so reset links never arrive.
export default function Login({ status }: { status?: string }) {
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Admin login">
            <Head title="Log in" />

            {status && <p className="mb-4 text-sm font-medium text-green-700">{status}</p>}

            <form className="grid gap-6" onSubmit={submit}>
                <div>
                    <label htmlFor="email" className={label}>
                        Email
                    </label>
                    <input
                        id="email"
                        type="email"
                        required
                        autoFocus
                        autoComplete="email"
                        placeholder="you@email.com"
                        className={field}
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    {errors.email && <p className="mt-2 text-sm text-[#b80f14]">{errors.email}</p>}
                </div>

                <div>
                    <label htmlFor="password" className={label}>
                        Password
                    </label>
                    <div className="relative">
                        <input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            required
                            autoComplete="current-password"
                            placeholder="••••••••••"
                            className={`${field} pr-12`}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                        <button
                            type="button"
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                            aria-pressed={showPassword}
                            onClick={() => setShowPassword((v) => !v)}
                            className="absolute inset-y-0 right-0 grid w-12 place-items-center text-[#a8abaf] transition-colors hover:text-[#1b1b1d]"
                        >
                            {showPassword ? <EyeOff className="size-5" /> : <Eye className="size-5" />}
                        </button>
                    </div>
                    {errors.password && <p className="mt-2 text-sm text-[#b80f14]">{errors.password}</p>}
                </div>

                <Checkbox
                    id="remember"
                    label={<span className="text-sm">Keep me logged in on this device</span>}
                    checked={data.remember}
                    onChange={(e) => setData('remember', e.target.checked)}
                />

                <button
                    type="submit"
                    disabled={processing}
                    className="flex h-13 w-full items-center justify-center gap-2 rounded-xl bg-[#1a45dc] text-base font-semibold text-white shadow-[0_10px_24px_-10px_#1a45dc] transition hover:bg-[#1538b8] focus-visible:ring-4 focus-visible:ring-[#1a45dc]/25 focus-visible:outline-none disabled:opacity-70"
                >
                    {processing && <LoaderCircle className="size-5 animate-spin" />}
                    Continue
                </button>
            </form>

            <p className="mt-6 text-center text-sm text-[#55585c]">No account yet? Ask your administrator to create one.</p>
            <p className="mt-10 text-center text-xs text-[#8e9196]">Sahara Construction Supply</p>
        </AuthLayout>
    );
}
