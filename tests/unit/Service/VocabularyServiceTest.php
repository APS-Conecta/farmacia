<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Tests\Unit\Service;

use OCA\Farmacia\Db\Category;
use OCA\Farmacia\Db\CategoryMapper;
use OCA\Farmacia\Db\Channel;
use OCA\Farmacia\Db\ChannelMapper;
use OCA\Farmacia\Db\MedicationAbasteceMapper;
use OCA\Farmacia\Db\MedicationMapper;
use OCA\Farmacia\Exception\InvalidVocabularyException;
use OCA\Farmacia\Service\VocabularyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The vocabulary's three rules — what a label may contain, the address it
 * makes, and what removal has to refuse — plus the shape every write
 * answers with (the whole served list, never the single row).
 *
 * The mappers are mocked because they are what has to be OBSERVED here, not
 * exercised: the whole of the removal decision is that nothing is deleted
 * while a refusal is due, and a mock is the only thing that can assert
 * `delete()` was never reached. The integration suite (VocabularyGuardTest)
 * proves the other half — that the count really sees a retired medication,
 * which no mock can know (territorio's TaxonomyRemovalTest split).
 *
 * The counts are stubbed with the ids they are HANDED (`->with()`), not
 * merely with numbers: an implementation that counted the wrong row would
 * pass every assertion about the message while orphaning the real ones.
 */
final class VocabularyServiceTest extends TestCase {
	private CategoryMapper&MockObject $categories;
	private ChannelMapper&MockObject $channels;
	private MedicationMapper&MockObject $medications;
	private MedicationAbasteceMapper&MockObject $assignments;
	private IDBConnection&MockObject $db;
	private VocabularyService $service;

	protected function setUp(): void {
		$this->categories = $this->createMock(CategoryMapper::class);
		$this->channels = $this->createMock(ChannelMapper::class);
		$this->medications = $this->createMock(MedicationMapper::class);
		$this->assignments = $this->createMock(MedicationAbasteceMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new VocabularyService(
			$this->categories, $this->channels, $this->medications, $this->assignments, $this->db,
		);
	}

	private static function category(int $id, string $slug, string $label): Category {
		$word = new Category();
		$word->setId($id);
		$word->setSlug($slug);
		$word->setLabel($label);

		return $word;
	}

	private static function channel(int $id, string $slug, string $label): Channel {
		$word = new Channel();
		$word->setId($id);
		$word->setSlug($slug);
		$word->setLabel($label);

		return $word;
	}

	/**
	 * The unique index's complaint, as the live server throws it. The OCP
	 * base class ships a getReason() that answers null — at runtime the
	 * mapper throws the DBAL-backed subclass that answers — so the stand-in
	 * subclasses rather than constructs the base, which every caller in
	 * this app would read as "not a unique violation" and rethrow.
	 */
	private static function uniqueViolation(): DbException {
		return new class ('duplicate key value violates unique constraint') extends DbException {
			public function getReason(): ?int {
				return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};
	}

	public function testSlugForFoldsLabelsTheWayTheSeedWasPreFolded(): void {
		// Real pairs from EnsureSeedData::CATEGORIES: the seed's comment
		// promises its slugs are exactly what slugFor derives, and this is
		// the pin that keeps the promise observable.
		self::assertSame('analgesicos_antipireticos_antiinflamatorios', VocabularyService::slugFor('Analgésicos / antipiréticos / antiinflamatorios'));
		self::assertSame('cardiovasculares_hta_dm_dlp', VocabularyService::slugFor('Cardiovasculares (HTA · DM · DLP)'));
		self::assertSame('cremas_vaselinas_bengue_pasta_lassar', VocabularyService::slugFor('Cremas (vaselinas, bengue, pasta Lassar)'));
	}

	public function testABlankLabelIsRefusedAndNothingIsInserted(): void {
		$this->categories->expects(self::never())->method('insert');

		try {
			$this->service->addCategory('   ');
			self::fail('a blank label must be refused');
		} catch (InvalidVocabularyException $e) {
			self::assertSame('El nombre no puede quedar vacío.', $e->getMessage());
		}
	}

	public function testALabelThatFoldsToNoSlugIsRefused(): void {
		$this->channels->expects(self::never())->method('insert');

		try {
			$this->service->addChannel('///');
			self::fail('a label with no letters or numbers must be refused');
		} catch (InvalidVocabularyException $e) {
			self::assertSame('El nombre necesita alguna letra o algún número.', $e->getMessage());
		}
	}

	public function testALabelCarryingThePlanillaSeparatorIsRefusedForBothVocabularies(): void {
		$this->categories->expects(self::never())->method('insert');
		$this->channels->expects(self::never())->method('insert');

		foreach ([['addCategory', $this->categories], ['addChannel', $this->channels]] as [$method, $mapper]) {
			try {
				$this->service->$method('Urgencias | CECOSF');
				self::fail("$method must refuse a label carrying the planilla separator");
			} catch (InvalidVocabularyException $e) {
				self::assertSame('El nombre no puede contener «|»: es el separador con el que la planilla une varios valores en una celda.', $e->getMessage());
			}
		}
	}

	public function testAnOverlongLabelIsRefused(): void {
		$this->categories->expects(self::never())->method('insert');

		try {
			$this->service->addCategory(str_repeat('a', 129));
			self::fail('a label past the column length must be refused');
		} catch (InvalidVocabularyException $e) {
			self::assertSame('El nombre no puede pasar de 128 caracteres.', $e->getMessage());
		}
	}

	public function testAnAddedWordIsInsertedFoldedAndAnswersWithTheWholeList(): void {
		$this->categories->expects(self::once())->method('insert')
			->with(self::callback(static function (Category $word): bool {
				return $word->getSlug() === 'salud_mental' && $word->getLabel() === 'Salud Mental';
			}))
			->willReturnArgument(0);
		$this->categories->method('findAll')->willReturn([
			self::category(1, 'dental', 'Dental'),
			self::category(2, 'salud_mental', 'Salud Mental'),
		]);

		$list = $this->service->addCategory('  Salud Mental  ');

		self::assertSame([
			['id' => 1, 'slug' => 'dental', 'label' => 'Dental'],
			['id' => 2, 'slug' => 'salud_mental', 'label' => 'Salud Mental'],
		], $list);
	}

	public function testANameWhoseSlugAlreadyExistsIsRefusedNamingTheLabel(): void {
		$this->categories->method('insert')->willThrowException(self::uniqueViolation());

		try {
			$this->service->addCategory('Comité de Vivienda');
			self::fail('a duplicate slug must be refused');
		} catch (InvalidVocabularyException $e) {
			// Of the name, not of the slug: the slug is derived, so the
			// person never typed it.
			self::assertSame('Ya existe una categoría con ese nombre.', $e->getMessage());
		}
	}

	public function testAChannelWhoseSlugAlreadyExistsIsRefusedNamingTheLabel(): void {
		$this->channels->method('insert')->willThrowException(self::uniqueViolation());

		try {
			$this->service->addChannel('CECOSF');
			self::fail('a duplicate channel slug must be refused');
		} catch (InvalidVocabularyException $e) {
			self::assertSame('Ya existe un canal con ese nombre.', $e->getMessage());
		}
	}

	public function testADatabaseFailureThatIsNotAUniqueViolationEscapesUntranslated(): void {
		$this->categories->method('insert')->willThrowException(new DbException('connection lost'));

		try {
			$this->service->addCategory('Dental');
			self::fail('the raw database failure must escape');
		} catch (InvalidVocabularyException) {
			self::fail('a connection loss is not a refusal anyone can act on');
		} catch (DbException $e) {
			self::assertSame('connection lost', $e->getMessage());
		}
	}

	public function testARemovedCategoryIsRefusedWhileAMedicationIsFiledUnderIt(): void {
		$this->categories->method('findBySlug')->with('dental')->willReturn(self::category(7, 'dental', 'Dental'));
		$this->medications->expects(self::once())->method('countByCategory')->with(7)->willReturn(3);
		$this->categories->expects(self::never())->method('delete');

		try {
			$this->service->removeCategory('dental');
			self::fail('an in-use category must not be removed');
		} catch (InvalidVocabularyException $e) {
			self::assertStringContainsString('«Dental»', $e->getMessage());
			self::assertStringContainsString('hay 3 medicamentos clasificados ahí', $e->getMessage());
			self::assertStringContainsString('incluidos los retirados', $e->getMessage());
		}
	}

	public function testARemovedChannelIsRefusedWhileAnyAssignmentPointsAtIt(): void {
		$this->channels->method('findBySlug')->with('urgencias')->willReturn(self::channel(4, 'urgencias', 'URGENCIAS'));
		$this->assignments->expects(self::once())->method('countByChannel')->with(4)->willReturn(2);
		$this->channels->expects(self::never())->method('delete');

		try {
			$this->service->removeChannel('urgencias');
			self::fail('an in-use channel must not be removed');
		} catch (InvalidVocabularyException $e) {
			self::assertStringContainsString('«URGENCIAS»', $e->getMessage());
			self::assertStringContainsString('hay 2 medicamentos que provienen de este canal', $e->getMessage());
		}
	}

	public function testTheRefusalsCountWithSingulars(): void {
		$this->categories->method('findBySlug')->willReturn(self::category(7, 'dental', 'Dental'));
		$this->medications->method('countByCategory')->willReturn(1);
		$this->channels->method('findBySlug')->willReturn(self::channel(4, 'urgencias', 'URGENCIAS'));
		$this->assignments->method('countByChannel')->willReturn(1);

		try {
			$this->service->removeCategory('dental');
			self::fail('an in-use category must not be removed');
		} catch (InvalidVocabularyException $e) {
			self::assertStringContainsString('hay 1 medicamento clasificado ahí', $e->getMessage());
		}

		try {
			$this->service->removeChannel('urgencias');
			self::fail('an in-use channel must not be removed');
		} catch (InvalidVocabularyException $e) {
			self::assertStringContainsString('hay 1 medicamento que proviene de este canal', $e->getMessage());
		}
	}

	public function testACategoryNothingUsesIsDeletedAndAnswersWithTheList(): void {
		$dental = self::category(7, 'dental', 'Dental');
		$this->categories->method('findBySlug')->willReturn($dental);
		$this->medications->method('countByCategory')->willReturn(0);
		$this->categories->expects(self::once())->method('delete')->with($dental);
		$this->categories->method('findAll')->willReturn([self::category(1, 'corticoides', 'Corticoides')]);

		$list = $this->service->removeCategory('dental');

		self::assertSame([['id' => 1, 'slug' => 'corticoides', 'label' => 'Corticoides']], $list);
	}

	public function testARenameChangesTheLabelAndLeavesTheSlugAlone(): void {
		$dental = self::category(7, 'dental', 'Dental');
		$this->categories->method('findBySlug')->willReturn($dental);
		$this->categories->expects(self::once())->method('update')->with($dental);
		$this->categories->method('findAll')->willReturn([$dental]);

		$this->service->renameCategory('dental', ' Odontología ');

		self::assertSame('dental', $dental->getSlug());
		self::assertSame('Odontología', $dental->getLabel());
	}

	public function testARenameCarryingTheSeparatorIsRefusedAndNothingIsWritten(): void {
		$this->categories->method('findBySlug')->willReturn(self::category(7, 'dental', 'Dental'));
		$this->categories->expects(self::never())->method('update');

		try {
			$this->service->renameCategory('dental', 'Dental | Odontología');
			self::fail('a rename carrying the separator must be refused');
		} catch (InvalidVocabularyException $e) {
			self::assertSame('El nombre no puede contener «|»: es el separador con el que la planilla une varios valores en una celda.', $e->getMessage());
		}
	}

	public function testAnUnknownSlugEscapesForTheControllerToAnswer404(): void {
		$this->categories->method('findBySlug')->willThrowException(new DoesNotExistException('no'));

		$this->expectException(DoesNotExistException::class);
		$this->service->removeCategory('nada');
	}
}
