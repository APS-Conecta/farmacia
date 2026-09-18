<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Farmacia\Controller;

use OCA\Farmacia\AppInfo\Application;
use OCA\Farmacia\Exception\InvalidMedicationException;
use OCA\Farmacia\Service\MedicationService;
use OCA\Farmacia\Service\VocabularyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The vademécum over OCS — the contract a future suite app reads, and the
 * one with write: epi's GET-only route extended on ADR-0006's own taxonomy,
 * which sanctions the resource-per-endpoint shape exactly for mutable things
 * with ids and a CRUD surface — what `medicamentos` is.
 *
 * OCSController rather than a plain Controller for epi's reasons: the OCS
 * envelope is the consumer surface Nextcloud itself serves its dashboards
 * from, and the `OCS-APIRequest` header IS the CSRF protection — verified
 * in-container on this stack (`lib/private/AppFramework/Http/Request.php`'s
 * `passesCSRFCheck` short-circuit, NC 34.0.1), so the POST/PUT here carry no
 * `#[NoCSRFRequired]`: adding one would widen the surface the extracted
 * spec advertises.
 *
 * Consumers send `OCS-APIRequest: true` and get the OCS envelope back:
 *
 *     GET /ocs/v2.php/apps/farmacia/api/v1/medicamentos
 *     { "ocs": { "meta": {…}, "data": [ …the arsenal… ] } }
 *
 * The payloads are the browser's own — MedicationService's one definition
 * (flat fields, ges/restricciones/abastece attached): a consumer integrates
 * against the same shape the app itself renders, which is the whole point
 * of the service stating that doctrine in S3.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class ApiController extends OCSController {
	/**
	 * Reads: a ceiling against a runaway consumer, not a quota (epi's own
	 * words) — the list is one document off local reads, and a consumer
	 * building a page spends one or two.
	 */
	private const READ_LIMIT = 120;

	/**
	 * Writes: tighter than reads on purpose. The write path here is a
	 * person's or an integration's tool; the bulk path is the planilla
	 * import, which has its own staged review. A script hammering POSTs
	 * has missed the door that was built for it, and 20 a minute says so.
	 */
	private const WRITE_LIMIT = 20;

	private const PERIOD = 60;

	/** The FDA pregnancy categories, as MedicationService states them. */
	private const FDA_CATEGORIES = ['A', 'B', 'C', 'D', 'X'];

	public function __construct(
		IRequest $request,
		private MedicationService $medications,
		private VocabularyService $vocabulary,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The live arsenal, narrowed server-side — the UI searches client-side
	 * (its whole document is already in memory); this route narrows for the
	 * consumer who asked over the wire.
	 *
	 * An omitted parameter and an empty one both mean "not asked" — epi's
	 * blank lesson: a consumer building `&gestacion=${picked}` with nothing
	 * picked sent `''`, and `''` matching no FDA category read as "no
	 * medications", precisely the answer this route exists to refuse. What
	 * cannot be understood is refused, never silently ignored: a gestación
	 * outside A–X is 400, a renal_ajuste that is not a flag is 400, an
	 * unknown categoría slug is 404 — an empty list answers only a
	 * question that was understood and happens to have nothing to say.
	 *
	 * ?search= matches what the app's own search matches (filters.js's one
	 * definition): producto, fármaco (DCI) and the category label,
	 * accent-insensitively — folded here with slugFor, the app's one PHP
	 * fold. The código is the record's identity, not its description; a
	 * consumer needing it has the whole list in one document to find it in.
	 *
	 * @param ?string $search free text, matched against producto, fármaco and the category label
	 * @param ?string $categoria a category slug, as the vocabulary is addressed everywhere
	 * @param ?string $gestacion one of A, B, C, D, X
	 * @param ?string $renal_ajuste `1` to keep only renal-adjusted rows, `0` to not narrow
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, list<array<string, mixed>>, array{}>
	 *
	 * 200: The arsenal — every medication's full payload, children attached
	 * 400: The request cannot be understood — {message: what the parameter must be} (gestación outside A–X, renal_ajuste not a flag)
	 * 404: No category carries that slug
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/medicamentos')]
	public function index(
		?string $search = null,
		?string $categoria = null,
		?string $gestacion = null,
		?string $renal_ajuste = null,
	): DataResponse {
		$search = self::blank($search);
		$categoria = self::blank($categoria);
		$gestacion = self::blank($gestacion);
		$renal = self::blank($renal_ajuste);

		// 400, not 422: these two are the request itself being unintelligible
		// — epi's impossible-narrowing rule — where a refusal the request
		// understood carries 422 with a sentence. The sentence still ships,
		// because a consumer debugging a query string deserves the same
		// honesty a person gets in the sidebar.
		if ($gestacion !== null && !in_array($gestacion, self::FDA_CATEGORIES, true)) {
			return new DataResponse(['message' => 'La categoría FDA debe ser A, B, C, D o X.'], Http::STATUS_BAD_REQUEST);
		}
		if ($renal !== null && $renal !== '0' && $renal !== '1') {
			return new DataResponse(['message' => 'El parámetro renal_ajuste es 1 (solo medicamentos con ajuste renal) o 0.'], Http::STATUS_BAD_REQUEST);
		}

		// The vocabulary's own served list, folded into the two maps the
		// narrowing reads: the slug the contract speaks, and the label the
		// search matches — one read, both halves.
		$bySlug = [];
		$labels = [];
		foreach ($this->vocabulary->categories() as $word) {
			$bySlug[$word['slug']] = $word['id'];
			$labels[$word['id']] = $word['label'];
		}

		if ($categoria !== null && !isset($bySlug[$categoria])) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($this->narrow(
			$this->medications->listAll(),
			$search,
			$categoria === null ? null : $bySlug[$categoria],
			$gestacion,
			$renal === '1',
			$labels,
		));
	}

	/**
	 * One medication, new — the same door the sidebar's Guardar goes
	 * through, so the two cannot disagree about what a record is.
	 *
	 * The payload keys are the browser's own (payload.js): codigo, producto,
	 * categoryId, the optional texts, and the three child lists — ges,
	 * restricciones, abastece (channel ids) — sent as request parameters
	 * (form-encoded or query string, the OCS-classic encodings; repeated
	 * `ges[]=…` keys carry the lists).
	 *
	 * @return DataResponse<Http::STATUS_CREATED|Http::STATUS_UNPROCESSABLE_ENTITY, array<string, mixed>, array{}>
	 *
	 * 201: The stored medication, the browser payload shape (children attached)
	 * 422: Understood and refused — {message: the Spanish sentence}, the app's 422 discipline
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/medicamentos')]
	public function create(): DataResponse {
		try {
			return new DataResponse($this->medications->create($this->request->getParams()), Http::STATUS_CREATED);
		} catch (InvalidMedicationException $e) {
			return $this->refuse($e->getMessage());
		}
	}

	/**
	 * One medication by id. A retired record answers with its retired flag
	 * rather than 404: it is recoverable data, and "no such record" would
	 * say a thing that exists does not. The consumer decides what a retired
	 * row means to it — same honesty the sidebar shows.
	 *
	 * @param int $id the medication's id, as the list served it
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_NOT_FOUND, array<string, mixed>, array{}>
	 *
	 * 200: The medication's full payload, children attached, retired flag and all
	 * 404: No medication carries that id
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/medicamentos/{id}')]
	public function show(int $id): DataResponse {
		try {
			return new DataResponse($this->medications->read($id));
		} catch (DoesNotExistException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * One medication, edited — the same door as the sidebar's Guardar, the
	 * same absent-key rule (an unsent field is left alone), the same
	 * refusals in the service's own words: a retired record is not editable,
	 * a duplicate código is named, a new category must exist.
	 *
	 * @param int $id the medication's id, as the list served it
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_NOT_FOUND|Http::STATUS_UNPROCESSABLE_ENTITY, array<string, mixed>, array{}>
	 *
	 * 200: The stored medication as it now reads
	 * 404: No medication carries that id
	 * 422: Understood and refused — {message: the Spanish sentence}
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/medicamentos/{id}')]
	public function update(int $id): DataResponse {
		try {
			return new DataResponse($this->medications->update($id, $this->request->getParams()));
		} catch (InvalidMedicationException $e) {
			return $this->refuse($e->getMessage());
		} catch (DoesNotExistException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * The one narrowing — a fold over the one list, so every parameter's
	 * meaning is stated in one place and the set cannot disagree with
	 * itself. Pure array work on territorio's zero-JOIN reasoning: the
	 * arsenal is one document already assembled, hundreds of rows, read
	 * once per request — the same budget the initial state spends.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param array<int, string> $labels categoryId → label
	 * @return list<array<string, mixed>>
	 */
	private function narrow(array $rows, ?string $search, ?int $categoryId, ?string $gestation, bool $renal, array $labels): array {
		// A punctuation-only search folds to nothing, which is not a
		// question anyone can answer — treating it as a match-everything
		// needle would make `?search=??` the whole arsenal.
		$needle = $search === null ? null : VocabularyService::slugFor($search);
		if ($needle === '') {
			$needle = null;
		}

		return array_values(array_filter($rows, function (array $row) use ($needle, $categoryId, $gestation, $renal, $labels): bool {
			if ($needle !== null) {
				$haystacks = [
					VocabularyService::slugFor((string)($row['producto'] ?? '')),
					VocabularyService::slugFor((string)($row['farmaco'] ?? '')),
					VocabularyService::slugFor((string)($labels[(int)($row['categoryId'] ?? 0)] ?? '')),
				];
				if (!self::contains($haystacks, $needle)) {
					return false;
				}
			}
			if ($categoryId !== null && (int)($row['categoryId'] ?? 0) !== $categoryId) {
				return false;
			}
			if ($gestation !== null && ($row['fdaEmbarazo'] ?? null) !== $gestation) {
				return false;
			}
			if ($renal && in_array($row['ajusteRenal'] ?? null, [null, ''], true)) {
				return false;
			}

			return true;
		}));
	}

	/** @param list<string> $haystacks */
	private static function contains(array $haystacks, string $needle): bool {
		foreach ($haystacks as $haystack) {
			if ($haystack !== '' && str_contains($haystack, $needle)) {
				return true;
			}
		}

		return false;
	}

	/** An omitted or whitespace-only parameter is not a question. */
	private static function blank(?string $value): ?string {
		$value = trim((string)$value);

		return $value === '' ? null : $value;
	}

	private function refuse(string $message): DataResponse {
		// The create/update refusals only — understood and refused, the app's
		// 422 discipline. index()'s unintelligible-query refusals answer 400
		// inline, where they happen, so the two statuses cannot be confused
		// by a shared door.
		return new DataResponse(['message' => $message], Http::STATUS_UNPROCESSABLE_ENTITY);
	}
}
