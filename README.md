# Farmacia

Aplicación de Nextcloud 34 para APS Conecta Gestión: el vademécum de medicamentos del CESFAM, con su cobertura (GES, canales de abastece) y su información de seguridad clínica (categoría FDA en embarazo, ajuste renal, riesgo colinérgico en adulto mayor).

## Qué es

El arsenal farmacológico del centro: cada medicamento que el CESFAM receta y despacha. **No guarda datos de pacientes y no gestiona stock.** El arsenal es una referencia de lo que el CESFAM usa, no un inventario de lo que hay en bodega: nada vence y nada se cuenta ([ADR-0004](docs/adr/0004-the-arsenal-is-a-vademecum-not-a-stock.md)). La interfaz está en español; todo lo demás, en inglés ([`AGENTS.md`](AGENTS.md)).

Toda persona con sesión iniciada puede consultar **y editar** el arsenal. La red de seguridad no son los permisos, sino el retiro reversible y la revisión fila por fila de toda importación: una decisión registrada, con sus razones, en [`docs/threat-model.md`](docs/threat-model.md).

| Vista | Qué es | De dónde viene |
| --- | --- | --- |
| **Arsenal Farmacológico** | Todos los medicamentos activos, con búsqueda por producto, fármaco (DCI) o categoría | Las tablas propias de la aplicación |
| **Fármacos en embarazo** | El mismo arsenal, abierto en las filas con categoría FDA | La regla de lente: conjunto de categorías FDA ([ADR-0002](docs/adr/0002-a-lens-changes-the-initial-filter-not-the-dataset.md)) |
| **Ajuste farmacológico ERC** | El mismo arsenal, abierto en las filas con ajuste renal | La regla de lente: conjunto de ajuste renal |
| «Ver retirados» | La pantalla de recuperación: registros retirados, restaurables en el lugar | La marca `retired` ([ADR-0003](docs/adr/0003-retirar-and-restaurar-are-logical-nothing-is-deleted.md)) |
| «Importar planilla» | La importación CSV en dos fases: revisión por etapas, decisión, aplicación y reversión | `farmacia_import` / `farmacia_import_row` ([ADR-0001](docs/adr/0001-the-planilla-is-a-projection-not-the-data.md)) |
| «Exportar planilla» | Todo el arsenal vivo como un CSV, reimportable | La misma gramática, en el sentido contrario |

