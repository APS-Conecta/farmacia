# Manual de Farmacia

Para el personal del CESFAM que consulta el vademécum y cuida el arsenal farmacológico, y para
quien administra la aplicación. Los nombres entre comillas latinas («…») son los que aparecen en
pantalla.

## 1. Qué muestra, y qué no

Farmacia es el vademécum de medicamentos del CESFAM: cada medicamento que el centro receta y
despacha, con su cobertura (GES, canales de abastece) y su seguridad clínica (categoría FDA en
embarazo, ajuste renal, riesgo colinérgico en adulto mayor).

- **No guarda datos de pacientes** y **no gestiona stock**: nada vence, nada se cuenta. Es una
  referencia de lo que el CESFAM usa, no un inventario de lo que hay en bodega.
- **Toda persona con cuenta en la instalación puede consultar y editar** el arsenal. La red de
  seguridad no son los permisos: es el retiro reversible («Retirar del arsenal») y la revisión por
  filas de toda importación (§5).
- Nada se borra: lo que sale del arsenal queda en «Ver retirados» y se puede restaurar (§4).

## 2. El arsenal y las lentes

Al abrir Farmacia, la columna izquierda ofrece tres lentes sobre el mismo arsenal:

- «Arsenal Farmacológico»: todos los medicamentos activos.
- «Fármacos en embarazo»: los que tienen categoría FDA informada (A, B, C, D o X).
- «Ajuste farmacológico ERC»: los que tienen ajuste renal informado.

Una lente es un punto de partida, no un dato distinto: cambia el filtro inicial y limpia la
búsqueda; el arsenal sigue siendo uno solo. Si no recuerda qué lente dejó activa, volver a
«Arsenal Farmacológico» muestra todo.

Sobre la lista, «Buscar» filtra por producto, fármaco (DCI) o categoría terapéutica, sin importar
acentos ni mayúsculas. El botón «×» (o «Limpiar la búsqueda») la deja en blanco. El encabezado
muestra cuántos medicamentos ve («N medicamentos»). Si nada coincide, la lista dice «Nada coincide
con «…»»: pruebe con menos palabras o limpie la búsqueda. Un arsenal recién instalado dice
«Todavía no hay medicamentos»: importe la planilla (§5) o agregue el primero a mano (§3).

## 3. La ficha del medicamento

Presione un medicamento de la lista y se abre su ficha a la derecha, en cinco secciones:

- **Identidad**: «Código», «Producto», «Fármaco (DCI)», «Mg», «Presentación» y «Categoría
  terapéutica». El código es la identidad del registro: la importación lo usa para reconocerlo.
- **Cobertura**: «Abastece» (los canales de dónde proviene, si aplica), «Programa», la casilla
  «Trazador (uso monitorio / epidemiológico)», los números «GES» y las «Restricciones». GES y
  restricciones se agregan de a uno con «Agregar número GES» / «Agregar restricción» y se quitan
  con «Quitar».
- **Clínica**: «Indicación», «Dosificación», «Precauciones» y «Observaciones», en texto libre.
- **Seguridad**: la casilla «Riesgo colinérgico en adulto mayor», la «Categoría FDA en embarazo»
  (A, B, C, D o X — vacío = sin dato, no lo mismo que segura) y el «Ajuste renal (ERC)».
- **Estado**: cuándo se guardó por última vez («Actualizado: …» o «Sin guardar todavía.»).

«Guardar» escribe los cambios; «Guardando…» indica que van en camino. Un rechazo (por ejemplo, un
código vacío) se muestra en rojo en la misma ficha, con la frase que dice por qué: corríjalo y
vuelva a guardar. El botón «Agregar medicamento» del pie del menú abre una ficha en blanco
(«Nuevo medicamento») con las mismas secciones.

## 4. Retirar y restaurar

Un medicamento que sale del arsenal se retira; no se borra. En la ficha, «Retirar del arsenal»
pide confirmación en dos pasos: «Se retira del arsenal y de la lista. No se borra: conserva sus
datos y se puede restaurar.», y solo «Retirar» ejecuta. El registro desaparece de las listas
activas pero conserva todos sus datos.

Para recuperarlo, presione «Ver retirados» sobre la lista: la pantalla muestra los medicamentos
retirados (con su marca «Retirado») y, al abrir uno, su ficha dice «Este registro está retirado:
no aparece en el arsenal.» con el botón «Restaurar». Restaurar lo devuelve al arsenal tal como
estaba. «Ver activos» vuelve a la lista normal. La búsqueda sí funciona en «Ver retirados»; las
lentes no: es una pantalla de recuperación, no un filtro clínico.

## 5. La planilla: importar

El pie del menú («Importar planilla») abre la importación. La planilla es un CSV con encabezado;
las columnas que se leen son: Código, Producto, Fármaco (DCI), Mg, Presentación, Categoría,
Trazador, GES, Restricciones, Abastece, Programa, Indicación, Dosificación, Precauciones,
Observaciones, Riesgo colinérgico AM, FDA embarazo y Ajuste renal; las demás se ignoran. Varias
celdas con varios valores (GES, restricciones, abastece) van unidas con «|».

