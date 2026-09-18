# Farmacia

El vademécum de medicamentos del CESFAM: arsenal farmacológico con información de
seguridad clínica (categoría FDA en embarazo, ajuste renal, riesgo colinérgico en
adulto mayor). App propia de APS Conecta Gestión — **lab app, no va en un release**
(ADR-0005). No contiene datos de pacientes; no gestiona stock.

## Estado

En desarrollo. Véase `CONTEXT.md` para el glosario del dominio.

## Desarrollo

Clonar bajo el id de la app dentro de la suite y declararla como lab app:

    git clone https://github.com/APS-Conecta/farmacia.git ../gestion/apps/farmacia
    # dev/lab-apps.sh: extender la única línea LAB_APPS con
    # farmacia=https://github.com/APS-Conecta/farmacia.git
    make -C ../gestion fix-mount-perms && make -C ../gestion seed   # habilita la app

Comandos (dentro de apps/farmacia):

    npm ci && npm run build   # js/farmacia-main.js — el bundle se commitea
    make test                 # suite unit en contenedor PHP pelado
    make integration          # suite de integración contra NC34 + PostgreSQL 18
    npm test                  # vitest (src/*.test.js)
    npm run lint-js           # eslint (src)