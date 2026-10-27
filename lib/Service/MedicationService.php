<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Service;

use APS\Common\Text;
use OCA\Farmacia\Db\CategoryMapper;
use OCA\Farmacia\Db\ChannelMapper;
use OCA\Farmacia\Db\Medication;
use OCA\Farmacia\Db\MedicationAbasteceMapper;
use OCA\Farmacia\Db\MedicationGesMapper;
use OCA\Farmacia\Db\MedicationMapper;
use OCA\Farmacia\Db\MedicationRestrictionMapper;
use OCA\Farmacia\Exception\InvalidMedicationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;

class MedicationService {
	private const FDA_CATEGORIES = ['A', 'B', 'C', 'D', 'X'];
	private const LIST_CAP = 200;
	private const CODIGO_MAX = 128;
	private const PRODUCTO_MAX = 255;

	private const OPTIONAL_TEXT = [
		// [setter, the Spanish sentence label, the column's VARCHAR width —
		// or null for the TEXT columns, which carry no app-side ceiling:
		// a planilla cell caps at ImportService's stage table and a typed
		// note has no length a clinic reaches; documented here because L2-05
		// asks the TEXT bounds to be DECIDED, and the decision is "none,
		// because the column is TEXT"].
		'farmaco' => ['setFarmaco', 'El fármaco (DCI)', 255],
		'mg' => ['setMg', 'Las mg', 64],
		'presentacion' => ['setPresentacion', 'La presentación', 255],
		'programa' => ['setPrograma', 'El programa', 128],
		'indicacion' => ['setIndicacion', 'La indicación', null],
		'dosificacion' => ['setDosificacion', 'La dosificación', null],
		'precauciones' => ['setPrecauciones', 'Las precauciones', null],
		'observaciones' => ['setObservaciones', 'Las observaciones', null],
		'ajusteRenal' => ['setAjusteRenal', 'El ajuste renal', null],
	];

	/**
	 * The cell caps the planilla stage echoes in its review sentence — one
	 * table, read from the write path that enforces it (L2-05). Key →
	 * [max, the column's Spanish name], with codigo/producto's own caps
	 * folded in so the stage never states a number this service does not
	 * refuse by.
	 *
	 * @return array<string, array{int, string}>
	 */
	public const CELL_CAPS = [
		'codigo' => [self::CODIGO_MAX, 'Código'],
		'producto' => [self::PRODUCTO_MAX, 'Producto'],
		'farmaco' => [255, 'Fármaco (DCI)'],
		'mg' => [64, 'Mg'],
		'presentacion' => [255, 'Presentación'],
		'programa' => [128, 'Programa'],
	];

	/**
	 * The largest number the Types::INTEGER (signed 32-bit)
	 * `farmacia_medication_ges.number` column holds; the staging judge reads it too.
	 */
	public const GES_NUMBER_MAX = 2147483647;

