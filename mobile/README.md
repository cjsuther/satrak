# Satrak Campo — app móvil

App de campo del **módulo de personas**: rastrea a la persona **solo durante su
jornada**, muestra su puesto y sus misiones, y tiene el botón de pánico.

React + TypeScript + Vite, empaquetada con **Capacitor 6**. El diseño y las
decisiones están en [`../application/satrak-modulo-personas-spec.md`](../application/satrak-modulo-personas-spec.md)
(§5 el contrato de la API, §9 el stack).

---

## Compatibilidad

| | Piso |
|---|---|
| Android | **5.1** (API 22) |
| iOS | **13** |

Capacitor 6 no baja de ahí. Por eso el build apunta a **ES2015** y el bundle se
mantiene chico (~56 KB gzip): en Android 5/6 el System WebView puede estar sin
actualizar.

## Cómo está armada

```
src/
├── api/          cliente HTTP y tipos del contrato /api/app/*
├── state/        sesión persistida (token, install_id, servidor)
├── tracking/
│   ├── shift.ts    jornada en el cliente — espejo de ShiftGuard del backend
│   ├── buffer.ts   cola en disco de puntos sin subir
│   ├── plugin.ts   registro del plugin nativo de geolocalización
│   └── tracker.ts  enciende/apaga la captura y sube por lotes
├── screens/      Login, Home, Missions
└── components/   PanicButton
```

### Las tres reglas que la app hace cumplir

1. **Fuera de la jornada no se captura nada.** `tracking/shift.ts` replica
   exactamente la lógica de `ShiftGuard` (cruce de medianoche, precedencia de
   excepciones) y el tracker apaga el watcher al terminar el turno. El servidor
   igual descarta lo que llegue fuera de turno: si este cálculo se equivocara, el
   peor caso es gastar batería, nunca guardar una posición indebida.
2. **El pánico pide confirmación en pantalla** antes de enviarse, para que no se
   dispare solo en el bolsillo, y se acepta siempre — dentro o fuera de turno.
3. **Una sola sesión por persona.** Si alguien inicia sesión en otro teléfono, la
   API devuelve 401 y esta app cierra la sesión sola.

### Huso horario

La jornada se evalúa en el huso de la **empresa**, no en el del teléfono. El
offset se saca del campo `server_time` que devuelve la API (ej. `-03:00`) en vez
de usar `Intl` con nombres de zona, que en un WebView viejo puede estar incompleto.

### Ubicación en segundo plano

`@capacitor-community/background-geolocation` (MIT). Corre **nativo**, fuera del
WebView: la app sigue reportando con la pantalla apagada.

> **Alternativa paga**: `@transistorsoft/capacitor-background-geolocation` es más
> robusto en OEM agresivos (Xiaomi, Huawei, Oppo) pero tiene licencia comercial
> para Android. Si en las pruebas de campo el plugin community pierde puntos en
> esos equipos, la migración toca sólo `src/tracking/plugin.ts` y `tracker.ts`.

---

## Desarrollo

```bash
npm install
npm run dev        # http://localhost:5173, proxea /api a 127.0.0.1:8099
```

Para el backend local, desde `../application`:

```bash
php -S 127.0.0.1:8099 -t public public/index.php
```

En el navegador no hay plugins nativos: el login, la jornada y las pantallas
funcionan, pero no hay captura en segundo plano.

### Tests

```bash
npm test       # jornada del cliente vs. los mismos casos que el test de ShiftGuard
npm run test:e2e   # cliente de la API contra el backend local (requiere el server arriba)
npm run typecheck
```

---

## Build nativo

Los proyectos `android/` e `ios/` **no están versionados**: se generan.

```bash
npm install
npx cap add android          # necesita Android Studio + SDK
npx cap add ios              # necesita Xcode (solo macOS)
npm run sync                 # build web + copia a los proyectos nativos
npm run android              # abre Android Studio
npm run ios                  # abre Xcode
```

### Permisos a declarar

**Android** (`android/app/src/main/AndroidManifest.xml`) — el plugin agrega la
mayoría; verificar que estén:

```xml
<uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION" />
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
<uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_LOCATION" />
```

**iOS** (`ios/App/App/Info.plist`):

```xml
<key>NSLocationAlwaysAndWhenInUseUsageDescription</key>
<string>Satrak registra tu ubicación únicamente durante tu jornada laboral.</string>
<key>NSLocationWhenInUseUsageDescription</key>
<string>Satrak registra tu ubicación únicamente durante tu jornada laboral.</string>
<key>UIBackgroundModes</key>
<array><string>location</string></array>
```

### Antes de publicar

- **Play Store**: hay que completar el formulario de declaración de ubicación en
  segundo plano, mostrar la divulgación destacada dentro de la app y publicar la
  política de privacidad. Las apps de seguimiento de personal se aprueban, pero
  con consentimiento explícito visible.
- **App Store**: revisan con lupa el uso de `Always`; hay que justificarlo y
  mostrar el consentimiento.
- **OEM chinos** (Xiaomi, Huawei, Oppo) matan servicios en segundo plano. Incluir
  la guía por fabricante para excluir la app del ahorro de batería, y asumir que
  ahí la frecuencia real va a ser peor que la configurada.
