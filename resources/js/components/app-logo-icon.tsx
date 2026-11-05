import { cn } from '@/lib/utils';

/** Sahara "S" mark on a white tile: its see-through blue layers need a light background, even on the dark sidebar. */
export default function AppLogoIcon({ className }: { className?: string }) {
    return (
        <span className={cn('grid shrink-0 place-items-center rounded-xl bg-white p-1', className)}>
            <img src="/images/sahara-mark.svg" alt="Sahara Construction Supply" className="size-full object-contain" />
        </span>
    );
}
