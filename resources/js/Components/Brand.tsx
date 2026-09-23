import { CalendarDays } from 'lucide-react';

export default function Brand() {
    return (
        <div className="brand">
            <span className="brand-mark">
                <CalendarDays size={22} strokeWidth={1.8} aria-hidden="true" />
            </span>
            <span>
                Boca<span className="brand-dot">-lendar</span>
            </span>
        </div>
    );
}
