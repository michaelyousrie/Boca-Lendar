import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { browserEnvironment } from './environment';

test.beforeEach(() => {
    execFileSync('php', ['artisan', 'cache:clear'], {
        env: { ...process.env, ...browserEnvironment },
        stdio: 'pipe',
    });
});

async function register(page: Page, mobile: boolean) {
    await page.goto('/register');
    await page.getByLabel('Your name').fill('Jordan Lee');
    const email = `review-${crypto.randomUUID()}@example.com`;
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('a-good-test-password');
    await page.getByLabel('Confirm password').fill('a-good-test-password');
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL(/\/appointments$/);
    return email;
}

async function fillBooking(page: Page, title = 'Discovery session') {
    await page.getByRole('button', { name: 'New appointment', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Appointment title').fill(title);
    await dialog.getByLabel('Customer name').fill('Sam Rivera');
    await dialog.getByLabel('Customer email').fill('sam@example.com');
    const tomorrow = new Date(Date.now() + 86_400_000).toISOString().slice(0, 10);
    await dialog.getByLabel('Date', { exact: true }).fill(tomorrow);
    await dialog.getByLabel('Start time').fill('14:00');
    await dialog.getByLabel('Appointment timezone').selectOption('UTC');
}

async function expandCancelled(page: Page) {
    const section = page.getByRole('region', { name: 'Cancelled appointments' });
    await expect(section).toBeVisible({ timeout: 20_000 });
    if (!(await section.locator('details').evaluate((element: HTMLDetailsElement) => element.open))) {
        await section.locator('summary').click();
    }
}

function googleFixture(mode: string, email?: string) {
    execFileSync('php', ['tests/Fixtures/google.php', mode, ...(email ? [email] : [])], {
        env: { ...process.env, ...browserEnvironment },
        stdio: 'pipe',
    });
}

test('register, book locally, reject a conflict, cancel immediately and sign out', async ({
    page,
    isMobile,
}) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await register(page, isMobile);
    await fillBooking(page);
    await page.getByRole('button', { name: 'Reserve appointment', exact: true }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    const appointment = page.getByRole('article', { name: 'Discovery session', exact: true });
    await expect(appointment).toContainText('Saved locally');
    if (isMobile) await page.getByRole('button', { name: 'Calendars & dates' }).click();
    await expect(page.getByRole('heading', { name: 'Google Account' })).toBeVisible();
    await page
        .getByRole('region', { name: 'Upcoming Events' })
        .getByRole('button', { name: /Discovery session/ })
        .click();
    await expect(appointment).toBeVisible();
    await page.screenshot({
        path: `test-results/upcoming-${isMobile ? 'mobile' : 'desktop'}.png`,
        fullPage: true,
    });
    await expect(page.getByRole('button', { name: 'Calendar mirror' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Agenda', exact: true })).toHaveCount(0);

    await fillBooking(page, 'Overlapping session');
    await page.getByRole('button', { name: 'Reserve appointment', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('already has a reservation');
    await expect(page.getByLabel('Start time')).toBeFocused();
    await page.getByRole('button', { name: 'Close dialog' }).click();
    await appointment.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.getByRole('button', { name: 'Keep appointment' }).click();
    await expect(appointment).not.toContainText('Cancelled');
    await appointment.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.getByRole('button', { name: 'Cancel appointment', exact: true }).click();
    const disclosure = page.getByRole('region', { name: 'Cancelled appointments' });
    await expect(disclosure.getByText('1', { exact: true })).toBeVisible();
    await expect(appointment).not.toBeVisible();
    const toggle = disclosure.locator('summary');
    await toggle.focus();
    await page.keyboard.press('Enter');
    await expect(appointment).toBeVisible();
    await page.keyboard.press('Space');
    await expect(appointment).not.toBeVisible();
    await expect(disclosure.getByText('1', { exact: true })).toBeVisible();
    await expandCancelled(page);
    await expect(appointment).toContainText('Cancelled');
    const cancelled = page.getByRole('region', { name: 'Cancelled appointments' });
    await expect(cancelled.getByRole('article', { name: 'Discovery session' })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'Show cancelled appointments' })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: "It's a bit quiet in here" })).not.toBeVisible();
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.goto('/appointments');
    await expect(page).toHaveURL(/\/login$/);
    expect(errors).toEqual([]);
});

test('accessible pages, responsive layout and a keyboard-safe booking dialog', async ({ page, isMobile }) => {
    await page.goto('/login');
    await expect(page).toHaveTitle('Sign in | Boca-lendar');
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await register(page, isMobile);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await expect(page.getByRole('button', { name: 'Back to today' })).toHaveCount(0);
    const selectedDay = page
        .getByRole('region', { name: 'Daily schedule' })
        .getByRole('heading', { level: 2 })
        .last();
    const today = await selectedDay.innerText();
    await page.getByRole('button', { name: 'Next week' }).click();
    await expect(selectedDay).not.toHaveText(today);
    if (isMobile) await page.getByRole('button', { name: 'Calendars & dates' }).click();
    await page.getByRole('button', { name: 'Back to today' }).click();
    await expect(page.getByRole('button', { name: 'Back to today' })).toHaveCount(0);
    await page.getByRole('button', { name: 'New appointment', exact: true }).click();
    await expect(page.getByLabel('Appointment title')).toBeFocused();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.keyboard.press('Shift+Tab');
    await expect(page.getByRole('button', { name: 'Close dialog' })).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(page.getByRole('button', { name: 'Reserve appointment', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(page.getByRole('button', { name: 'New appointment', exact: true })).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({
        path: `test-results/workspace-${isMobile ? 'mobile' : 'desktop'}.png`,
        fullPage: true,
        animations: 'disabled',
    });
});

test('month markers follow active bookings and cancelled cards remain clearly labelled', async ({
    page,
    isMobile,
}) => {
    await register(page, isMobile);
    await fillBooking(page, 'Cancelled consultation');
    await page.getByRole('button', { name: 'Reserve appointment', exact: true }).click();
    const cancelled = page.getByRole('article', { name: 'Cancelled consultation', exact: true });
    await expect(cancelled).toBeVisible();
    await fillBooking(page, 'Upcoming consultation');
    await page.getByLabel('Start time').fill('14:30');
    await page.getByRole('button', { name: 'Reserve appointment', exact: true }).click();
    const upcoming = page.getByRole('article', { name: 'Upcoming consultation', exact: true });
    await expect(upcoming).toBeVisible();
    if (isMobile) await page.getByRole('button', { name: 'Calendars & dates' }).click();

    const month = page.getByRole('region', { name: 'Choose a date' });
    const markers = month.locator('[aria-description="Has appointments"]');
    await expect(markers).toHaveCount(1);
    await expect(markers).toHaveAttribute('aria-pressed', 'true');
    const originalMonth = await month.getByRole('heading').innerText();
    await month.getByRole('button', { name: 'Next month' }).click();
    await expect(month.getByRole('heading')).not.toHaveText(originalMonth);
    await expect(markers).toHaveCount(0);
    await expect(upcoming).toBeVisible();
    await month.getByRole('button', { name: 'Previous month' }).click();
    await expect(month.getByRole('heading')).toHaveText(originalMonth);
    await expect(markers).toHaveCount(1);

    await cancelled.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.getByRole('button', { name: 'Cancel appointment', exact: true }).click();
    await expandCancelled(page);
    await expect(cancelled.getByText('Cancelled', { exact: true })).toBeVisible();
    await expect(cancelled).not.toContainText('Saved locally');
    await expect(cancelled.getByRole('heading')).toHaveCSS('text-decoration-line', 'line-through');
    await expect(upcoming).not.toContainText('Cancelled');
    const cancelledSection = page.getByRole('region', { name: 'Cancelled appointments' });
    await expect(cancelledSection.getByRole('article')).toHaveCount(1);
    await expect(page.getByRole('article').first()).toHaveAccessibleName('Upcoming consultation');
    await expect(page.getByRole('article').last()).toHaveAccessibleName('Cancelled consultation');
    await expect(markers).toHaveCount(1);
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({
        path: `test-results/appointment-states-${isMobile ? 'mobile' : 'desktop'}.png`,
        fullPage: true,
    });
    await expect(page.getByRole('status')).toHaveText('Appointment cancelled.');
    await month.getByRole('button', { name: 'Next month' }).click();
    await expect(month.getByRole('heading')).not.toHaveText(originalMonth);
    await expect(page.getByRole('status')).not.toBeVisible();
    await month.getByRole('button', { name: 'Previous month' }).click();
    await expect(month.getByRole('heading')).toHaveText(originalMonth);

    await upcoming.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.getByRole('button', { name: 'Cancel appointment', exact: true }).click();
    await expandCancelled(page);
    await expect(upcoming.getByText('Cancelled', { exact: true })).toBeVisible();
    await expect(cancelledSection.getByRole('article')).toHaveCount(2);
    await expect(markers).toHaveCount(0);
});

test('renders untrusted appointment text safely and validates the booking date', async ({
    page,
    isMobile,
}) => {
    await register(page, isMobile);
    await fillBooking(page, '<img src=x onerror=alert(1)>');
    await page.getByLabel('Date', { exact: true }).fill('2000-01-01');
    await page.getByRole('button', { name: 'Reserve appointment', exact: true }).click();
    await expect(page.getByLabel('Date', { exact: true })).toBeFocused();
    expect(
        await page
            .getByLabel('Date', { exact: true })
            .evaluate((input: HTMLInputElement) => input.validity.rangeUnderflow),
    ).toBe(true);
    await page
        .getByLabel('Date', { exact: true })
        .fill(new Date(Date.now() + 86_400_000).toISOString().slice(0, 10));
    await page.getByRole('button', { name: 'Reserve appointment', exact: true }).click();
    await expect(
        page.getByRole('article', { name: '<img src=x onerror=alert(1)>', exact: true }),
    ).toBeVisible();
    await expect(page.locator('.appointment-card img')).toHaveCount(0);
});

test('defaults to a future time and blocks past times in the appointment timezone', async ({
    page,
    isMobile,
}) => {
    await register(page, isMobile);
    await page.clock.setFixedTime('2026-09-23T12:07:00Z');
    await page.goto('/appointments?date=2026-09-23&timezone=Africa%2FCairo');
    await page.getByRole('button', { name: 'New appointment', exact: true }).click();
    const date = page.getByLabel('Date', { exact: true });
    const time = page.getByLabel('Start time');
    await expect(date).toHaveValue('2026-09-23');
    await expect(time).toHaveValue('15:15');
    await expect(time).toHaveAttribute('min', '15:08');
    await page.getByLabel('Appointment title').fill('Future booking');
    await page.getByLabel('Customer name').fill('Sam');
    await page.getByLabel('Customer email').fill('sam@example.com');
    await time.fill('10:00');
    let submissions = 0;
    page.on('request', (request) => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === '/appointments') submissions++;
    });
    await page.getByRole('button', { name: 'Reserve appointment', exact: true }).click();
    await expect(time).toBeFocused();
    expect(await time.evaluate((input: HTMLInputElement) => input.validity.rangeUnderflow)).toBe(true);
    expect(submissions).toBe(0);

    await page.getByRole('button', { name: 'Close dialog' }).click();
    await page.clock.setFixedTime('2026-09-23T20:59:59Z');
    await page.getByRole('button', { name: 'New appointment', exact: true }).click();
    await expect(date).toHaveValue('2026-09-24');
    await expect(time).toHaveValue('00:00');
    await page.clock.setFixedTime('2026-09-23T21:00:01Z');
    await page.getByLabel('Appointment title').fill('Form left open');
    await page.getByLabel('Customer name').fill('Sam');
    await page.getByLabel('Customer email').fill('sam@example.com');
    await page.getByRole('button', { name: 'Reserve appointment', exact: true }).click();
    await expect(page.getByRole('alert')).toHaveText('Choose a future time.');
    expect(submissions).toBe(0);
    await page.screenshot({
        path: `test-results/future-booking-${isMobile ? 'mobile' : 'desktop'}.png`,
        fullPage: true,
        animations: 'disabled',
    });
});

test('send an existing appointment to Google, inspect failures across dates and retry from the sidebar', async ({
    page,
    isMobile,
}) => {
    const email = await register(page, isMobile);
    await fillBooking(page, 'Existing local appointment');
    await page.getByRole('button', { name: 'Reserve appointment', exact: true }).click();
    const appointment = page.getByRole('article', { name: 'Existing local appointment', exact: true });
    await expect(appointment).toContainText('Saved locally');
    googleFixture('connect', email);
    await page.reload();
    await appointment.getByRole('button', { name: 'Sync to Google' }).click();
    await expect(page.getByRole('option', { name: 'Read-only calendar (read only)' })).toBeDisabled();
    await page.getByRole('button', { name: 'Sync appointment', exact: true }).click();
    await expect(appointment).toContainText('Syncing to Google');
    await expect(page.getByRole('status')).toHaveText('Google calendar selected.');
    googleFixture('fail', email);
    await expect(appointment).toContainText('Failed to sync', { timeout: 12_000 });
    await expect(page.getByRole('status')).not.toBeVisible();
    await page.getByRole('button', { name: 'Previous week' }).click();
    await expect(appointment).not.toBeVisible();
    if (isMobile) await page.getByRole('button', { name: 'Calendars & dates' }).click();
    const sidebar = page.getByRole('region', { name: 'Google sync', exact: true });
    await expect(sidebar).toContainText('Existing local appointment');
    await expect(sidebar).toContainText('Check calendar permissions');
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({
        path: `test-results/sync-sidebar-${isMobile ? 'mobile' : 'desktop'}.png`,
        fullPage: true,
        animations: 'disabled',
    });
    await sidebar.getByRole('button', { name: 'Retry sync for Existing local appointment' }).click();
    await expect(sidebar).toContainText('Syncing');
    await sidebar.getByRole('button', { name: 'Existing local appointment', exact: true }).click();
    await expect(appointment).toContainText('Syncing to Google');
    googleFixture('sync');
    await page.reload();
    await expect(appointment).toContainText('Synced to Google');
    await appointment.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.getByRole('button', { name: 'Cancel appointment', exact: true }).click();
    await expandCancelled(page);
    await expect(appointment).toContainText('Removing from Google');
    await expect(page.getByRole('status')).toHaveText('Appointment cancelled.');
    googleFixture('sync');
    await expect(appointment).toContainText('Removed from Google', { timeout: 12_000 });
    await expect(page.getByRole('status')).not.toBeVisible();

    await fillBooking(page, 'New Google appointment');
    await expect(page.getByRole('dialog').getByLabel('Google calendar', { exact: true })).toHaveValue(
        /work-/,
    );
    await page.getByRole('button', { name: 'Reserve appointment', exact: true }).click();
    googleFixture('sync');
    await page.reload();
    const newAppointment = page.getByRole('article', { name: 'New Google appointment', exact: true });
    await expect(newAppointment).toContainText('Synced to Google');
    googleFixture('events-preserve-bookings', email);
    await expect(page.getByRole('button', { name: 'Refresh appointments' })).toBeEnabled();
    const originalDate = new URL(page.url()).searchParams.get('date');
    await page.getByRole('button', { name: 'Next week' }).click();
    await expect(page).not.toHaveURL(new RegExp(`date=${originalDate}`));
    await page.getByRole('button', { name: 'Previous week' }).click();
    await expect(page).toHaveURL(new RegExp(`date=${originalDate}`));
    await expect(page.getByRole('button', { name: 'Refresh appointments' })).toBeEnabled();
    await expect(newAppointment).toContainText('Synced to Google');
    await page.getByRole('button', { name: 'Refresh appointments' }).click();
    await expect(page.getByRole('button', { name: 'Syncing appointments...' })).toBeDisabled();
    googleFixture('events-delete-bookings', email);
    await expandCancelled(page);
    await expect(
        page
            .getByRole('region', { name: 'Cancelled appointments' })
            .getByRole('article', { name: 'New Google appointment' }),
    ).toContainText('Removed from Google');
    await expect(page.getByRole('button', { name: 'Refresh appointments' })).toBeEnabled();
});

test('shows saved Google events, checks in the background and keeps data when refresh fails', async ({
    page,
    isMobile,
}, testInfo) => {
    const email = await register(page, isMobile);
    googleFixture('connect', email);
    googleFixture('events-seed', email);
    await page.goto('/appointments?date=2026-11-01&timezone=UTC');
    const meeting = page.getByRole('article', { name: 'Team review', exact: true });
    const holiday = page.getByRole('article', { name: 'Team day off' });
    await expect(meeting).toBeVisible();
    await expect(holiday).toContainText('All day');
    await expect(holiday.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Syncing appointments...' })).toBeDisabled();
    if (isMobile) await page.getByRole('button', { name: 'Calendars & dates' }).click();
    await page.getByRole('checkbox', { name: 'Read-only calendar' }).uncheck();
    await expect(holiday).not.toBeVisible();
    await expect(meeting).toBeVisible();
    await page.getByRole('checkbox', { name: 'Read-only calendar' }).check();
    await expect(holiday).toBeVisible();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.screenshot({
        path: `test-results/google-events-${testInfo.project.name}.png`,
        fullPage: true,
    });
    if (isMobile) await page.getByRole('button', { name: 'Calendars & dates' }).click();
    googleFixture('events-fail', email);
    await expect(page.getByRole('alert')).toContainText('Work calendar');
    await expect(meeting).toBeVisible();
    await expect(holiday).toBeVisible();
    await page.getByRole('button', { name: 'Refresh appointments' }).click();
    await expect(page.getByRole('button', { name: 'Syncing appointments...' })).toBeDisabled();
    googleFixture('events-update', email);
    await expect(page.getByRole('article', { name: 'Updated team review' })).toBeVisible();
    await expect(meeting).not.toBeVisible();
    await expect(page.getByRole('alert')).not.toBeVisible();
    await page.reload();
    await expect(page.getByRole('article', { name: 'Updated team review' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Refresh appointments' })).toBeEnabled();
    const originalDate = new URL(page.url()).searchParams.get('date');
    await page.getByRole('button', { name: 'Next week' }).click();
    await expect(page).not.toHaveURL(new RegExp(`date=${originalDate}`));
    await page.getByRole('button', { name: 'Previous week' }).click();
    await expect(page).toHaveURL(new RegExp(`date=${originalDate}`));
    await expect(page.getByRole('article', { name: 'Updated team review' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Refresh appointments' })).toBeEnabled();
    googleFixture('schedule');
    googleFixture('events-empty', email);
    await expandCancelled(page);
    await expect(
        page.getByRole('region', { name: 'Cancelled appointments' }).getByRole('article'),
    ).toHaveCount(2, { timeout: 20_000 });
    await page.getByRole('button', { name: 'New appointment', exact: true }).click();
    const choices = page.getByRole('dialog').getByLabel('Google calendar', { exact: true });
    await expect(choices).toHaveValue(/work-/);
    await expect(
        choices.getByRole('option', { name: 'Read-only calendar (read only)', exact: true }),
    ).toBeDisabled();
});

test('cancels an imported appointment locally and retries failed Google removal', async ({
    page,
    isMobile,
}, testInfo) => {
    const email = await register(page, isMobile);
    googleFixture('connect', email);
    googleFixture('events-seed', email);
    await page.goto('/appointments?date=2026-11-01&timezone=UTC');
    googleFixture('events-update', email);
    const meeting = page.getByRole('article', { name: 'Updated team review' });
    await expect(meeting).toBeVisible();
    await meeting.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Keep appointment' })).toBeFocused();
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
    await page.getByRole('button', { name: 'Keep appointment' }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await meeting.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.getByRole('button', { name: 'Cancel appointment', exact: true }).click();
    await expandCancelled(page);
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(meeting).toContainText('Cancelled');
    await expect(meeting).toContainText('Removing from Google');
    googleFixture('fail', email);
    await expect(meeting).toContainText('Failed to sync');
    await page.screenshot({
        path: `test-results/google-delete-${testInfo.project.name}.png`,
        fullPage: true,
    });
    await meeting.getByRole('button', { name: 'Retry sync' }).click();
    googleFixture('sync');
    await expect(meeting).toContainText('Removed from Google');
    await expect(page.getByRole('article', { name: 'Team day off' })).toBeVisible();
    await page.reload();
    await expandCancelled(page);
    await expect(
        page.getByRole('region', { name: 'Cancelled appointments' }).getByRole('article'),
    ).toContainText('Updated team review');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('edits a local booking, then syncs further edits to its Google destination', async ({
    page,
    isMobile,
}) => {
    const email = await register(page, isMobile);
    await fillBooking(page, 'Original booking');
    await page.getByRole('button', { name: 'Reserve appointment', exact: true }).click();
    let card = page.getByRole('article', { name: 'Original booking', exact: true });
    await card.getByRole('button', { name: 'Edit', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Edit appointment' })).toBeVisible();
    await page.getByLabel('Appointment title').fill('Edited booking');
    await page.getByLabel('Start time').fill('15:00');
    await page.getByLabel('Duration (min)').fill('45');
    await page.getByRole('button', { name: 'Save changes' }).click();
    card = page.getByRole('article', { name: 'Edited booking', exact: true });
    await expect(card).toContainText('3:00 PM');
    await expect(card).toContainText('45 min');
    await expect(card).toContainText('Saved locally');
    googleFixture('connect', email);
    await page.reload();
    await card.getByRole('button', { name: 'Sync to Google', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Sync appointment', exact: true }).click();
    googleFixture('sync');
    await expect(card).toContainText('Synced to Google', { timeout: 12_000 });
    await card.getByRole('button', { name: 'Edit', exact: true }).click();
    await expect(page.getByLabel('Google calendar', { exact: true })).toBeDisabled();
    await page.getByLabel('Customer name').fill('Sam Edited');
    await page.screenshot({
        path: `test-results/edit-booking-${isMobile ? 'mobile' : 'desktop'}.png`,
        fullPage: true,
    });
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(card).toContainText('Syncing to Google');
    googleFixture('sync');
    await expect(card).toContainText('Synced to Google', { timeout: 12_000 });
    await expect(card).toContainText('Sam Edited');
});

for (const allDay of [false, true]) {
    test(`edits an imported ${allDay ? 'all-day' : 'timed'} appointment with required customer fields and local-first delivery`, async ({
        page,
        isMobile,
    }) => {
        const email = await register(page, isMobile);
        googleFixture('connect', email);
        googleFixture(allDay ? 'events-seed-all-day' : 'events-seed', email);
        await page.goto('/appointments?date=2026-11-01&timezone=UTC');
        const card = page
            .getByRole('article', { name: allDay ? 'Team day off' : 'Team review', exact: true })
            .filter({ has: page.getByRole('button', { name: 'Edit', exact: true }) });
        await card.getByRole('button', { name: 'Edit', exact: true }).click();
        await expect(page.getByRole('dialog', { name: 'Edit appointment' })).toBeVisible();
        await expect(page.getByLabel('Appointment title')).toBeFocused();
        await expect(page.getByLabel('Customer name')).toHaveValue('');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByLabel('Customer name')).toBeFocused();
        await page.getByLabel('Appointment title').fill('Edited appointment');
        await page.getByLabel('Customer name').fill('Sam Rivera');
        await page.getByLabel('Customer email').fill('sam@example.com');
        await page.getByLabel('Date', { exact: true }).fill('2026-11-03');
        await page.getByLabel(allDay ? 'Duration (days)' : 'Duration (min)').fill(allDay ? '2' : '60');
        if (!allDay) await page.getByLabel('Start time').fill('16:00');
        expect((await new AxeBuilder({ page }).include('dialog').analyze()).violations).toEqual([]);
        await page.screenshot({
            path: `test-results/edit-imported-${allDay ? 'all-day' : 'timed'}-${isMobile ? 'mobile' : 'desktop'}.png`,
            fullPage: true,
        });
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page).toHaveURL(/date=2026-11-03/);
        await expect(page.getByRole('dialog')).not.toBeVisible();
        const edited = page.getByRole('article', { name: 'Edited appointment', exact: true });
        await expect(edited).toContainText(allDay ? 'All day' : '4:00 PM');
        await expect(edited).toContainText('Sam Rivera');
        googleFixture('fail', email);
        await expect(edited).toContainText('Failed to sync');
        await edited.getByRole('button', { name: 'Retry sync' }).click();
        googleFixture('sync');
        await expect(edited).toContainText('Synced to Google');
        await page.reload();
        await expect(edited).toBeVisible();
        await edited.getByRole('button', { name: 'Edit', exact: true }).click();
        await expect(page.getByLabel('Customer email')).toHaveValue('sam@example.com');
        await expect(page.getByLabel(allDay ? 'Duration (days)' : 'Duration (min)')).toHaveValue(
            allDay ? '2' : '60',
        );
    });
}