Elija el «Archivo», presione «Revisar» y la aplicación estudia cada fila por separado, sin
escribir nada todavía. La revisión muestra el «Lote #N» con su resumen («N por crear · N por
actualizar · …») y cada fila con su veredicto: «crear», «actualizar», «sin cambios», «omitido»
(retirado: la importación no lo toca), «duplicado» o «con problemas» (con la frase que dice qué
celda no se pudo leer).

- **Decisiones de vocabulario.** Si la planilla nombra una categoría o un canal que no existe, la
  fila queda «esperando una decisión» y la casilla «Solo filas que esperan una decisión» las
  aísla. «Resolver» ofrece elegir la «Categoría terapéutica» o los canales de «Abastece» que
  corresponden; «Aplicar» se habilita cuando ninguna fila debe nada. Si falta la palabra misma,
  ciérrelo, declárela en el pie del menú («Categorías» o «Abastece», §7) y vuelva a abrir el lote
  desde «Lotes recientes».
- **Aplicar / descartar.** «Aplicar» escribe el lote completo en una sola operación: o entra todo
  lo revisado, o no entra nada. Las filas «con problemas» no entran y no estorban a las demás.
  «Descartar» cierra el lote sin escribir nada.
- **Lotes recientes / deshacer.** Un lote en revisión sobrevive al cierre de la página: «Lotes
  recientes» lo reabre con sus decisiones tomadas. Un lote aplicado ofrece «Deshacer»: lo que la
  importación creó se retira y lo que actualizó vuelve a como estaba, salvo lo que otra persona
  haya editado mientras tanto, que se conserva y se informa. Deshacer un «Deshacer» no se puede:
  la salida honesta es importar la planilla de nuevo.

## 6. La planilla: exportar

«Exportar planilla» dice lo que el archivo lleva antes de descargarlo: el arsenal farmacológico
completo como CSV, con todas las columnas, listo para abrir en Excel o LibreOffice, y
reimportable: la revisión lo recibe fila por fila como cualquier planilla (§5). Los medicamentos
retirados no van: la planilla es el arsenal que la aplicación muestra. «Descargar» guarda el
archivo (farmacia-AAAA-MM-DD.csv); «Cancelar» cierra sin descargar.

## 7. Las palabras: categorías y canales

El pie del menú abre los dos vocabularios de la instancia:

- «Categorías» («Categorías terapéuticas»): llega sembrada con 16 categorías al instalar;
  «Nueva categoría» agrega, el nombre mismo se edita con un clic («Guardar» el nuevo nombre) y
  «Quitar» elimina — salvo que algún medicamento, activo o retirado, esté clasificado bajo ella:
  mientras esté en uso, la palabra no se puede quitar.
- «Abastece» («Canales de abastece»): parte vacía — cada CESFAM define sus canales (URGENCIAS,
  CECOSF…). Se administra igual («Nuevo canal», renombrar, «Quitar» con la misma regla de uso).

Los nombres no pueden quedar vacíos, ni pasar de 128 caracteres, ni contener «|» (el separador
de la planilla); dos palabras no pueden llamarse igual. Un rechazo se muestra dentro del mismo
diálogo. Renombrar es seguro: la planilla exporta la palabra nueva, y una planilla vieja que
traiga la antigua vuelve a pedir su decisión en la revisión, en vez de perderse.

## 8. Para quien administra: importar sin navegador

La línea de comandos importa la misma planilla por el mismo camino, sin abrir el navegador:

    occ farmacia:import /ruta/a/la/planilla.csv --actor="quien la corre" --apply

Sin «--apply», el archivo queda en revisión («Lote #N») y las decisiones se toman en la
aplicación (§5); con «--apply», el lote se aplica de inmediato solo si ninguna fila debe una
decisión — si debe, se rechaza el «--apply» y el lote queda en revisión, con el aviso de que las
decisiones se toman en la pantalla. «--actor» registra quién corrió la importación (por omisión,
«occ»). Las reglas del lote son las mismas: una fila con problemas no cuesta las demás, nada se
aplica sin revisión y «Deshacer» queda disponible en «Lotes recientes».

## 9. Palabras que conviene conocer

El glosario completo del dominio — **Arsenal farmacológico**, **Lente**, **Planilla**, **Lote**,
**Retirar / restaurar** y los demás — vive en inglés, con sus encabezados en español, en
[`CONTEXT.md`](../CONTEXT.md). Es la referencia cuando dos documentos dicen la misma cosa de
distinta forma.

## 10. Si algo no se ve

- **«No se pudieron cargar los datos de la aplicación…»**: la página no pudo leer sus datos
  iniciales. Recargue la página; si persiste, avise a quien administra la instalación.
- **«Todavía no hay medicamentos»**: el arsenal está vacío. Importe la planilla (§5) o agregue el
  primer medicamento (§3).
- **«No hay medicamentos retirados»**: nadie ha retirado nada todavía; es una pantalla de
  recuperación y estará vacía hasta que alguien retire algo.
- **«Nada coincide con «…»»**: la búsqueda no encontró el texto en producto, fármaco ni
  categoría. Pruebe con menos palabras o limpie la búsqueda.
- **Un mensaje de error rojo** (en la ficha, en la importación o en un vocabulario): dice qué
  falló y qué corregir. Corrija lo que nombra y repita la acción; si un error se repite, avise a
  quien administra la instalación.