	public function __construct(
		private MedicationMapper $medications,
		private MedicationGesMapper $ges,
		private MedicationRestrictionMapper $restrictions,
		private MedicationAbasteceMapper $abastece,
		private CategoryMapper $categories,
		private ChannelMapper $channels,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function listAll(): array {
		return $this->withChildren($this->medications->findAll());
	}

	/** @return list<array<string, mixed>> */
	public function listRetired(): array {
		return $this->withChildren($this->medications->findRetired());
	}

	/**
	 * One medication by id, children attached — the OCS door for a consumer
	 * following an id it got from the list (the browser never needs it: the
	 * initial state already holds every row). A retired record answers with
	 * its retired flag rather than a refusal: it is recoverable data, and
	 * «no such record» would say a thing that exists does not — the
	 * sidebar's own honesty, restated for a consumer.
	 *
	 * @return array<string, mixed>
	 * @throws DoesNotExistException when there is no such medication
	 */
	public function read(int $id): array {
		return $this->payloadOf($this->medications->find($id));
	}

	/** @param array<string, mixed> $input */
	public function create(array $input): array {
		$medication = $this->apply(new Medication(), $input, whole: true);
		$ges = $this->gesList($input['ges'] ?? []);
		$restrictions = $this->restrictionList($input['restricciones'] ?? []);
		$channels = $this->channelList($input['abastece'] ?? []);

		return $this->medications->transactionally(function () use ($medication, $ges, $restrictions, $channels): array {
			$created = $this->insert($medication);
			$this->ges->replaceFor((int)$created->getId(), $ges);
			$this->restrictions->replaceFor((int)$created->getId(), $restrictions);
			$this->abastece->replaceFor((int)$created->getId(), $channels);

			return $this->payloadOf($created);
		});
	}

	/** @param array<string, mixed> $input */
	public function update(int $id, array $input): array {
		$medication = $this->medications->find($id);

		if ($medication->getRetired()) {
			throw new InvalidMedicationException('El registro está retirado. Restáurelo antes de editarlo.');
		}

		$before = $medication->comparable();
		$childrenBefore = $this->childrenOf($medication);

		$this->apply($medication, $input, whole: false);

		$ges = array_key_exists('ges', $input) ? $this->gesList($input['ges']) : null;
		$restrictions = array_key_exists('restricciones', $input) ? $this->restrictionList($input['restricciones']) : null;
		$channels = array_key_exists('abastece', $input) ? $this->channelList($input['abastece']) : null;

		$childrenChanged = ($ges !== null && $ges !== $childrenBefore['ges'])
			|| ($restrictions !== null && $restrictions !== $childrenBefore['restricciones'])
			|| ($channels !== null && $channels !== $childrenBefore['abastece']);

		if ($medication->comparable() === $before && !$childrenChanged) {
			return $this->payloadOf($medication);
		}

		return $this->medications->transactionally(function () use ($medication, $ges, $restrictions, $channels): array {
			try {
				$written = $this->medications->update($medication);
			} catch (DbException $e) {
				// The mirror of insert()'s catch (L2-06): the one write the OCS
				// contract exposes answered a duplicate código with the database's
				// own error through a 500 — the sentence-style posture breaking on
				// exactly the write a consumer can reach.
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				throw new InvalidMedicationException(sprintf('El código «%s» ya existe.', $medication->getCodigo()), 0, $e);
			}
			if ($ges !== null) { $this->ges->replaceFor((int)$written->getId(), $ges); }
			if ($restrictions !== null) { $this->restrictions->replaceFor((int)$written->getId(), $restrictions); }
			if ($channels !== null) { $this->abastece->replaceFor((int)$written->getId(), $channels); }

			return $this->payloadOf($written);
		});
	}

	public function retire(int $id): array {
		$medication = $this->medications->find($id);
		if ($medication->getRetired()) {
			throw new InvalidMedicationException('El registro ya estaba retirado.');
		}

		return $this->payloadOf($this->medications->retire($medication));
	}

	public function restore(int $id): array {
		$medication = $this->medications->find($id);
		if (!$medication->getRetired()) {
			throw new InvalidMedicationException('El registro no está retirado.');
		}

		return $this->payloadOf($this->medications->restore($medication));
	}

	private function apply(Medication $medication, array $input, bool $whole): Medication {
		if ($whole || array_key_exists('codigo', $input)) {
			$codigo = trim((string)($input['codigo'] ?? ''));
			if ($codigo === '') {
				throw new InvalidMedicationException('El medicamento necesita un código.');
			}
			if (mb_strlen($codigo) > self::CODIGO_MAX) {
				throw new InvalidMedicationException(sprintf('El código no puede pasar de %d caracteres.', self::CODIGO_MAX));
			}
			$medication->setCodigo($codigo);
		}

		if ($whole || array_key_exists('producto', $input)) {
			$producto = trim((string)($input['producto'] ?? ''));
			if ($producto === '') {
				throw new InvalidMedicationException('El medicamento necesita un producto.');
			}
			if (mb_strlen($producto) > self::PRODUCTO_MAX) {
				throw new InvalidMedicationException(sprintf('El producto no puede pasar de %d caracteres.', self::PRODUCTO_MAX));
			}
			$medication->setProducto($producto);
		}

		if ($whole || array_key_exists('categoryId', $input)) {
			// `is_int || ctype_digit`, never a bare cast: `(int)['x']` is 1, so a
			// `categoryId[]=7` request silently classified a real medication under
			// seeded category 1 after find(1) succeeded (L2-03) — a data-corruption
			// path dressed as a validation pass.
			$given = $input['categoryId'] ?? null;
			$categoryId = is_int($given) || (is_string($given) && ctype_digit($given)) ? (int)$given : null;
			if ($categoryId === null) {
				throw new InvalidMedicationException('La categoría tiene que ser un número.');
			}
			try {
				$this->categories->find($categoryId);
			} catch (DoesNotExistException $e) {
				throw new InvalidMedicationException(sprintf('La categoría %d no existe.', $categoryId), 0, $e);
			}
			$medication->setCategoryId($categoryId);
		}

		foreach (self::OPTIONAL_TEXT as $key => [$setter, $label, $cap]) {
			if (!array_key_exists($key, $input)) { continue; }
			$value = trim((string)$input[$key]);
			if ($cap !== null && mb_strlen($value) > $cap) {
				throw new InvalidMedicationException(sprintf('%s no puede pasar de %d caracteres.', $label, $cap));
			}
			$medication->$setter($value === '' ? null : $value);
		}

		if (array_key_exists('trazador', $input)) { $medication->setTrazador($this->flag($input['trazador'], 'El trazador')); }
		if (array_key_exists('riesgoColinergicoAm', $input)) { $medication->setRiesgoColinergicoAm($this->flag($input['riesgoColinergicoAm'], 'El riesgo colinérgico AM')); }
		if (array_key_exists('fdaEmbarazo', $input)) { $medication->setFdaEmbarazo($this->fda($input['fdaEmbarazo'])); }

		return $medication;
	}

	private function insert(Medication $medication): Medication {
		try {
			return $this->medications->insert($medication);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) { throw $e; }
			throw new InvalidMedicationException(sprintf('El código «%s» ya existe.', $medication->getCodigo()), 0, $e);
		}
	}

