import { KeyboardEvent, ReactNode, useEffect, useRef } from 'react';
import { X } from 'lucide-react';

type Props = {
    title: string;
    description?: string;
    onClose: () => void;
    children: ReactNode;
    busy?: boolean;
};

export default function Dialog({ title, description, onClose, children, busy = false }: Props) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const dialog = ref.current!;
        const previouslyFocused = document.activeElement as HTMLElement | null;
        dialog.showModal();
        dialog.querySelector<HTMLElement>('[data-autofocus]')?.focus();
        const previous = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            dialog.close();
            document.body.style.overflow = previous;
            previouslyFocused?.focus();
        };
    }, []);

    function trapFocus(event: KeyboardEvent<HTMLDialogElement>) {
        if (event.key !== 'Tab') return;
        const items = Array.from(
            ref.current!.querySelectorAll<HTMLElement>(
                'button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), a[href], [tabindex="0"]',
            ),
        );
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first?.focus();
        }
    }

    return (
        <dialog
            onKeyDown={trapFocus}
            ref={ref}
            className="dialog"
            aria-labelledby="dialog-title"
            aria-describedby={description ? 'dialog-description' : undefined}
            onCancel={(event) => {
                event.preventDefault();
                if (!busy) onClose();
            }}
        >
            <div className="dialog-heading">
                <div>
                    <h2 id="dialog-title">{title}</h2>
                    {description && <p id="dialog-description">{description}</p>}
                </div>
                <button
                    type="button"
                    className="icon-button"
                    aria-label="Close dialog"
                    onClick={onClose}
                    disabled={busy}
                >
                    <X size={20} />
                </button>
            </div>
            {children}
        </dialog>
    );
}
