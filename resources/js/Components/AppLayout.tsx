import { ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { CalendarDays, LogOut } from 'lucide-react';
import Brand from './Brand';
import type { SharedProps } from '../types';

export default function AppLayout({ children }: { children: ReactNode }) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <>
            <a href="#agenda" className="skip-link">
                Skip to appointments
            </a>
            <div className="app-shell">
                <nav className="nav-rail" aria-label="Main navigation">
                    <span className="rail-logo" aria-hidden="true">
                        b.
                    </span>
                    <Link
                        href="/appointments"
                        className="rail-link active"
                        aria-label="Appointments"
                        aria-current="page"
                    >
                        <CalendarDays size={24} strokeWidth={1.6} />
                    </Link>
                    <span className="rail-spacer" />
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="rail-link"
                        aria-label="Sign out"
                    >
                        <LogOut size={21} />
                    </Link>
                </nav>
                <div className="app-body">
                    <header className="topbar">
                        <div className="topbar-brand">
                            <Brand />
                        </div>
                        <div className="topbar-user">
                            <span className="user-name">{auth.user?.name}</span>
                            <span className="user-avatar" aria-hidden="true">
                                {auth.user?.name.slice(0, 1).toUpperCase()}
                            </span>
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                className="icon-button mobile-signout"
                                aria-label="Sign out"
                            >
                                <LogOut size={18} />
                            </Link>
                        </div>
                    </header>
                    <div className="workspace">{children}</div>
                </div>
            </div>
        </>
    );
}
