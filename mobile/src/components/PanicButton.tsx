/**
 * Botón de pánico.
 *
 * Pide **confirmación en pantalla** antes de enviar (decisión del cliente): sin
 * eso, el botón se dispara solo en el bolsillo y las alertas críticas pierden
 * credibilidad. La confirmación es de un toque y con el botón grande, para que
 * no cueste en una emergencia real.
 *
 * El envío no depende de la jornada ni de la conexión: el servidor acepta el
 * pánico siempre, y si no hay red se reintenta.
 */

import { useEffect, useState } from 'react';

interface Props {
  onConfirm: () => Promise<void>;
}

type State = 'idle' | 'confirming' | 'sending' | 'sent' | 'failed';

export function PanicButton({ onConfirm }: Props) {
  const [state, setState] = useState<State>('idle');

  // La confirmación se cancola sola: un modal abierto por error no puede quedar
  // tapando la pantalla.
  useEffect(() => {
    if (state !== 'confirming') return;
    const timer = setTimeout(() => setState('idle'), 10_000);
    return () => clearTimeout(timer);
  }, [state]);

  useEffect(() => {
    if (state !== 'sent') return;
    const timer = setTimeout(() => setState('idle'), 6_000);
    return () => clearTimeout(timer);
  }, [state]);

  const send = async () => {
    setState('sending');
    try {
      await onConfirm();
      setState('sent');
    } catch {
      setState('failed');
    }
  };

  if (state === 'sent') {
    return (
      <div className="panic panic--sent" role="status">
        <strong>Alerta enviada</strong>
        <span>Tu empresa y la guardia fueron avisadas.</span>
      </div>
    );
  }

  if (state === 'confirming' || state === 'sending') {
    return (
      <div className="panic panic--confirm" role="alertdialog" aria-label="Confirmar pánico">
        <strong>¿Enviar alerta de pánico?</strong>
        <div className="panic-actions">
          <button
            type="button"
            className="btn btn--danger"
            onClick={send}
            disabled={state === 'sending'}
          >
            {state === 'sending' ? 'Enviando…' : 'Sí, enviar'}
          </button>
          <button
            type="button"
            className="btn btn--ghost"
            onClick={() => setState('idle')}
            disabled={state === 'sending'}
          >
            Cancelar
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="panic">
      <button type="button" className="btn btn--panic" onClick={() => setState('confirming')}>
        PÁNICO
      </button>
      {state === 'failed' && (
        <p className="alert alert--error">
          No se pudo enviar. Probá de nuevo; si no hay señal, seguí intentando.
        </p>
      )}
    </div>
  );
}
