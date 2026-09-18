<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Command;

use OCA\Farmacia\Exception\InvalidImportException;
use OCA\Farmacia\Service\ApplyService;
use OCA\Farmacia\Service\ImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ farmacia:import <archivo.csv> [--actor=…]
 *
 * The headless half of importing: the browser upload goes through the same
 * ImportService, so the two cannot drift.
 *
 * Extends Symfony's Command directly, not OC\Core\Command\Base: Base lives
 * outside OCP and this app is OCP-only — territorio's Command precedent.
 */
class Import extends Command {
	public function __construct(
		private ImportService $import,
		private ApplyService $apply,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('farmacia:import')
			->setDescription('Etapa una planilla CSV del vademécum para revisión')
			->addArgument('archivo', InputArgument::REQUIRED, 'Ruta al archivo .csv')
			// There is no logged-in user on the command line, so the batch
			// would otherwise record what happened and never who asked for it.
			->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Quién ejecuta la importación', 'occ')
			// The clean-planilla path: stage and land in one run, for the file
			// whose every row resolved at stage time. A planilla that still
			// owes decisions is refused here and stays staged — the decisions
			// are the review screen's to take, and the refusal says so.
			->addOption('apply', null, InputOption::VALUE_NONE, 'Aplicar el lote inmediatamente después de revisarlo');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = (string)$input->getArgument('archivo');

		if (!is_readable($path)) {
			$output->writeln(sprintf('<error>No se puede leer %s</error>', $path));

			return Command::INVALID;
		}

		try {
			$review = $this->import->stage(
				(string)file_get_contents($path),
				basename($path),
				(string)$input->getOption('actor'),
			);
		} catch (InvalidImportException $e) {
			// Refused while reading: nothing was staged.
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}

		$batch = $review['batch'];
		$output->writeln(sprintf(
			'Lote #%d en revisión — %d por crear, %d por actualizar, %d sin cambios, '
			. '%d omitidos por retirados, %d duplicados, %d con problemas',
			$batch['id'],
			$batch['created'],
			$batch['updated'],
			$batch['unchanged'],
			$batch['skipped'],
			$batch['duplicates'],
			$batch['unsupported'],
		));

		$undecided = count(array_filter(
			$review['rows'],
			static fn (array $row): bool => $row['status'] === 'unresolved-vocabulary',
		));
		if ($undecided > 0) {
			$output->writeln(sprintf('%d fila(s) esperan una decisión de vocabulario.', $undecided));
		}

		if (!$input->getOption('apply')) {
			$output->writeln('Revise el lote en Farmacia: Importar planilla.');

			return Command::SUCCESS;
		}

		try {
			$applied = $this->apply->apply((int)$batch['id']);
		} catch (InvalidImportException $e) {
			// Refused while applying: nothing landed, and the batch is still
			// staged exactly as the review left it.
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}

		$appliedBatch = $applied['batch'];
		$output->writeln(sprintf(
			'Lote #%d aplicado — %d por crear, %d por actualizar, %d sin cambios, '
			. '%d omitidos por retirados, %d duplicados, %d con problemas',
			$appliedBatch['id'],
			$appliedBatch['created'],
			$appliedBatch['updated'],
			$appliedBatch['unchanged'],
			$appliedBatch['skipped'],
			$appliedBatch['duplicates'],
			$appliedBatch['unsupported'],
		));

		return Command::SUCCESS;
	}
}
