<?php

declare(strict_types=1);

// The route table grows by slice: import/export (S7+) adds the controllers
// that own them. The OCS surface (S11) does NOT live here — it registers by
// attribute on its own controller, and its public URL is Spanish
// (/api/v1/medicamentos) where these internal ones are English, the
// language split applied to identifiers («abastece» excepted: it is the
// domain's own word, CONTEXT.md's, and there is no English name for a
// thing each CESFAM defines for itself).
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Every user who can open the app may edit the vademécum; the safety
		// net is retirement, not permissions.
		['name' => 'medication#create', 'url' => '/medications', 'verb' => 'POST'],
		['name' => 'medication#update', 'url' => '/medications/{id}', 'verb' => 'PUT',
			'requirements' => ['id' => '\d+']],
		// Retires rather than destroys. /medications/retired cannot be confused
		// with /medications/{id} because no GET route takes an {id} — the
		// structural invariant territorio documents at its own table.
		['name' => 'medication#retired', 'url' => '/medications/retired', 'verb' => 'GET'],
		['name' => 'medication#destroy', 'url' => '/medications/{id}', 'verb' => 'DELETE',
			'requirements' => ['id' => '\d+']],
		['name' => 'medication#restore', 'url' => '/medications/{id}/restore', 'verb' => 'POST',
			'requirements' => ['id' => '\d+']],

		// The vocabulary is the clinic's own data the moment the seed lands
		// (EnsureSeedData's words): both lists are editable by the whole team,
		// for the same reason every other write here is — the safety net is
		// the in-use guard, not permissions. Addressed by slug because the
		// slug survives a rename; no `?force` anywhere, because removal is
		// refused while anything still references the word (territorio#ADR-0016's
		// shape) and there is nothing for a caller to choose.
		['name' => 'category#create', 'url' => '/categories', 'verb' => 'POST'],
		['name' => 'category#update', 'url' => '/categories/{slug}', 'verb' => 'PUT'],
		['name' => 'category#destroy', 'url' => '/categories/{slug}', 'verb' => 'DELETE'],
		['name' => 'abastece#create', 'url' => '/abastece', 'verb' => 'POST'],
		['name' => 'abastece#update', 'url' => '/abastece/{slug}', 'verb' => 'PUT'],
		['name' => 'abastece#destroy', 'url' => '/abastece/{slug}', 'verb' => 'DELETE'],

		// Importing is open to every user, like every other change: the safety
		// net is the staged review — nothing lands in the medication tables
		// until the batch is applied from that review — not permissions. The
		// occ command runs through the same service, so the two doors cannot
		// drift.
		//
		// batches is declared before review on purpose: 'batches' would bind
		// to {id} and the review would answer for a batch called «batches».
		// The medication block above avoids the collision structurally (no
		// GET takes an {id}); this block cannot, because reopening a staged
		// batch after a page reload IS a GET by id.
		['name' => 'import#upload', 'url' => '/import', 'verb' => 'POST'],
		['name' => 'import#batches', 'url' => '/import/batches', 'verb' => 'GET'],
		['name' => 'import#review', 'url' => '/import/{id}', 'verb' => 'GET',
			'requirements' => ['id' => '\d+']],
		['name' => 'import#decide', 'url' => '/import/rows/{id}/decision', 'verb' => 'POST',
			'requirements' => ['id' => '\d+']],

		// Landing and unlanding a review: apply walks the rows the person
		// approved, discard closes the batch without landing anything, and
		// revert takes an applied batch back out (territorio's import#revert
		// shape). POST, all three: each one moves the batch's state.
		['name' => 'import#apply', 'url' => '/import/{id}/apply', 'verb' => 'POST',
			'requirements' => ['id' => '\d+']],
		['name' => 'import#discard', 'url' => '/import/{id}/discard', 'verb' => 'POST',
			'requirements' => ['id' => '\d+']],
		['name' => 'import#revert', 'url' => '/import/{id}/revert', 'verb' => 'POST',
			'requirements' => ['id' => '\d+']],

		// The export is the import's mirror: the same grammar, the other
		// direction. One route, no parameters — the whole live arsenal is
		// the export (a filtered copy would quietly stop being the
		// vademécum), so there is nothing for a caller to choose, and GET
		// makes the download a plain link the browser navigates to.
		['name' => 'export#plain', 'url' => '/export', 'verb' => 'GET'],
	],
];
