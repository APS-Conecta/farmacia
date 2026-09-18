<?php

declare(strict_types=1);

namespace OC\Hooks;

/**
 * The one server class the OCP stub package does not ship. `OCP\Files\IRootFolder`
 * extends it, so any unit test that mocks a Files-tree reader dies in the bare
 * container with "Interface OC\Hooks\Emitter not found" — territorio hit
 * exactly this wall before #104's refusal paths could be unit-tested at all.
 * Empty on purpose, and it cannot drift: an app may only call OCP, so nothing
 * here ever calls a method of it. It arrives with this slice though nothing in
 * it needs it yet, because the import slice (Files-tree uploads) is where it
 * becomes load-bearing, and a stub found then would be a rediscovery.
 */
interface Emitter {
}