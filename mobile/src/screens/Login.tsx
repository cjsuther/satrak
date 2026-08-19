/**
 * Login: empresa + DNI + contraseña.
 *
 * Se avisa por adelantado que iniciar sesión acá cierra la sesión de cualquier
 * otro teléfono: es una regla del sistema y la persona tiene que enterarse antes
 * de quedarse sin rastreo en el equipo viejo.
 */

import { useState, type FormEvent } from 'react';
import { DEFAULT_BASE_URL } from '../state/session';

export interface LoginValues {
  companySlug: string;
  dni: string;
  password: string;
}

interface Props {
  baseUrl: string;
  busy: boolean;
  error: string | null;
  onSubmit: (values: LoginValues) => void;
  onChangeBaseUrl: (url: string) => void;
}

export function Login({ baseUrl, busy, error, onSubmit, onChangeBaseUrl }: Props) {
  const [companySlug, setCompanySlug] = useState('');
  const [dni, setDni] = useState('');
  const [password, setPassword] = useState('');
  const [showServer, setShowServer] = useState(false);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (busy) return;
    onSubmit({ companySlug: companySlug.trim(), dni: dni.trim(), password });
  };

  return (
    <form className="screen screen--login" onSubmit={submit}>
      <h1 className="brand">
        satrak<span className="brand-dot">.</span>
      </h1>
      <p className="muted">Ingresá con los datos que te dio tu empresa.</p>

      <label className="field">
        <span>Empresa</span>
        <input
          value={companySlug}
          onChange={(e) => setCompanySlug(e.target.value)}
          autoCapitalize="none"
          autoCorrect="off"
          placeholder="ej. transportes-comahue"
          required
        />
      </label>

      <label className="field">
        <span>DNI</span>
        <input
          value={dni}
          onChange={(e) => setDni(e.target.value)}
          inputMode="numeric"
          autoComplete="username"
          required
        />
      </label>

      <label className="field">
        <span>Contraseña</span>
        <input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          autoComplete="current-password"
          required
        />
      </label>

      {error && <p className="alert alert--error">{error}</p>}

      <button className="btn btn--primary" type="submit" disabled={busy}>
        {busy ? 'Ingresando…' : 'Ingresar'}
      </button>

      <p className="note">
        Sólo podés tener la sesión abierta en un teléfono. Si entrás acá, se cierra
        la del equipo anterior.
      </p>

      <button type="button" className="link" onClick={() => setShowServer((v) => !v)}>
        {showServer ? 'Ocultar servidor' : 'Cambiar servidor'}
      </button>
      {showServer && (
        <label className="field">
          <span>Servidor</span>
          <input
            value={baseUrl}
            onChange={(e) => onChangeBaseUrl(e.target.value)}
            autoCapitalize="none"
            autoCorrect="off"
            placeholder={DEFAULT_BASE_URL}
          />
        </label>
      )}
    </form>
  );
}
