<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Service;

use DateTime;

/**
 * The whole arsenal as one planilla — the export the import is the other
 * half of. Territorio's export answers a filtered set in four formats;
 * farmacia has one file of one set, so this is the thin one: the live
 * arsenal through the one definition every consumer reads
 * (MedicationService::listAll — the browser's own document), the vocabulary
 * labels the planilla speaks, and the writer's dialect. Nothing is
 * filtered, because a narrowed copy would quietly stop being the
 * vademécum, and there is nothing to confirm or record — no personal data
 * anywhere in the app (the Not-Building line), so territorio's password
 * prompt and export log have no counterpart here.
 *
 * Retired records are deliberately absent: the export is the arsenal, the
 * same set the app shows, and a re-import would only skip them anyway —
 * retirement is a local decision an import must not undo (ImportService's
 * own rule). The retired set stays reachable in the app, which is where
 * recovering it belongs.
 */
class ExportService {
	public function __construct(
		private MedicationService $medications,
		private VocabularyService $vocabulary,
		private CsvWriter $writer,
	) {
	}

	/**
	 * The arsenal as the {filename, mime, body} trio a download needs —
	 * territorio's export shape, minus the format parameter the app does
	 * not have.
	 *
	 * @return array{filename: string, mime: string, body: string}
	 */
	public function export(): array {
		$categoryLabels = $this->labels($this->vocabulary->categories());
		$channelLabels = $this->labels($this->vocabulary->abastece());

		$rows = array_map(
			function (array $row) use ($categoryLabels, $channelLabels): array {
				return $row + [
					// Labels, not ids: the planilla speaks the words the people
					// reading it use, and the import folds a label back the way a
					// hand-typed planilla is folded. A word renamed after this
					// medication was classified under it resurfaces on
					// re-import as a review decision — the label folds to a slug
					// the stored (kept) slug no longer answers to — rather than
					// silently matching: the boundary RoundTripTest pins, and
					// the review is the surface designed to settle it.
					'categoriaLabel' => $categoryLabels[(int)$row['categoryId']] ?? '',
					'abasteceLabels' => array_map(
						static fn (int $id): string => $channelLabels[$id] ?? '',
						$row['abastece'],
					),
				];
			},
			$this->medications->listAll(),
		);

		return [
			'filename' => 'farmacia-' . (new DateTime())->format('Y-m-d') . '.csv',
			'mime' => 'text/csv; charset=utf-8',
			'body' => $this->writer->write($rows),
		];
	}

	/**
	 * @param list<array{id: int, slug: string, label: string}> $words
	 * @return array<int, string> id → label
	 */
	private function labels(array $words): array {
		$labels = [];
		foreach ($words as $word) {
				$labels[$word['id']] = $word['label'];
		}

		return $labels;
	}
}
