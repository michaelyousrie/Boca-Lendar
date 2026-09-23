import { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import TimezoneSelect from '../../resources/js/Components/TimezoneSelect';

const timezones = ['UTC', 'Africa/Cairo', 'America/New_York', 'America/Argentina/Buenos_Aires'];

function Picker({ onChange = vi.fn() }: { onChange?: (zone: string) => void }) {
    const [value, setValue] = useState('UTC');
    return (
        <TimezoneSelect
            label="Timezone"
            value={value}
            timezones={timezones}
            onChange={(zone) => {
                setValue(zone);
                onChange(zone);
            }}
        />
    );
}

describe('searchable timezone select', () => {
    it('searches case-insensitively by city, region, spaces or IANA identifier and keeps the stored identifier', async () => {
        const onChange = vi.fn();
        render(<Picker onChange={onChange} />);
        const input = screen.getByRole('combobox', { name: 'Timezone' });
        await userEvent.click(input);
        expect(screen.getByRole('option', { name: 'UTC' })).toHaveAttribute('aria-selected', 'true');
        await userEvent.type(input, 'NEW york');
        expect(screen.getAllByRole('option')).toHaveLength(1);
        await userEvent.click(screen.getByRole('option', { name: 'America/New York' }));
        expect(onChange).toHaveBeenCalledWith('America/New_York');
        expect(input).toHaveValue('America/New York');
        expect(input).toHaveFocus();
        await userEvent.click(input);
        await userEvent.type(input, 'America/Argentina');
        expect(screen.getByRole('option', { name: 'America/Argentina/Buenos Aires' })).toBeVisible();
        await userEvent.clear(input);
        await userEvent.type(input, 'New_York');
        expect(screen.getAllByRole('option')).toHaveLength(1);
    });

    it('supports keyboard selection without submitting the surrounding form', async () => {
        const submit = vi.fn((event) => event.preventDefault());
        render(
            <form onSubmit={submit}>
                <Picker />
            </form>,
        );
        const input = screen.getByRole('combobox');
        input.focus();
        await userEvent.keyboard('{ArrowDown}{ArrowDown}{Enter}');
        expect(input).toHaveValue('Africa/Cairo');
        expect(submit).not.toHaveBeenCalled();
        expect(input).toHaveAttribute('aria-expanded', 'false');
        await userEvent.keyboard('{ArrowUp}{ArrowUp}');
        expect(input).toHaveAttribute(
            'aria-activedescendant',
            screen.getByRole('option', { name: 'UTC' }).id,
        );
        await userEvent.keyboard('{Enter}');
        expect(input).toHaveValue('UTC');
    });

    it('discards unselected searches on Escape, Tab and outside clicks', async () => {
        const onChange = vi.fn();
        const escape = vi.fn();
        render(
            <div onKeyDown={escape}>
                <Picker onChange={onChange} />
                <button>Outside</button>
            </div>,
        );
        const input = screen.getByRole('combobox');
        await userEvent.click(input);
        await userEvent.type(input, 'cairo');
        escape.mockClear();
        await userEvent.keyboard('{Escape}');
        expect(escape).not.toHaveBeenCalled();
        expect(input).toHaveValue('UTC');
        await userEvent.click(input);
        await userEvent.type(input, 'cairo');
        await userEvent.tab();
        expect(screen.getByRole('button')).toHaveFocus();
        expect(input).toHaveValue('UTC');
        await userEvent.click(input);
        await userEvent.click(screen.getByRole('button'));
        expect(input).toHaveAttribute('aria-expanded', 'false');
        expect(onChange).not.toHaveBeenCalled();
    });

    it('shows an empty state and never commits arbitrary text', async () => {
        const onChange = vi.fn();
        render(<Picker onChange={onChange} />);
        const input = screen.getByRole('combobox');
        await userEvent.click(input);
        await userEvent.type(input, 'not a timezone');
        expect(screen.getByRole('status')).toHaveTextContent('No timezones found');
        expect(screen.queryByRole('option')).toBeNull();
        expect(input).not.toHaveAttribute('aria-activedescendant');
        await userEvent.keyboard('{ArrowDown}{Enter}');
        expect(onChange).not.toHaveBeenCalled();
        await userEvent.clear(input);
        expect(screen.getAllByRole('option')).toHaveLength(timezones.length);
        await userEvent.click(screen.getByRole('option', { name: 'UTC' }));
        expect(onChange).not.toHaveBeenCalled();
    });

    it('preserves validation attributes and prevents interaction while disabled', async () => {
        const { rerender } = render(
            <TimezoneSelect
                label="Timezone"
                id="timezone"
                value="UTC"
                timezones={timezones}
                onChange={vi.fn()}
                invalid
                describedBy="timezone-error"
                disabled
            />,
        );
        const input = screen.getByRole('combobox');
        expect(input).toBeDisabled();
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(input).toHaveAttribute('aria-describedby', 'timezone-error');
        await userEvent.click(input);
        expect(screen.queryByRole('listbox')).toBeNull();
        rerender(
            <TimezoneSelect label="Timezone" value="Africa/Cairo" timezones={timezones} onChange={vi.fn()} />,
        );
        expect(input).toHaveValue('Africa/Cairo');
        fireEvent.change(input, { target: { value: 'cairo' } });
        expect(screen.getByRole('option', { name: 'Africa/Cairo' })).toBeVisible();
    });
});
