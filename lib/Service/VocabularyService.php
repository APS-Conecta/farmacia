<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Service;

use APS\Common\Text;
use OCA\Farmacia\Db\Category;
use OCA\Farmacia\Db\CategoryMapper;
use OCA\Farmacia\Db\Channel;
use OCA\Farmacia\Db\ChannelMapper;
use OCA\Farmacia\Db\MedicationAbasteceMapper;
use OCA\Farmacia\Db\MedicationMapper;
use OCA\Farmacia\Exception\InvalidVocabularyException;
use OCP\DB\Exception as DbException;

/**
 * The two vocabularies the app draws its filters and pickers from: the
 * therapeutic categories and the ABASTECE supply channels. Editable by the
 * whole team, like every other write in the app — the safety net is the
 * in-use guard, not permissions.
 *
 * Every write method answers with the whole served list, on territorio's
 * rationale: a single row would make the client splice it in — a second
 * description of the same list that can disagree with it.
 *
 * The two vocabularies are one shape (id, slug, label) under one set of
 * rules, so each rule is stated once: `cleanLabel()` judges what a word may
 * contain, `slugFor()` derives the address, `refuseIfInUse()` guards
 * removal. The two public method families differ only in which table a
 * rule runs over.
 */
class VocabularyService {
	/** farmacia_category.label and farmacia_abastece.label are VARCHAR(128). */
	private const LABEL_LENGTH = 128;

	/** Deleted — private const SLUG_LENGTH: sole referencing site was the
	 *  slugFor body that now lives in Text. */

	public function __construct(
		private CategoryMapper $categories,
		private ChannelMapper $channels,
		private MedicationMapper $medications,
		private MedicationAbasteceMapper $assignments,
	) {
	}

	/** @return list<array{id: int, slug: string, label: string}> */
	public function categories(): array {
		return array_map(static fn ($c) => [
			'id' => (int)$c->getId(),
			'slug' => $c->getSlug(),
			'label' => $c->getLabel(),
		], $this->categories->findAll());
	}

	/** @return list<array{id: int, slug: string, label: string}> */
	public function abastece(): array {
		return array_map(static fn ($c) => [
			'id' => (int)$c->getId(),
			'slug' => $c->getSlug(),
			'label' => $c->getLabel(),
		], $this->channels->findAll());
	}

	public function addCategory(string $label): array {
		$this->addWord($this->categories, Category::class, $label, 'una categoría');

		return $this->categories();
	}

	public function addChannel(string $label): array {
		$this->addWord($this->channels, Channel::class, $label, 'un canal');

		return $this->abastece();
	}

	/**
	 * Renames. The slug is what survives — territorio's findBySlug
	 * rationale, still true here: the label moves and the id differs per
	 * install, so a client that wants to address one word has exactly one
	 * stable name for it. Nothing here regenerates the slug, so a rename
	 * can never collide: uniqueness is the insert-time rule below.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException when no category carries that slug
	 * @throws InvalidVocabularyException when the label is not one this app stores
	 */
	public function renameCategory(string $slug, string $label): array {
		$word = $this->categories->findBySlug($slug);
		$word->setLabel($this->cleanLabel($label));
		$this->categories->update($word);

		return $this->categories();
	}

	/** The channel mirror of `renameCategory()`, same shape and same rules. */
	public function renameChannel(string $slug, string $label): array {
		$word = $this->channels->findBySlug($slug);
		$word->setLabel($this->cleanLabel($label));
		$this->channels->update($word);

		return $this->abastece();
	}

	/**
	 * Removes a category nothing uses: refused while any medication is
	 * classified under it, retired medications included (territorio#ADR-0016's orphan
	 * argument — see `refuseIfInUse()`). Nothing is reclassified or
	 * destroyed on the person's behalf, and there is no `?force` for a
	 * caller to find anywhere.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException when no category carries that slug
	 * @throws InvalidVocabularyException while any medication, live or retired, is filed under it
	 */
	public function removeCategory(string $slug): array {
		$word = $this->categories->findBySlug($slug);
		$count = $this->medications->countByCategory((int)$word->getId());
		$this->refuseIfInUse(
			$count,
			$word->getLabel(),
			$count === 1 ? 'medicamento clasificado ahí' : 'medicamentos clasificados ahí',
			'Reclasifíquelos',
		);
		$this->categories->delete($word);

		return $this->categories();
	}

