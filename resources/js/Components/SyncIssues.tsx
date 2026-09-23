import { Link } from '@inertiajs/react';
import { CircleAlert, RefreshCw } from 'lucide-react';
import { dateLabel, timeLabel } from '../lib/dates';
import type { SyncIssue } from '../types';

export default function SyncIssues({
    issues,
    timezone,
    onSelect,
}: {
    issues: SyncIssue[];
    timezone: string;
    onSelect: (date: string) => void;
}) {
    if (issues.length === 0) return null;

    return (
        <section className="sync-panel" aria-labelledby="sync-title">
            <div className="section-heading">
                <h2 id="sync-title">Google sync</h2>
                <span className="sync-count">{issues.length}</span>
            </div>
            <ul className="sync-issues">
                {issues.map((item) => (
                    <li key={item.id} className={`sync-issue ${item.sync_status}`}>
                        <div className="sync-issue-status">
                            {item.sync_status === 'failed' ? (
                                <CircleAlert size={14} />
                            ) : (
                                <RefreshCw size={14} />
                            )}
                            {item.sync_status === 'failed' ? 'Failed to sync' : 'Syncing'}
                        </div>
                        <button className="sync-issue-title" onClick={() => onSelect(item.date)}>
                            {item.title}
                        </button>
                        <span className="sync-issue-date">
                            {dateLabel(item.date, { month: 'short', day: 'numeric' })},{' '}
                            {item.all_day ? 'All day' : timeLabel(item.starts_at, timezone)}
                        </span>
                        {item.status === 'cancelled' && <p>Removing from Google.</p>}
                        {item.sync_error && <p className="sync-issue-reason">{item.sync_error}</p>}
                        {item.sync_status === 'failed' && (
                            <Link
                                as="button"
                                method="post"
                                href={`/appointments/${item.id}/retry`}
                                preserveScroll
                                className="text-button"
                                aria-label={`Retry sync for ${item.title}`}
                            >
                                Retry sync
                            </Link>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
