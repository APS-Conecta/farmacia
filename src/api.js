/**
 * The client's one error shape (L2-14/L2-15) — territorio's src/api.js,
 * restated for this app until the org canon wave promotes it to
 * aps-common's js/ half (where fold.js already lives). messageFrom never
 * invents a message; safeError/warnError never log the object that carries
 * config.data — here that object is a MEDICATION payload (indicación,
 * dosificación, precauciones, FDA, ajuste renal) or the planilla FormData
 * itself, which is heavier than territorio's case.
 */

/** @returns {string} the server's sentence, or the fallback it was handed */
export function messageFrom(exception, fallback) {
	return exception?.response?.data?.message ?? fallback
}

export function safeError(label, exception) {
	const status = exception?.response?.status
	console.error(`farmacia: ${label} (${status ?? 'sin respuesta'})`,
		exception?.message ?? String(exception))
}

export const warnError = (label, exception) => {
	const status = exception?.response?.status
	console.warn(`farmacia: ${label} (${status ?? 'sin respuesta'})`,
		exception?.message ?? String(exception))
}
