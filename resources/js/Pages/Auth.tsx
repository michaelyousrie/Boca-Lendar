import { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import Brand from '../Components/Brand';
import Field from '../Components/Field';

export default function Auth({ register }: { register: boolean }) {
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '', remember: false });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(register ? '/register' : '/login', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    }
    return (
        <>
            <Head title={register ? 'Create account' : 'Sign in'} />
            <main className="auth-layout">
                <section className="auth-form-panel">
                    <div className="auth-brand">
                        <Brand />
                    </div>
                    <div className="auth-form">
                        <h1>{register ? 'Create account' : 'Sign in'}</h1>
                        <form onSubmit={submit}>
                            {register && (
                                <Field id="name" label="Your name" error={form.errors.name}>
                                    <input
                                        id="name"
                                        autoComplete="name"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        required
                                        autoFocus
                                        maxLength={100}
                                        aria-invalid={Boolean(form.errors.name)}
                                        aria-describedby={form.errors.name ? 'name-error' : undefined}
                                    />
                                </Field>
                            )}
                            <Field id="email" label="Email address" error={form.errors.email}>
                                <input
                                    id="email"
                                    type="email"
                                    autoComplete="email"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                    required
                                    autoFocus={!register}
                                    maxLength={254}
                                    aria-invalid={Boolean(form.errors.email)}
                                    aria-describedby={form.errors.email ? 'email-error' : undefined}
                                />
                            </Field>
                            <Field
                                id="password"
                                label="Password"
                                error={form.errors.password}
                                hint={register ? 'At least 10 characters.' : undefined}
                            >
                                <input
                                    id="password"
                                    type="password"
                                    autoComplete={register ? 'new-password' : 'current-password'}
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                    required
                                    aria-invalid={Boolean(form.errors.password)}
                                    aria-describedby={form.errors.password ? 'password-error' : undefined}
                                />
                            </Field>
                            {register && (
                                <Field id="password_confirmation" label="Confirm password">
                                    <input
                                        id="password_confirmation"
                                        type="password"
                                        autoComplete="new-password"
                                        value={form.data.password_confirmation}
                                        onChange={(e) =>
                                            form.setData('password_confirmation', e.target.value)
                                        }
                                        required
                                    />
                                </Field>
                            )}
                            {!register && (
                                <label className="checkbox-label">
                                    <input
                                        type="checkbox"
                                        checked={form.data.remember}
                                        onChange={(e) => form.setData('remember', e.target.checked)}
                                    />
                                    Keep me signed in
                                </label>
                            )}
                            <button className="button primary full-width" disabled={form.processing}>
                                {form.processing && <LoaderCircle size={17} className="spin" />}
                                {register ? 'Create account' : 'Sign in'}
                            </button>
                        </form>
                        <p className="auth-switch">
                            <Link href={register ? '/login' : '/register'}>
                                {register ? 'Sign in' : 'Create account'}
                            </Link>
                        </p>
                    </div>
                </section>
            </main>
        </>
    );
}