	/** The channel mirror of `removeCategory()` — the count runs over the channel
	 * assignments, every one of which is a reference the schema will not catch. */
	public function removeChannel(string $slug): array {
		$word = $this->channels->findBySlug($slug);
		$count = $this->assignments->countByChannel((int)$word->getId());
		$this->refuseIfInUse(
			$count,
			$word->getLabel(),
			$count === 1 ? 'medicamento que proviene de este canal' : 'medicamentos que provienen de este canal',
			'Quítelo de cada medicamento',
		);
		$this->channels->delete($word);

		return $this->abastece();
	}

	/**
	 * The one add, for either vocabulary: judge the label, derive the slug,
	 * insert. Uniqueness is the index's, not a check-then-insert — a second
	 * statement of the same rule that could disagree with the first — and
	 * the index's complaint is turned into a sentence naming the LABEL,
	 * because the slug is derived and the person never typed it: «Comité de
	 * Vivienda» colliding with «comité de vivienda» is two people typing
	 * what should be one word, and the message has to say it that way.
	 *
	 * @param CategoryMapper|ChannelMapper $mapper
	 * @param class-string<Category|Channel> $class
	 */
	private function addWord(CategoryMapper|ChannelMapper $mapper, string $class, string $label, string $kind): void {
		$clean = $this->cleanLabel($label);
		$slug = self::slugFor($clean);
		if ($slug === '') {
			throw new InvalidVocabularyException('El nombre necesita alguna letra o algún número.');
		}

		$word = new $class();
		$word->setSlug($slug);
		$word->setLabel($clean);

		try {
			$mapper->insert($word);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			throw new InvalidVocabularyException(sprintf('Ya existe %s con ese nombre.', $kind));
		}
	}

	/**
	 * The removal guard, territorio#ADR-0016's shape: called before any deletion, never
	 * after, and the count is the caller's — what would be orphaned, not
	 * what somebody is looking at.
	 *
	 * @param string $what how the counted things are named, already singular or plural
	 * @param string $tail the next step, already conjugated («Reclasifíquelos…»)
	 */
	private function refuseIfInUse(int $count, string $label, string $what, string $tail): void {
		if ($count === 0) {
			return;
		}

		throw new InvalidVocabularyException(sprintf(
			'No se puede quitar «%s»: hay %d %s, incluidos los retirados. '
			. 'Para verlos, pulse «Ver retirados» en la lista. %s y vuelva a intentarlo.',
			$label,
			$count,
			$what,
			$tail,
		));
	}

	/**
	 * What a person typed, or a refusal that says what to fix.
	 *
	 * The `|` refusal is the planilla round-trip's other half: the export
	 * joins a medication's GES numbers and channels with the separator, so
	 * a vocabulary label carrying it would come back from a spreadsheet as
	 * two values — the word is refused where it is typed rather than
	 * corrupted where it is exported. Both vocabularies: one rule, stated
	 * once, over both tables.
	 */
	private function cleanLabel(string $label): string {
		$clean = trim($label);
		if ($clean === '') {
			throw new InvalidVocabularyException('El nombre no puede quedar vacío.');
		}
		if (mb_strlen($clean) > self::LABEL_LENGTH) {
			throw new InvalidVocabularyException(sprintf('El nombre no puede pasar de %d caracteres.', self::LABEL_LENGTH));
		}
		if (str_contains($clean, '|')) {
			throw new InvalidVocabularyException('El nombre no puede contener «|»: es el separador con el que la planilla une varios valores en una celda.');
		}

		return $clean;
	}

	/**
	 * The identifier a label makes — the shared rule, stated once in
	 * aps-common's Text (L0-03/L2-04). Kept as a static forward so every call
	 * site (the planilla headings, the admin write doors, the tests) keeps
	 * working unchanged.
	 */
	public static function slugFor(string $label): string {
		return Text::slugFor($label);
	}
}
