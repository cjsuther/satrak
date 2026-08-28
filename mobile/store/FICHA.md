# Textos de ficha — Satrak Campo

Borradores para completar en Play Console y App Store Connect. Ajustalos a gusto;
lo que no conviene tocar es el encuadre: **esta app la instala un empleado porque
su empresa se la pidió, no alguien que la descubre buscando**. Prometer más que
eso es lo que hace que a una app de rastreo laboral le lluevan reseñas de una
estrella.

Los contadores de caracteres están verificados contra los límites de cada tienda.

---

## Google Play

**Título** · máx. 30

```
Satrak Campo
```

**Descripción breve** · máx. 80 — es lo que se ve en los resultados de búsqueda

```
Registro de jornada y ubicación para personal en campo. Solo en tu horario.
```

**Descripción completa** · máx. 4000

```
Satrak Campo es la aplicación que usa el personal de las empresas que contratan
Satrak para el seguimiento de su gente en el terreno.

No es una app que se descarga por cuenta propia: para usarla necesitás que tu
empleador te dé una empresa, un DNI y una contraseña.

QUÉ HACE

• Registra tu recorrido durante tu jornada laboral, para que quede constancia
  de dónde estuviste trabajando.
• Te muestra a cuántos metros estás de tu puesto asignado y de cada punto de tus
  misiones, así podés verificar que tu ubicación es la correcta.
• Te permite iniciar una misión y marcar la llegada.
• Incluye un botón de pánico que avisa de inmediato a la guardia de tu empresa,
  dentro o fuera de tu horario.

SOLO DURANTE TU JORNADA

La aplicación registra tu ubicación únicamente dentro del horario de trabajo que
tu empresa configuró. Fuera de ese horario la posición se descarta: no se guarda.
La restricción se aplica dos veces, en la aplicación y en el servidor.

La pantalla principal te muestra siempre, de forma permanente, si estás o no
dentro de la jornada, es decir, si en ese momento se está registrando tu
ubicación. Mientras el registro está activo, tu teléfono muestra además una
notificación fija. No hay seguimiento silencioso.

PENSADA PARA EL CAMPO

• Funciona sin señal: las posiciones se guardan en el teléfono y se suben cuando
  vuelve la conexión.
• Cuida la batería: baja la frecuencia de registro cuando estás detenido.
• Anda en teléfonos desde Android 5.1.

PERMISO DE UBICACIÓN EN SEGUNDO PLANO

La aplicación necesita acceder a tu ubicación también con la pantalla apagada.
Sin eso no puede registrar un recorrido completo, que es su única función. La
ubicación se usa exclusivamente para el registro de la jornada: no se comparte
con terceros, no se usa para publicidad y no se cruza con datos de otras
aplicaciones.

Política de privacidad: https://satrak.online/privacidad
```

**Categoría** · Empresa (Business)
**Etiquetas** · seguimiento, jornada laboral, personal, campo, flota

---

## App Store

**Nombre** · máx. 30

```
Satrak Campo
```

**Subtítulo** · máx. 30 — aparece bajo el nombre

```
Tu jornada, registrada
```

**Palabras clave** · máx. 100, separadas por coma, sin espacios

```
jornada,personal,campo,recorrido,ubicacion,empresa,turno,mision,rastreo,guardia
```

**Texto promocional** · máx. 170, se puede cambiar sin nueva versión

```
Registra tu recorrido solo durante tu horario de trabajo. Verificá a qué distancia estás de tu puesto y avisá con el botón de pánico.
```

**Descripción**

```
Satrak Campo es la aplicación que usa el personal de las empresas que contratan
Satrak para el seguimiento de su gente en el terreno.

Para usarla necesitás que tu empleador te dé una empresa, un DNI y una
contraseña: no es una app que se use por cuenta propia.

QUÉ HACE

• Registra tu recorrido durante tu jornada laboral.
• Te muestra a cuántos metros estás de tu puesto asignado y de cada punto de tus
  misiones, para que puedas verificar que tu ubicación es la correcta.
• Te permite iniciar una misión y marcar la llegada.
• Incluye un botón de pánico que avisa a la guardia de tu empresa, dentro o
  fuera de tu horario.

SOLO DURANTE TU JORNADA

La aplicación registra tu ubicación únicamente dentro del horario que tu empresa
configuró. Fuera de ese horario la posición se descarta: no se guarda. La
restricción se aplica en la aplicación y también en el servidor.

La pantalla principal te muestra siempre si estás o no dentro de la jornada, y
mientras el registro está activo el sistema muestra una notificación. No hay
seguimiento silencioso.

PENSADA PARA EL CAMPO

• Funciona sin señal: guarda las posiciones y las sube cuando vuelve la conexión.
• Cuida la batería: baja la frecuencia cuando estás detenido.

Política de privacidad: https://satrak.online/privacidad
```

**Categoría** · Negocios · secundaria: Utilidades

---

## Al completar los formularios de privacidad

Las dos tiendas piden declarar los datos por separado del texto de arriba, y
**tienen que coincidir** con `ios/App/App/PrivacyInfo.xcprivacy` y con la
política publicada. Si difieren, rechazo.

Lo que la app recolecta, vinculado a la identidad de la persona y **sin
seguimiento entre apps ni publicidad**:

| Dato | Finalidad |
|---|---|
| Ubicación precisa (incluye segundo plano) | Funcionalidad de la app |
| Identificador de dispositivo (`install_id`) | Funcionalidad de la app |
| Nombre y documento | Funcionalidad de la app |
| Diagnóstico del equipo (batería, modelo, versión) | Funcionalidad de la app |

Ojo con una palabra: para Apple, «tracking» significa **cruzar los datos con
otras empresas** con fines publicitarios. Que la app siga la posición de un
empleado NO es «tracking» en ese sentido, y por eso `NSPrivacyTracking` va en
`false`. Marcarlo en `true` obligaría a pedir permiso de App Tracking
Transparency, que no corresponde acá.

## Capturas

En `store/capturas/`. Las de Android salieron del Motorola Edge 30 Fusion
(1080×2400) y las de iOS del simulador de iPhone 17 Pro Max (1320×2868, que es
el tamaño de 6,9" que exige App Store).

Falta una captura de la pantalla de misiones en iOS: sacarla requiere tocar
«Ver misiones» en el simulador, y la automatización no puede hacerlo sin el
permiso de Accesibilidad de macOS. Se saca a mano en dos segundos.
