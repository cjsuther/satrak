# Firma de la app Android

## Crear el keystore (una sola vez)

**Corré esto vos**: la contraseña la elegís vos y no debe pasar por ningún log.

```bash
keytool -genkeypair -v \
  -keystore mobile/android/satrak-campo.jks \
  -keyalg RSA -keysize 2048 -validity 10000 \
  -alias satrak
```

Te va a pedir una contraseña y algunos datos (nombre, organización, país: `AR`).

Después, copiá `keystore.properties.example` a `keystore.properties` y completá
`storePassword` y `keyPassword` con la que pusiste.

## Guardalo bien

El `.jks` y su contraseña **no se pueden recuperar**. Si los perdés y no tenés
activado *Play App Signing*, no podés volver a publicar una actualización de la
app: hay que publicar una app nueva, con otro ID, y pedirle a todos los usuarios
que la instalen de cero.

Con **Play App Signing** (recomendado, y activado por defecto en apps nuevas)
Google guarda la clave de firma final y ésta pasa a ser sólo la *clave de subida*:
si la perdés, se puede pedir un reemplazo. Aun así, guardá el `.jks` y la
contraseña en un gestor de contraseñas, no sólo en esta máquina.

## Compilar para publicar

Google Play recibe un **AAB** (Android App Bundle), no un APK:

```bash
cd mobile
npm run build && npx cap sync android
cd android && ./gradlew bundleRelease
# → app/build/outputs/bundle/release/app-release.aab
```

Para probar el build de release en un teléfono, el APK sirve:

```bash
./gradlew assembleRelease
# → app/build/outputs/apk/release/app-release.apk
```

## Versionado

En `app/build.gradle`:

- `versionCode`: entero, **tiene que subir en cada publicación**. Play rechaza
  un bundle con un versionCode ya usado.
- `versionName`: el texto que ve el usuario (`1.0.0`).
