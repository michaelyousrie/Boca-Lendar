import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function NavigationProgress() {
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        const offStart = router.on('start', (event) => {
            if (event.detail.visit.showProgress) setLoading(true);
        });
        const offFinish = router.on('finish', () => setLoading(false));
        return () => {
            offStart();
            offFinish();
        };
    }, []);
    return loading ? (
        <div className="navigation-progress" role="progressbar" aria-label="Loading page" />
    ) : null;
}
