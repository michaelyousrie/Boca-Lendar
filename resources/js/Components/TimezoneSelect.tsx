import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { Check, ChevronDown, Globe2, Search } from 'lucide-react';

type Props = {
    id?: string;
    label: string;
    value: string;
    timezones: string[];
    onChange: (timezone: string) => void;
    invalid?: boolean;
    describedBy?: string;
    disabled?: boolean;
    placement?: 'top' | 'bottom';
};

const displayName = (timezone: string) => timezone.replaceAll('_', ' ');
const searchText = (value: string) => value.toLowerCase().replace(/[_/]+/g, ' ');

export default function TimezoneSelect({
    id,
    label,
    value,
    timezones,
    onChange,
    invalid,
    describedBy,
    disabled = false,
    placement = 'bottom',
}: Props) {
    const generatedId = useId();
    const inputId = id ?? generatedId;
    const listId = `${inputId}-options`;
    const list = useRef<HTMLUListElement>(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const terms = searchText(query).trim().split(/\s+/);
    const options = timezones.filter((zone) => terms.every((term) => searchText(zone).includes(term)));
    const activeOption = options[activeIndex];

    useEffect(() => {
        if (open) list.current?.children[activeIndex]?.scrollIntoView({ block: 'nearest' });
    }, [open, activeIndex, query]);

    function showOptions() {
        setQuery('');
        setActiveIndex(Math.max(0, timezones.indexOf(value)));
        setOpen(true);
    }

    function select(zone: string) {
        setOpen(false);
        if (zone !== value) onChange(zone);
    }

    function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.nativeEvent.isComposing) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!open) {
                showOptions();
            } else {
                const direction = event.key === 'ArrowDown' ? 1 : -1;
                setActiveIndex(Math.max(0, Math.min(options.length - 1, activeIndex + direction)));
            }
        } else if (event.key === 'Enter' && open) {
            event.preventDefault();
            if (activeOption) select(activeOption);
        } else if (event.key === 'Escape' && open) {
            event.preventDefault();
            event.stopPropagation();
            setOpen(false);
        } else if (event.altKey && (event.key === 'Home' || event.key === 'End') && open) {
            event.preventDefault();
            setActiveIndex(event.key === 'Home' ? 0 : Math.max(0, options.length - 1));
        }
    }

    return (
        <div className={`timezone-select timezone-select-${placement}`}>
            <div className="timezone-select-control">
                {open ? <Search size={16} aria-hidden="true" /> : <Globe2 size={16} aria-hidden="true" />}
                <input
                    id={inputId}
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open}
                    aria-controls={open ? listId : undefined}
                    aria-autocomplete="list"
                    aria-activedescendant={open && activeOption ? `${listId}-${activeIndex}` : undefined}
                    aria-invalid={invalid}
                    aria-describedby={describedBy}
                    autoComplete="off"
                    spellCheck={false}
                    disabled={disabled}
                    value={open ? query : displayName(value)}
                    placeholder="Search timezones..."
                    onClick={() => {
                        if (!open) showOptions();
                    }}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setActiveIndex(0);
                        setOpen(true);
                    }}
                    onBlur={() => setOpen(false)}
                    onKeyDown={onKeyDown}
                />
                <ChevronDown size={15} aria-hidden="true" />
            </div>
            {open && (
                <div className="timezone-select-popup">
                    <ul ref={list} id={listId} role="listbox" aria-label={`${label} options`}>
                        {options.map((zone, index) => (
                            <li
                                key={zone}
                                id={`${listId}-${index}`}
                                role="option"
                                aria-selected={zone === value}
                                className={index === activeIndex ? 'active' : ''}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => select(zone)}
                            >
                                <span>{displayName(zone)}</span>
                                {zone === value && <Check size={16} aria-hidden="true" />}
                            </li>
                        ))}
                    </ul>
                    <p className="timezone-select-status" role="status">
                        {options.length === 0
                            ? 'No timezones found. Try another city or region.'
                            : `${options.length} ${options.length === 1 ? 'timezone' : 'timezones'}`}
                    </p>
                </div>
            )}
        </div>
    );
}
