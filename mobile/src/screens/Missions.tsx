/**
 * Misiones del día: iniciar y marcar llegada.
 *
 * La persona no puede crear misiones —las carga el operador—, así que acá sólo
 * hay dos acciones. «Llegué» manda la posición actual y el servidor valida que
 * esté efectivamente en el destino: si no, la rechaza.
 */

import { useState } from 'react';
import type { Mission } from '../api/types';

const LABELS: Record<Mission['status'], string> = {
  pending: 'Pendiente',
  in_progress: 'En curso',
  completed: 'Cumplida',
  missed: 'No cumplida',
  cancelled: 'Cancelada',
};

interface Props {
  missions: Mission[];
  onBack: () => void;
  onStart: (id: number) => Promise<void>;
  onArrive: (id: number) => Promise<void>;
}

export function Missions({ missions, onBack, onStart, onArrive }: Props) {
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const run = async (id: number, action: (id: number) => Promise<void>) => {
    setBusyId(id);
    setError(null);
    try {
      await action(id);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo completar la acción.');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="screen">
      <header className="topbar">
        <button type="button" className="link" onClick={onBack}>
          ← Volver
        </button>
        <strong>Mis misiones</strong>
      </header>

      {error && <p className="alert alert--error">{error}</p>}

      {missions.length === 0 && <p className="muted">No tenés misiones asignadas para hoy.</p>}

      {missions.map((m) => (
        <section className="card" key={m.id}>
          <h2>{m.destination.name}</h2>
          <p className="muted">
            {m.scheduled_start.slice(11, 16)} – {m.scheduled_end.slice(11, 16)} ·{' '}
            {LABELS[m.status]}
          </p>
          {m.notes && <p>{m.notes}</p>}

          {m.status === 'pending' && (
            <button
              type="button"
              className="btn btn--primary"
              onClick={() => run(m.id, onStart)}
              disabled={busyId === m.id}
            >
              {busyId === m.id ? 'Iniciando…' : 'Iniciar'}
            </button>
          )}
          {m.status === 'in_progress' && (
            <button
              type="button"
              className="btn btn--primary"
              onClick={() => run(m.id, onArrive)}
              disabled={busyId === m.id}
            >
              {busyId === m.id ? 'Confirmando…' : 'Llegué'}
            </button>
          )}
        </section>
      ))}
    </div>
  );
}
