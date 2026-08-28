/**
 * Raíz de la app: sesión, sincronización y ruteo entre las tres pantallas.
 *
 * No hay router: son tres vistas y en un WebView viejo cada dependencia cuesta.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Geolocation } from '@capacitor/geolocation';
import { ApiClient, UnauthorizedError } from './api/client';
import type { LoginPayload, Mission, Post, SyncPayload } from './api/types';
import { Home } from './screens/Home';
import { Login, type LoginValues } from './screens/Login';
import { Missions } from './screens/Missions';
import { parseUtcOffsetMinutes, toApiTimestamp, type ShiftSchedule } from './tracking/shift';
import { Tracker, type TrackerStatus } from './tracking/tracker';
import {
  clearSession,
  DEFAULT_BASE_URL,
  getBaseUrl,
  getDeviceInfo,
  getInstallId,
  loadSession,
  saveSession,
  setBaseUrl,
  type StoredSession,
} from './state/session';

const SYNC_MS = 5 * 60_000;
const APP_VERSION = '1.0.0';
/** Lo máximo que el pánico espera un fix antes de mandarse sin coordenadas. */
const PANIC_FIX_MS = 3_000;
/** Para «Llegué» sí conviene esperar: sin posición el servidor no puede validar. */
const ARRIVE_FIX_MS = 15_000;

type View = 'home' | 'missions';

