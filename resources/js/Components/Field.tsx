import { ReactNode } from 'react';

type Props = { label: string; id: string; error?: string; children: ReactNode; hint?: string };

export default function Field({ label, id, error, children, hint }: Props) {
    return (
        <div className="field">
            <label htmlFor={id}>{label}</label>
            {children}
            {hint && <span className="field-hint">{hint}</span>}
            {error && (
                <p className="field-error" id={`${id}-error`} role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}
