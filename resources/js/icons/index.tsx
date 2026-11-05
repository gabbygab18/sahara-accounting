/**
 * Icons from the local icons-website set (data.ts, geometry.ts and render.ts are copied from
 * icons-website/src/icons). Every icon renders filled ("solid") by default; lines-only icons
 * such as chevrons have no shape to fill and stay as strokes.
 */
import { useId, type SVGProps } from 'react';
import { BY_NAME, renderInner, type Variant } from './render';

export interface IconProps extends Omit<SVGProps<SVGSVGElement>, 'stroke'> {
    name: string;
    variant?: Variant;
    stroke?: number;
    size?: number | string;
}

export function Icon({ name, variant = 'solid', stroke = 2, size = 24, ...props }: IconProps) {
    const id = `i${useId().replace(/[^\w-]/g, '')}`;
    const def = BY_NAME.get(name);
    if (!def) return null;
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            width={size}
            height={size}
            viewBox="0 0 24 24"
            aria-hidden
            focusable="false"
            {...props}
            dangerouslySetInnerHTML={{ __html: renderInner(def, { variant, stroke, id }) }}
        />
    );
}

export type IconComponent = ((props: Omit<IconProps, 'name'>) => React.JSX.Element) & { displayName?: string };

const named = (name: string): IconComponent => {
    const C = (props: Omit<IconProps, 'name'>) => <Icon name={name} {...props} />;
    C.displayName = name;
    return C;
};

// Same names as the lucide icons the Glint and starter-kit components import.
export const Check = named('check');
export const ChevronDown = named('chevron-down');
export const ChevronLeft = named('chevron-left');
export const ChevronRight = named('chevron-right');
export const ChevronsLeft = named('chevrons-left');
export const ChevronsRight = named('chevrons-right');
export const CircleAlert = named('alert-circle');
export const CircleCheck = named('check-circle');
export const Download = named('download');
export const Eye = named('eye');
export const EyeOff = named('eye-off');
export const Info = named('info');
export const Loader2 = named('loader');
export const LoaderCircle = named('loader');
export const LogOut = named('log-out');
export const Menu = named('menu');
export const Minus = named('minus');
export const Monitor = named('monitor');
export const Moon = named('moon');
export const MoreHorizontal = named('more-horizontal');
export const Plus = named('plus');
export const Search = named('search');
export const Settings = named('settings');
export const Sun = named('sun');
export const TriangleAlert = named('warning');
export const TrendingDown = named('trending-down');
export const TrendingUp = named('trending-up');
export const ArrowUpRight = named('arrow-up-right');
export const X = named('close');

// App icons.
export const Bell = named('bell');
export const BellRing = named('bell-ring');
export const Calendar = named('calendar');
export const CartPlus = named('cart-plus');
export const Edit = named('edit');
export const Grid = named('grid');
export const MapPin = named('map-pin');
export const History = named('history');
export const Lock = named('lock');
export const Undo = named('undo');
export const Receipt = named('receipt');
export const Printer = named('printer');
export const MagicWand = named('magic-wand');
export const Package = named('package');
export const PlusCircle = named('plus-circle');
export const Swap = named('swap');
export const Truck = named('truck');
export const Wallet = named('wallet');
export const Building = named('building');
export const Trash = named('trash');
export const AlarmClock = named('alarm-clock');
export const CheckCircle = named('check-circle');
