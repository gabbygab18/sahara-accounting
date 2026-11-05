import { Button } from '@/components/glint/button';
import { X } from '@/icons';
import { useEffect, useState } from 'react';

/** Chrome/Android's install event (not in TypeScript's DOM types yet). */
type InstallEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> };

const SNOOZE_KEY = 'sahara-accounting-install-snoozed-until';
const SNOOZE_DAYS = 7;

const installed = () =>
    window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
const isIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
// Messenger, Facebook, Instagram, Viber… open links in their own browser, which cannot add to the home screen.
const inAppBrowser = () => /FBAN|FBAV|FB_IAB|Instagram|Messenger|Viber|Line\//i.test(navigator.userAgent);

const snoozed = () => {
    try {
        return Number(localStorage.getItem(SNOOZE_KEY) ?? 0) > Date.now();
    } catch {
        return false;
    }
};
const snooze = () => {
    try {
        localStorage.setItem(SNOOZE_KEY, String(Date.now() + SNOOZE_DAYS * 86_400_000));
    } catch {
        // storage blocked: it just shows again next visit
    }
};

/** iOS share glyph: a box with an arrow out of the top. */
function ShareGlyph() {
    return (
        <svg
            viewBox="0 0 24 24"
            className="inline size-5 align-[-4px] text-[#0a84ff]"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-label="Share"
        >
            <path d="M12 3v12M8 7l4-4 4 4" />
            <path d="M8 11H6a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-2" />
        </svg>
    );
}

function AddGlyph() {
    return (
        <svg
            viewBox="0 0 24 24"
            className="inline size-5 align-[-4px]"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            aria-label="Add"
        >
            <rect x="4" y="4" width="16" height="16" rx="4" />
            <path d="M12 8v8M8 12h8" />
        </svg>
    );
}

/**
 * Android/Chrome: a card with an Install button that opens the browser's install dialog.
 * iPhone/iPad: Safari has no install dialog, so the card walks through Share → Add to Home Screen.
 * Hidden once installed; "Not now" hides it for a week.
 */
export function InstallPrompt() {
    const [event, setEvent] = useState<InstallEvent | null>(null);
    const [ios, setIos] = useState(false);

    useEffect(() => {
        if (installed() || snoozed()) return;
        const onPrompt = (e: Event) => {
            e.preventDefault(); // show our card instead of Chrome's mini bar
            setEvent(e as InstallEvent);
        };
        const onInstalled = () => setEvent(null);
        window.addEventListener('beforeinstallprompt', onPrompt);
        window.addEventListener('appinstalled', onInstalled);
        // Give the page a moment before asking.
        const timer = isIos() ? window.setTimeout(() => setIos(true), 1500) : undefined;
        return () => {
            window.removeEventListener('beforeinstallprompt', onPrompt);
            window.removeEventListener('appinstalled', onInstalled);
            window.clearTimeout(timer);
        };
    }, []);

    if (!event && !ios) return null;

    const close = () => {
        snooze();
        setEvent(null);
        setIos(false);
    };
    const install = async () => {
        if (!event) return;
        await event.prompt();
        await event.userChoice;
        setEvent(null);
    };

    return (
        <div
            role="dialog"
            aria-label="Install Sahara Accounting"
            className="light fixed inset-x-3 bottom-3 z-[60] mx-auto max-w-md rounded-3xl bg-white p-5 text-[#1b1b1d] shadow-[0_20px_60px_-15px_rgb(0_0_0/0.45)] ring-1 ring-black/5 sm:right-4 sm:left-auto sm:mx-0"
            style={{ marginBottom: 'env(safe-area-inset-bottom)' }}
        >
            <button
                type="button"
                onClick={close}
                aria-label="Not now"
                className="absolute top-3 right-3 rounded-full p-1.5 text-[#8e9196] hover:bg-[#f4f4f2]"
            >
                <X className="size-4" />
            </button>
            <div className="flex items-center gap-3 pr-6">
                <img src="/icons/icon-192.png?v=2" alt="" className="size-12 rounded-2xl" />
                <div>
                    <p className="font-display text-lg leading-tight font-bold">Install Sahara Accounting</p>
                    <p className="text-sm text-[#55585c]">Opens like an app, full screen, from your home screen.</p>
                </div>
            </div>

            {event && (
                <div className="mt-4 flex gap-2">
                    <Button variant="outline" className="flex-1" onClick={close}>
                        Not now
                    </Button>
                    <Button className="flex-[2]" onClick={install}>
                        Install
                    </Button>
                </div>
            )}

            {ios && !event && (
                <>
                    {inAppBrowser() ? (
                        <p className="mt-4 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            This was opened inside another app. Tap <b>⋯</b> and choose <b>Open in Safari</b> first, then follow the steps below.
                        </p>
                    ) : null}
                    <ol className="mt-4 space-y-3 text-sm">
                        <li className="flex gap-3">
                            <span className="grid size-6 shrink-0 place-items-center rounded-full bg-[#1b1b1d] text-xs font-bold text-white">1</span>
                            <span>
                                Tap the <b>Share</b> button <ShareGlyph /> at the bottom of Safari (top right on iPad).
                            </span>
                        </li>
                        <li className="flex gap-3">
                            <span className="grid size-6 shrink-0 place-items-center rounded-full bg-[#1b1b1d] text-xs font-bold text-white">2</span>
                            <span>
                                Scroll down and tap <b>Add to Home Screen</b> <AddGlyph />.
                            </span>
                        </li>
                        <li className="flex gap-3">
                            <span className="grid size-6 shrink-0 place-items-center rounded-full bg-[#1b1b1d] text-xs font-bold text-white">3</span>
                            <span>
                                Tap <b>Add</b>. Sahara Accounting appears on your home screen.
                            </span>
                        </li>
                    </ol>
                    <Button className="mt-4 w-full" variant="outline" onClick={close}>
                        Got it
                    </Button>
                </>
            )}
        </div>
    );
}
