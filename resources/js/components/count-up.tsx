import { Tally, type TallyProps } from '@/components/glint/tally';
import { useEffect } from 'react';

// Last value each counter showed, kept while the app is open.
const shown = new Map<string, number>();

/**
 * Glint Tally that counts up from 0 only the first time. Coming back to the page starts from the
 * last value shown, so numbers stay put (or tick by the difference) instead of replaying.
 */
export function CountUp({ id, to, ...props }: Omit<TallyProps, 'from'> & { id: string }) {
    const from = shown.get(id) ?? 0;
    useEffect(() => {
        shown.set(id, to);
    }, [id, to]);

    return <Tally from={from} to={to} {...props} />;
}
