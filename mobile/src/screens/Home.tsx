/**
 * Pantalla principal: en qué estado está la persona y qué se está registrando.
 *
 * Lo primero que se ve es si está o no en jornada, porque es lo que determina si
 * se la está rastreando. Decírselo de forma explícita es parte del consentimiento
 * informado (Ley 25.326), no un detalle de UI.
 */

import type { Mission, Post } from '../api/types';
import { openAppSettings } from '../tracking/plugin';
import type { TrackerStatus } from '../tracking/tracker';
import { distanceToGeofence, formatDistance } from '../tracking/geo';
import { PanicButton } from '../components/PanicButton';

interface Props {
  personName: string;
  company: string;
  status: TrackerStatus;
  post: Post | null;
  missions: Mission[];
  /** La empresa puede tener el pánico desactivado. */
  panicEnabled: boolean;
  syncing: boolean;
  onSync: () => void;
  onPanic: () => Promise<void>;
  onOpenMissions: () => void;
  onLogout: () => void;
}

export function Home({
  personName,
  company,
  status,
  post,
  missions,
  panicEnabled,
  syncing,
  onSync,
  onPanic,
  onOpenMissions,
  onLogout,
}: Props) {
  const active = missions.filter((m) => m.status === 'pending' || m.status === 'in_progress');

  const here =
    status.lastLat !== null && status.lastLon !== null
      ? { lat: status.lastLat, lon: status.lastLon }
      : null;

  /** Texto de distancia a una geocerca, o null si todavía no hay fix. */
  const distanceTo = (
    shape: 'circle' | 'polygon',
    geometry: Post['geometry'],
  ): { text: string; inside: boolean } | null => {
    if (!here) return null;
    const d = distanceToGeofence(here, shape, geometry);
    if (!d) return null;

    return {
      text: d.inside ? 'Estás adentro' : `A ${formatDistance(d.meters)}`,
      inside: d.inside,
    };
  };

  const postDistance = post ? distanceTo(post.shape, post.geometry) : null;

  return (
    <div className="screen">
      <header className="topbar">
        <div>
          <strong>{personName}</strong>
          <span className="muted"> · {company}</span>
        </div>
        <button type="button" className="link" onClick={onLogout}>
          Salir
        </button>
      </header>

      <section className={`status-card ${status.onShift ? 'status-card--on' : 'status-card--off'}`}>
        {status.onShift ? (
          <>
            <strong>En jornada</strong>
            <p>Se está registrando tu ubicación.</p>
          </>
        ) : (
          <>
            <strong>Fuera de jornada</strong>
            <p>No se está registrando tu ubicación.</p>
          </>
        )}
      </section>

      {status.permissionDenied && (
        <div className="alert alert--error">
          <p>
            Falta el permiso de ubicación en segundo plano. Sin eso la app no puede
            registrar tu recorrido durante la jornada.
          </p>
          <button type="button" className="btn btn--ghost" onClick={() => void openAppSettings()}>
            Abrir ajustes del teléfono
          </button>
        </div>
      )}

      {panicEnabled && <PanicButton onConfirm={onPanic} />}

      <section className="card">
        <h2>Mi puesto</h2>
        {post ? (
          <p>
            <strong>{post.name}</strong>
            <br />
            {postDistance ? (
              <span className={postDistance.inside ? 'distance distance--in' : 'distance'}>
                {postDistance.text}
              </span>
            ) : (
              <span className="muted">Esperando la ubicación…</span>
            )}
            <br />
            <span className="muted">Tolerancia {post.grace_min} min fuera del puesto.</span>
          </p>
        ) : (
          <p className="muted">No tenés un puesto asignado.</p>
        )}
      </section>

      <section className="card">
        <h2>Misiones de hoy</h2>
        {active.length === 0 ? (
          <p className="muted">No tenés misiones pendientes.</p>
        ) : (
          <ul className="list">
            {active.map((m) => (
              <li key={m.id}>
                <strong>{m.destination.name}</strong>
                <span className="muted">
                  {' '}
                  {m.scheduled_start.slice(11, 16)}–{m.scheduled_end.slice(11, 16)}
                </span>
                {(() => {
                  const d = distanceTo(m.destination.shape, m.destination.geometry);

                  return d ? (
                    <>
                      <br />
                      <span className={d.inside ? 'distance distance--in' : 'distance'}>
                        {d.text}
                      </span>
                    </>
                  ) : null;
                })()}
              </li>
            ))}
          </ul>
        )}
        <button type="button" className="btn btn--ghost" onClick={onOpenMissions}>
          Ver misiones
        </button>
      </section>

      <section className="card card--diag">
        <h2>Estado del equipo</h2>
        <dl>
          <div>
            <dt>Puntos sin subir</dt>
            <dd>{status.pending}</dd>
          </div>
          <div>
            <dt>Última ubicación</dt>
            <dd>{status.lastFixAt ? timeOf(status.lastFixAt) : '—'}</dd>
          </div>
          <div>
            <dt>Coordenadas</dt>
            <dd className="mono">
              {here ? `${here.lat.toFixed(5)}, ${here.lon.toFixed(5)}` : '—'}
            </dd>
          </div>
          <div>
            <dt>Precisión</dt>
            <dd>{status.lastAccuracyM !== null ? `± ${status.lastAccuracyM} m` : '—'}</dd>
          </div>
          <div>
            <dt>Última subida</dt>
            <dd>{status.lastUploadAt ? timeOf(status.lastUploadAt) : '—'}</dd>
          </div>
          <div>
            <dt>Batería</dt>
            <dd>{status.batteryPct !== null ? `${status.batteryPct}%` : '—'}</dd>
          </div>
        </dl>
        {status.lastError && <p className="muted small">Último aviso: {status.lastError}</p>}
        <button type="button" className="btn btn--ghost" onClick={onSync} disabled={syncing}>
          {syncing ? 'Actualizando…' : 'Actualizar ahora'}
        </button>
      </section>
    </div>
  );
}

function timeOf(date: Date): string {
  const h = date.getHours();
  const m = date.getMinutes();
  return `${h < 10 ? '0' : ''}${h}:${m < 10 ? '0' : ''}${m}`;
}