export default function App() {
  const [booting, setBooting] = useState(true);
  const [session, setSession] = useState<StoredSession | null>(null);
  const [baseUrl, setBaseUrlState] = useState(DEFAULT_BASE_URL);
  const [view, setView] = useState<View>('home');
  const [busy, setBusy] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [loginError, setLoginError] = useState<string | null>(null);

  const [post, setPost] = useState<Post | null>(null);
  const [missions, setMissions] = useState<Mission[]>([]);
  const [panicEnabled, setPanicEnabled] = useState(true);
  const [offsetMinutes, setOffsetMinutes] = useState(0);
  const [status, setStatus] = useState<TrackerStatus | null>(null);

  const api = useMemo(() => new ApiClient(DEFAULT_BASE_URL), []);

  const handleSessionLost = useCallback(() => {
    void clearSession();
    setSession(null);
    setLoginError('Tu sesión se cerró porque iniciaste en otro teléfono.');
  }, []);

  const tracker = useMemo(() => new Tracker(api, handleSessionLost), [api, handleSessionLost]);
  const trackerRef = useRef(tracker);
  trackerRef.current = tracker;

  /** Aplica lo que devuelven `/login` y `/sync`. */
  const applySync = useCallback(
    (payload: SyncPayload) => {
      const offset = parseUtcOffsetMinutes(payload.server_time) ?? -180;
      const schedule: ShiftSchedule = {
        shifts: payload.shifts,
        exceptions: payload.shift_exceptions,
        offsetMinutes: offset,
      };
      setOffsetMinutes(offset);
      setPost(payload.post);
      setMissions(payload.missions);
      // Ausente => habilitado: un servidor viejo no debe apagar el pánico.
      setPanicEnabled(payload.config.panic_enabled !== false);
      trackerRef.current.configure(schedule, payload.config);
    },
    [],
  );

  // --- Arranque -------------------------------------------------------------
  useEffect(() => {
    void (async () => {
      const url = await getBaseUrl();
      setBaseUrlState(url);
      api.setBaseUrl(url);

      const stored = await loadSession();
      if (stored) {
        api.setToken(stored.token);
        setSession(stored);
      }
      setBooting(false);
    })();
  }, [api]);

  // --- Ciclo de vida del tracker -------------------------------------------
  useEffect(() => {
    if (!session) return;

    const unsubscribe = tracker.onStatus(setStatus);
    tracker.start();

    const sync = async () => {
      setSyncing(true);
      try {
        applySync(await api.sync());
        await api.heartbeat(
          tracker.getStatus().batteryPct,
          !tracker.getStatus().permissionDenied,
        );
      } catch (e) {
        if (e instanceof UnauthorizedError) handleSessionLost();
      } finally {
        setSyncing(false);
      }
    };

    void sync();
    const timer = setInterval(() => void sync(), SYNC_MS);

    return () => {
      clearInterval(timer);
      unsubscribe();
      void tracker.stop();
    };
  }, [session, tracker, api, applySync, handleSessionLost]);

  // --- Acciones -------------------------------------------------------------

  const doLogin = async (values: LoginValues) => {
    setBusy(true);
    setLoginError(null);
    try {
      api.setBaseUrl(baseUrl);
      await setBaseUrl(baseUrl);

      const device = await getDeviceInfo();
      const payload: LoginPayload = await api.login({
        company_slug: values.companySlug,
        dni: values.dni,
        password: values.password,
        install_id: await getInstallId(),
        platform: device.platform,
        os_version: device.osVersion,
        app_version: APP_VERSION,
        model: device.model,
      });

      api.setToken(payload.token);
      const stored: StoredSession = {
        token: payload.token,
        companySlug: values.companySlug,
        person: payload.person,
      };
      await saveSession(stored);
      applySync(payload);
      setSession(stored);
    } catch (e) {
      setLoginError(e instanceof Error ? e.message : 'No se pudo ingresar.');
    } finally {
      setBusy(false);
    }
  };

  const doLogout = async () => {
    try {
      await api.logout();
    } catch {
      // Aunque falle la llamada, la sesión local se cierra igual.
    }
    await tracker.stop();
    await clearSession();
    api.setToken(null);
    setSession(null);
    setView('home');
  };

  const doPanic = async () => {
    const at = new Date();
    // La alerta no espera al GPS: si en PANIC_FIX_MS no hay fix, sale sin
    // coordenadas. Que llegue tarde es peor que que llegue sin ubicación —el
    // servidor igual la marca crítica y avisa a la guardia.
    const position = await currentPosition(PANIC_FIX_MS);
    await api.panic(toApiTimestamp(at, offsetMinutes), position?.lat ?? null, position?.lon ?? null);
  };

  const doSync = async () => {
    setSyncing(true);
    try {
      applySync(await api.sync());
      await tracker.flush();
    } catch (e) {
      if (e instanceof UnauthorizedError) handleSessionLost();
    } finally {
      setSyncing(false);
    }
  };

  const startMission = async (id: number) => {
    await api.startMission(id);
    applySync(await api.sync());
  };

  const arriveMission = async (id: number) => {
    const position = await currentPosition(ARRIVE_FIX_MS);
    if (!position) {
      throw new Error('No se pudo obtener tu ubicación. Probá al aire libre.');
    }
    await api.arriveMission(id, position.lat, position.lon);
    applySync(await api.sync());
  };

  // --- Render ---------------------------------------------------------------

  if (booting) {
    return <div className="screen screen--center">Cargando…</div>;
  }

  if (!session) {
    return (
      <Login
        baseUrl={baseUrl}
        busy={busy}
        error={loginError}
        onSubmit={doLogin}
        onChangeBaseUrl={setBaseUrlState}
      />
    );
  }

  if (view === 'missions') {
    return (
      <Missions
        missions={missions}
        here={
          status && status.lastLat !== null && status.lastLon !== null
            ? { lat: status.lastLat, lon: status.lastLon }
            : null
        }
        onBack={() => setView('home')}
        onStart={startMission}
        onArrive={arriveMission}
      />
    );
  }

  return (
    <Home
      personName={`${session.person.first_name} ${session.person.last_name}`}
      company={session.person.company}
      status={status ?? trackerRef.current.getStatus()}
      post={post}
      missions={missions}
      panicEnabled={panicEnabled}
      syncing={syncing}
      onSync={doSync}
      onPanic={doPanic}
      onOpenMissions={() => setView('missions')}
      onLogout={doLogout}
    />
  );
}

/**
 * Posición puntual para pánico y llegada a misión. Se pide aparte del watcher
 * porque estas acciones no pueden esperar al próximo fix del segundo plano.
 *
 * El `timeout` del plugin no siempre corta a tiempo (en el navegador puede
 * colgarse hasta que el usuario responde el permiso), así que además se corre
 * una carrera contra un temporizador propio.
 */
async function currentPosition(timeoutMs: number): Promise<{ lat: number; lon: number } | null> {
  const fix = Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: timeoutMs })
    .then((position) => ({ lat: position.coords.latitude, lon: position.coords.longitude }))
    .catch(() => null);

  const expired = new Promise<null>((resolve) => setTimeout(() => resolve(null), timeoutMs));

  return Promise.race([fix, expired]);
}