	private function fda(mixed $value): ?string {
		$category = strtoupper(trim((string)$value));
		if ($category === '') { return null; }
		if (!in_array($category, self::FDA_CATEGORIES, true)) {
			throw new InvalidMedicationException('La categoría FDA debe ser A, B, C, D o X (o vacía, para sin dato).');
		}
		return $category;
	}

	private function flag(mixed $value, string $label): bool {
		if ($value === null || is_bool($value)) { return (bool)$value; }
		// Fold only well-formed UTF-8: an ill-formed string folds to '' and would read as no.
		$folded = is_int($value) || (is_string($value) && mb_check_encoding($value, 'UTF-8')) ? Text::fold((string)$value) : null;
		if (in_array($folded, ['1', 'true', 'si'], true)) { return true; }
		if (in_array($folded, ['0', 'false', 'no', ''], true)) { return false; }
		throw new InvalidMedicationException(sprintf('%s debe ser sí o no.', $label));
	}

	private function gesList(array $values): array {
		$numbers = [];
		foreach ($values as $value) {
			$number = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int)$value : null;
			if ($number === null || $number < 1 || $number > self::GES_NUMBER_MAX) {
				throw new InvalidMedicationException(sprintf('El número GES «%s» no es un número de problema GES.', is_scalar($value) ? (string)$value : gettype($value)));
			}
			$numbers[] = $number;
		}
		$numbers = array_values(array_unique($numbers));
		self::cap($numbers, 'números GES');
		return $numbers;
	}

	private function restrictionList(array $values): array {
		$restrictions = [];
		foreach ($values as $value) {
			if (!is_scalar($value)) { throw new InvalidMedicationException('Cada restricción es un texto.'); }
			$restriction = trim((string)$value);
			if ($restriction === '') { continue; }
			if (str_contains($restriction, '|')) {
				// cleanLabel's own sentence, restated for the field it names (L2-13):
				// the export joins a medication's restrictions with «|», so a
				// stored one carrying it cannot round-trip — refused where it is
				// typed rather than discovered at the review of a LATER import.
				throw new InvalidMedicationException('Cada restricción no puede contener «|»: es el separador con el que la planilla une varios valores en una celda.');
			}
			$restrictions[] = $restriction;
		}
		$restrictions = array_values(array_unique($restrictions));
		self::cap($restrictions, 'restricciones');
		return $restrictions;
	}

	private function channelList(array $values): array {
		$channels = [];
		foreach ($values as $value) {
			$channelId = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int)$value : null;
			if ($channelId === null || $channelId < 1) { throw new InvalidMedicationException('Cada canal de abastece es un número.'); }
			try {
				$this->channels->find($channelId);
			} catch (DoesNotExistException $e) {
				throw new InvalidMedicationException(sprintf('El canal de abastece %d no existe.', $channelId), 0, $e);
			}
			$channels[] = $channelId;
		}
		$channels = array_values(array_unique($channels));
		self::cap($channels, 'canales de abastece');
		return $channels;
	}

	private static function cap(array $list, string $what): void {
		if (count($list) > self::LIST_CAP) {
			throw new InvalidMedicationException(sprintf('Una lista no puede pasar de %d %s.', self::LIST_CAP, $what));
		}
	}

	private function childrenOf(Medication $medication): array {
		$id = (int)$medication->getId();
		return [
			'ges' => $this->ges->findAllFor($id),
			'restricciones' => $this->restrictions->findAllFor($id),
			'abastece' => $this->abastece->findAllFor($id),
		];
	}

	private function withChildren(array $medications): array {
		$ids = array_map(static fn (Medication $m): int => (int)$m->getId(), $medications);
		$ges = $this->ges->forIds($ids);
		$restrictions = $this->restrictions->forIds($ids);
		$abastece = $this->abastece->forIds($ids);
		return array_map(static fn (Medication $m): array => $m->toClient() + [
			'ges' => $ges[(int)$m->getId()] ?? [],
			'restricciones' => $restrictions[(int)$m->getId()] ?? [],
			'abastece' => $abastece[(int)$m->getId()] ?? [],
		], $medications);
	}

	private function payloadOf(Medication $medication): array {
		return $medication->toClient() + $this->childrenOf($medication);
	}
}