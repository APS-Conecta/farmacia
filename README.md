# Farmacia

El vademécum de medicamentos del CESFAM: arsenal farmacológico con información de
seguridad clínica (categoría FDA en embarazo, ajuste renal, riesgo colinérgico en
adulto mayor). **App propia del release** — declarada en `OWN_APPS`
(`gestion/provisioning/phases/12-apps.sh`) y empaquetada como tarball bajo
`provisioning/apps/farmacia/`, como territorio y epidemiologia (ADR-0003).
No contiene datos de pacientes; no gestiona stock.

## Estado

En desarrollo. Véase `CONTEXT.md` para el glosario del dominio.

## Desarrollo

Clonar bajo el id de la app dentro de la suite (la app ya es propia, no lab —
la máquina de desarrollo la monta como clone vivo y `ensure_own_app` lo respeta):

    git clone https://github.com/APS-Conecta/farmacia.git ../gestion/apps/farmacia
    make -C ../gestion fix-mount-perms && make -C ../gestion seed   # habilita la app

Comandos (dentro de apps/farmacia):

    npm ci && npm run build   # js/farmacia-main.js — el bundle se commitea
    make test                 # suite unit en contenedor PHP pelado
    make integration          # suite de integración contra NC34 + PostgreSQL 18
    npm test                  # vitest (src/*.test.js)
    npm run lint-js           # eslint (src)