-- =============================================================================
-- 007 · `companies.panic_enabled`
--
-- El botón de pánico pasa a habilitarse por empresa. No todas lo quieren: hay
-- operaciones donde no hay guardia que atienda un pánico, y un botón que nadie
-- responde es peor que no tenerlo —promete un auxilio que no va a llegar.
--
-- NO va en `companies.modules`: ese SET dice qué CONTRATÓ la empresa (flota,
-- personal) y gobierna permisos vía Entitlements. El pánico es una función
-- dentro del módulo de personal, no algo que se contrate aparte. Sigue el
-- patrón de `emergency_email` y `timezone`: una columna de configuración.
--
-- Default 1: las empresas que ya lo tienen andando no lo pierden con la
-- migración. Desactivarlo es una decisión explícita del super admin.
-- =============================================================================

ALTER TABLE companies
  ADD COLUMN panic_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER emergency_email;
