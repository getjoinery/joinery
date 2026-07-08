/**
 * JoineryPasskeys - vanilla WebAuthn helper wrapping navigator.credentials
 * create()/get(). Handles base64url conversion and PRF extension wiring so
 * page scripts only deal with plain JSON: fetch the server's options,
 * pass them to register()/authenticate()/derive(), then POST the returned
 * object back to the matching verify action.
 *
 * @version 1.0
 */
window.JoineryPasskeys = (function () {
	'use strict';

	function isSupported() {
		return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);
	}

	/**
	 * Best-effort PRF availability probe. Never load-bearing - callers must
	 * treat a false/failed result as "offer a non-PRF fallback," not as
	 * definitive proof the authenticator lacks PRF (many authenticators only
	 * reveal support at the first real evaluation).
	 */
	async function isPrfLikely() {
		if (!isSupported()) return false;
		try {
			if (window.PublicKeyCredential.getClientCapabilities) {
				var caps = await window.PublicKeyCredential.getClientCapabilities();
				if (typeof caps.extension_prf === 'boolean') return caps.extension_prf;
			}
		} catch (e) { /* fall through to the coarser probe below */ }
		try {
			return await window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
		} catch (e) {
			return false;
		}
	}

	function b64urlToBuffer(b64url) {
		var base64 = String(b64url).replace(/-/g, '+').replace(/_/g, '/');
		while (base64.length % 4) base64 += '=';
		var binary = atob(base64);
		var bytes = new Uint8Array(binary.length);
		for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
		return bytes.buffer;
	}

	function bufferToB64url(buffer) {
		var bytes = new Uint8Array(buffer);
		var binary = '';
		for (var i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i]);
		return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	function decodeExtensions(extensions) {
		var out = Object.assign({}, extensions);
		if (extensions.prf && extensions.prf.eval) {
			out.prf = { eval: { first: b64urlToBuffer(extensions.prf.eval.first) } };
			if (extensions.prf.eval.second) {
				out.prf.eval.second = b64urlToBuffer(extensions.prf.eval.second);
			}
		}
		return out;
	}

	function decodeCreationOptions(options) {
		var publicKey = Object.assign({}, options);
		publicKey.challenge = b64urlToBuffer(options.challenge);
		publicKey.user = Object.assign({}, options.user, { id: b64urlToBuffer(options.user.id) });
		if (options.excludeCredentials) {
			publicKey.excludeCredentials = options.excludeCredentials.map(function (c) {
				return Object.assign({}, c, { id: b64urlToBuffer(c.id) });
			});
		}
		if (options.extensions) publicKey.extensions = decodeExtensions(options.extensions);
		return publicKey;
	}

	function decodeRequestOptions(options) {
		var publicKey = Object.assign({}, options);
		publicKey.challenge = b64urlToBuffer(options.challenge);
		if (options.allowCredentials) {
			publicKey.allowCredentials = options.allowCredentials.map(function (c) {
				return Object.assign({}, c, { id: b64urlToBuffer(c.id) });
			});
		}
		if (options.extensions) publicKey.extensions = decodeExtensions(options.extensions);
		return publicKey;
	}

	/** clientExtensionResults carries ArrayBuffers (PRF output) that must be
	 *  base64url-encoded before the object can be JSON.stringify'd and posted. */
	function encodeClientExtensionResults(credential) {
		var raw = credential.getClientExtensionResults ? credential.getClientExtensionResults() : {};
		var out = Object.assign({}, raw);
		if (raw.prf) {
			out.prf = Object.assign({}, raw.prf);
			if (raw.prf.results) {
				out.prf.results = {};
				if (raw.prf.results.first) out.prf.results.first = bufferToB64url(raw.prf.results.first);
				if (raw.prf.results.second) out.prf.results.second = bufferToB64url(raw.prf.results.second);
			}
		}
		return out;
	}

	function encodeAttestationResponse(credential) {
		var response = credential.response;
		return {
			id: credential.id,
			rawId: bufferToB64url(credential.rawId),
			type: credential.type,
			response: {
				clientDataJSON: bufferToB64url(response.clientDataJSON),
				attestationObject: bufferToB64url(response.attestationObject),
				transports: (response.getTransports && response.getTransports()) || [],
			},
			clientExtensionResults: encodeClientExtensionResults(credential),
		};
	}

	function encodeAssertionResponse(credential) {
		var response = credential.response;
		return {
			id: credential.id,
			rawId: bufferToB64url(credential.rawId),
			type: credential.type,
			response: {
				clientDataJSON: bufferToB64url(response.clientDataJSON),
				authenticatorData: bufferToB64url(response.authenticatorData),
				signature: bufferToB64url(response.signature),
				userHandle: response.userHandle ? bufferToB64url(response.userHandle) : null,
			},
			clientExtensionResults: encodeClientExtensionResults(credential),
		};
	}

	/** Runs the creation ceremony; returns a JSON-ready object for passkey_register_verify. */
	async function register(optionsJson) {
		var publicKey = decodeCreationOptions(optionsJson);
		var credential = await navigator.credentials.create({ publicKey: publicKey });
		if (!credential) throw new Error('Passkey creation was cancelled.');
		return encodeAttestationResponse(credential);
	}

	/** Runs the request ceremony; returns a JSON-ready object for the login/step-up verify actions. */
	async function authenticate(optionsJson) {
		var publicKey = decodeRequestOptions(optionsJson);
		var credential = await navigator.credentials.get({ publicKey: publicKey });
		if (!credential) throw new Error('Passkey sign-in was cancelled.');
		return encodeAssertionResponse(credential);
	}

	/**
	 * Like authenticate(), but for options carrying a PRF extension request.
	 * Returns { response, prfOutput } - response is the same JSON-ready object
	 * authenticate() returns (POST it to the consumer's derive-verify action);
	 * prfOutput is the base64url-encoded derived secret for immediate local use.
	 */
	async function derive(optionsJson) {
		var response = await authenticate(optionsJson);
		var results = response.clientExtensionResults && response.clientExtensionResults.prf
			? response.clientExtensionResults.prf.results : null;
		return {
			response: response,
			prfOutput: results && results.first ? results.first : null,
		};
	}

	/**
	 * The full options -> authenticate -> verify round trip shared by every
	 * password-flow passkey button (sign-in, password reset, reset second factor).
	 * POSTs {} (plus any extraBody) to optionsUrl, runs the request ceremony, POSTs
	 * the credential to verifyUrl, and resolves with verify's `data` object (e.g.
	 * { redirect, second_factor_required }). Throws with the server error message on
	 * any failure so callers can surface it uniformly.
	 */
	async function runFlow(optionsUrl, verifyUrl, extraBody) {
		var optRes = await fetch(optionsUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(extraBody || {}),
		});
		var optJson = await optRes.json();
		if (!optRes.ok) throw new Error((optJson && optJson.error) || 'Unable to start passkey ceremony.');

		var credential = await authenticate(optJson.data.options);

		var verRes = await fetch(verifyUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ credential: credential }),
		});
		var verJson = await verRes.json();
		if (!verRes.ok) throw new Error((verJson && verJson.error) || 'Passkey verification failed.');

		return (verJson && verJson.data) || {};
	}

	return {
		isSupported: isSupported,
		isPrfLikely: isPrfLikely,
		register: register,
		authenticate: authenticate,
		derive: derive,
		runFlow: runFlow,
		bufferToB64url: bufferToB64url,
		b64urlToBuffer: b64urlToBuffer,
	};
})();