Para otras aplicaciones, Farmacia publica una API OCS bajo `/ocs/v2.php/apps/farmacia/api/v1/medicamentos` (consulta de todo el arsenal, consulta de un medicamento, alta y actualización). El encabezado `OCS-APIRequest: true` **no es opcional**: Nextcloud responde `412` sin él. Los límites son 120 lecturas y 20 escrituras por minuto y por usuario. **Las rutas de escritura cambian el medicamento directamente, sin paso de revisión**: [`docs/threat-model.md`](docs/threat-model.md) registra por qué se acepta. El contrato es [`openapi.json`](openapi.json), generado por `make openapi` desde el controlador y nunca a mano; el [sitio de documentación](https://aps-conecta.github.io/documentation/desarrollo/apis/plataforma-y-rest-interno.html) indexa los contratos de las aplicaciones.

Una clínica obtiene Farmacia del asistente de la suite —aplicación opcional, desactivada por omisión—, no de la tienda de aplicaciones: la suite descarga la release firmada más nueva compatible con su Nextcloud, verifica su firma contra la llave de las aplicaciones y solo entonces la instala ([ADR-0017 de la suite](https://github.com/APS-Conecta/instalador/blob/main/docs/adr/0017-apps-install-from-their-own-releases.md)). Requiere Nextcloud 34 y PHP 8.2 a 8.5 (`appinfo/info.xml`); el entorno de desarrollo usa PostgreSQL 18.

**No hay nada que configurar.** La aplicación no lee configuración alguna: ni `IConfig` en `lib/`, ni claves de aplicación, ni sección de administración, ni nada en `config.php`. Los datos propios de la instancia son sus dos vocabularios («Categorías» y «Abastece»), que se editan en la propia aplicación. La única puerta fuera del navegador es el comando occ ([referencia de occ](https://docs.nextcloud.com/server/34/admin_manual/occ.html)):

    occ farmacia:import <archivo.csv> --actor=<name> [--apply]

## Documentación

El manual de uso, el diseño, el contrato OCS y las novedades viven en el sitio de documentación:

- [Guía de uso de Farmacia](https://aps-conecta.github.io/documentation/usuario/farmacia.html): consultar el arsenal, retirar y restaurar, importar y exportar la planilla, mantener los vocabularios y la importación por occ.
- [Farmacia: diseño y contratos](https://aps-conecta.github.io/documentation/desarrollo/farmacia.html): la vía única de escritura, la API OCS, los estados de la importación, los errores, los invariantes y la deuda técnica.
- [Novedades de Farmacia](https://aps-conecta.github.io/documentation/proyecto/novedades/farmacia.html): los cambios visibles, por versión.

Dentro de este repositorio quedan solo los documentos que viajan con el código:

- [`CONTEXT.md`](CONTEXT.md): el vocabulario del dominio (arsenal farmacológico, lente, planilla, lote, retirar y restaurar), en inglés.
- [`docs/adr/`](docs/adr/): las decisiones y su razonamiento (0001 a 0005).
- [`docs/threat-model.md`](docs/threat-model.md): qué busca un atacante, las puertas de entrada y sus guardas.
- [`openapi.json`](openapi.json): el contrato OCS, generado.
- [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md): los componentes de terceros del paquete y sus licencias.
- [`CHANGELOG.md`](CHANGELOG.md): qué cambió y cuándo.

## Estado

**0.11.1, etiquetada el 2026-09-18.** Todo lo descrito está construido y entregado: el arsenal y sus tres lentes, la ficha del medicamento, retirar y restaurar, ambos vocabularios (16 categorías terapéuticas sembradas al instalar; canales de abastece vacíos a propósito, porque cada CESFAM define los suyos), la importación de planilla en dos fases con su revisión, la exportación, la puerta `occ farmacia:import` y el contrato OCS. `main` lleva 25 commits sobre la etiqueta (el endurecimiento, el CI de la plantilla de la organización, el payload OCS tipado, el modelo de amenazas y la reescritura de la documentación) sin publicar: gestion actualiza el tarball que fija en un hito, no en cada etiqueta.

No está hecho, a propósito: no hay sección de administración ni delegación por grupo (cualquier persona con sesión puede editar; el modelo de amenazas registra la decisión y su pregunta abierta), y las etiquetas no tienen páginas de publicación en GitHub, por la misma razón que estadistica no las tiene.

## Inicio rápido de desarrollo

En una máquina con Docker, junto a una copia de [`aps-common`](https://github.com/APS-Conecta/aps-common) (`../aps-common`). Esto levanta un Nextcloud 34 desechable con PostgreSQL 18 en el puerto 8090; no es el stack de gestion.

1. **Levantar la instancia** (en este repositorio): `make instance-up`. Habilita la aplicación (lo que corre la migración y el paso de reparación de la semilla), reinicia los procesos web y termina con `Instance ready at http://localhost:8090 (admin / admin)`.
2. **Verificar el cableado**: `make smoke`. Comprueba que la ruta OCS responde 412 sin el encabezado `OCS-APIRequest` y 200 con él, que una escritura entra sin token CSRF, los rechazos 400 y 404, el ícono, los huecos de `.htaccess`, que la compilación produce el bundle y que `openapi.json` se regenera sin deriva. Termina con `All good.`
3. **Abrir `http://localhost:8090/apps/farmacia`** como `admin` / `admin`. La lista dice «Todavía no hay medicamentos», que es lo correcto: importar una planilla («Importar planilla») o agregar el primer medicamento a mano («Agregar medicamento»).

`make instance-clean` elimina la instancia y su base de datos.

Para desarrollar, este repositorio trae su propia instancia de prueba (`compose.dev.yaml`, `Dockerfile.test`): `make instance-up` la levanta y `make fix-mount-perms` repara un montaje que quedó de solo lectura. La suite instala la aplicación desde su release firmada; el clon es solo para desarrollar.

- `make test`: la suite unitaria de PHPUnit en un contenedor PHP básico, sin Nextcloud.
- `npm test` y `npm run lint-js`: Vitest y ESLint sobre `src/`.
- `make instance-up && make smoke`: el cableado contra la instancia desechable (los pasos 1 y 2 de arriba).
- La suite de integración corre dentro de esa instancia contra su PostgreSQL real, con el objetivo `integration` del Makefile (necesita antes `make deps` y `make instance-up`; ningún job de CI la ejecuta).
- `make aps-drift`: las copias vendorizadas de `aps-common` están al día (`make aps-sync` las refresca).
- `npm run build`: genera `js/farmacia-main.js` (el bundle no se confirma; el flujo de release lo compila y CI falla si la compilación no produce bundle).

El CI (`.github/workflows/ci.yml`) ejecuta la comprobación de deriva de aps-common, el lint, las suites unitarias, la comprobación del bundle, `make test` y `make smoke` sobre una instancia en frío; el flujo de release (`.github/workflows/release.yml`) compila y empaqueta en cada PR. Una trampa ya pagada: **reiniciar los procesos web después de `occ app:enable`**. Guardan las rutas y el estado de la aplicación en APCu durante una hora, y toda ruta responde 404 hasta que se reinician; `make instance-up` lo hace solo.

## Licencia

AGPL-3.0-or-later; véase [`LICENSE`](LICENSE). No es una elección libre: los bundles de `js/` compilan `@nextcloud/vue` (AGPL-3.0-or-later) y paquetes de Nextcloud bajo GPL-3.0-or-later, de modo que el copyleft se hereda de lo que enlaza; el [ADR-0006 de la suite](https://github.com/APS-Conecta/instalador/blob/main/docs/adr/0006-agpl-across-the-org.md) registra la decisión para toda la organización. Como la sección 13 de la AGPL cubre el uso por red, al personal que usa la aplicación por la intranet se le debe su código fuente si lo solicita.

Los avisos y licencias de la organización viven en [Aviso y licencias](https://aps-conecta.github.io/documentation/aviso.html); los componentes de terceros de este paquete y sus avisos, en [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md).
