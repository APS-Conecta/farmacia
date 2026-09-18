# Farmacia — glosario

Las palabras del dominio, fijadas para que el código diga lo mismo que el equipo.
_Evitar_ marca el término que no debe aparecer.

**Arsenal farmacológico**: el catálogo completo de medicamentos que el CESFAM usa.
_Evitar_: stock, inventario, bodega.

**Medicamento**: una fila del arsenal: producto comercial + principio activo + presentación
+ datos de cobertura y seguridad clínica.

**Lente**: una vista inicial sobre el mismo dataset: Arsenal (todo), Fármacos en
embarazo (FDA informada), Ajuste farmacológico ERC (ajuste renal informado). Una lente
cambia el filtro inicial, nunca los datos. _Evitar_: pestaña, módulo, sección clínica.

**Categoría terapéutica**: vocabulario editable por instancia, sembrado con 16 valores
al instalar. _Evitar_: familia, clase ATC (no es la clasificación ATC).

**Canal de abastece**: vocabulario por instancia de dónde proviene cada medicamento
(URGENCIAS, CECOSF…). Empieza vacío: cada CESFAM define los suyos. _Evitar_: proveedor,
bodega.

**GES**: número de problema AUGE/GES que cubre el medicamento (0..n).

**Restricción**: condición de uso escrita en texto libre (0..n).

**Trazador**: bandera que marca un medicamento de uso monitorio/epidemiológico.

**Categoría FDA**: A, B, C, D o X en embarazo. Vacío = sin dato (no lo mismo que segura).

**Retirar / restaurar**: baja y recuperación lógicas. Nada se borra. _Evitar_: eliminar.

**Planilla**: el CSV que entra y sale de la app. La base de datos es la fuente de
verdad; el CSV es una proyección.

**Lote**: una corrida de importación revisable y revertible, con autor y hora.