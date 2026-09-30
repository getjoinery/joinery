package com.getjoinery.android

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicReference

/** One file part for a `multipart/form-data` action submit. [field] is the
 *  form field name, e.g. `attachments[]` (PHP folds the `[]` suffix into an
 *  `$_FILES['attachments']` array so several files share one field). */
class MultipartFile(
    val field: String,
    val filename: String,
    val mimeType: String,
    val data: ByteArray,
)

/**
 * The one HTTP chokepoint. Every joinery-android request — auth, forms,
 * actions — goes through here, so client headers, key headers, idempotency
 * keys, and error-envelope mapping happen exactly once.
 */
class ApiClient(
    val config: JoineryConfig,
    private val http: OkHttpClient = defaultHttpClient(),
) {
    /** Current session key pair, null when signed out. Written from the caller,
     *  read on request threads. */
    private val credentialsRef = AtomicReference<ApiCredentials?>(null)

    var credentials: ApiCredentials?
        get() = credentialsRef.get()
        set(value) = credentialsRef.set(value)

    /** Fired on any 426 UpgradeRequired so the app can flip into the blocking
     *  upgrade screen no matter which call tripped it. */
    var upgradeRequiredHandler: ((String) -> Unit)? = null

    /** Fired when an authenticated request comes back 401 — the key was revoked
     *  out from under us (App Sessions page, password change). */
    var sessionInvalidatedHandler: (() -> Unit)? = null

    private val jsonMediaType = "application/json".toMediaType()

    // MARK: Requests

    /**
     * Perform a request and return the parsed success envelope.
     * - [body] non-null sends a JSON body.
     * - [authenticated] attaches the session key headers.
     * - [idempotencyKey] is attached verbatim when given — pass a fresh UUID per
     *   logical mutating operation (docs/api.md § Idempotent writes).
     */
    suspend fun request(
        method: String,
        path: String,
        query: List<Pair<String, String>> = emptyList(),
        body: JsonValue? = null,
        authenticated: Boolean = true,
        idempotencyKey: String? = null,
        maxResponseBytes: Long? = null,
    ): JsonValue {
        val urlBuilder = requireHttps(config.baseUrl).newBuilder().encodedPath(path)
        query.forEach { urlBuilder.addQueryParameter(it.first, it.second) }

        val builder = Request.Builder()
            .url(urlBuilder.build())
            // Custom headers use hyphen form — proxy/FPM stacks drop underscore
            // header names (docs/api.md).
            .header("Accept", "application/json")
            .header("client-app", config.clientApp)
            .header("client-version", config.clientVersion)

        if (authenticated) {
            credentials?.let {
                builder.header("public-key", it.publicKey)
                builder.header("secret-key", it.secretKey)
            }
        }
        idempotencyKey?.let { builder.header("Idempotency-Key", it) }

        val requestBody = body?.encodedBytes()?.toRequestBody(jsonMediaType)
        builder.method(method, requestBody ?: emptyBodyIfNeeded(method))

        return execute(builder.build(), authenticated, http, maxResponseBytes)
    }

    /**
     * Submit an action as `multipart/form-data`: `POST /api/v1/action/{action}`,
     * with [fields] as text parts and [files] as file parts — for uploads the
     * JSON body can't carry (mail/chat attachments). The action pipeline reads
     * the text parts as `$_POST` and the file parts as `$_FILES`. Mutating, so
     * an idempotency key is generated unless supplied; server-side it hashes
     * over the (empty) raw body, so a multipart send dedupes retries by key
     * alone.
     */
    suspend fun submitMultipart(
        action: String,
        fields: List<Pair<String, String>>,
        files: List<MultipartFile>,
        authenticated: Boolean = true,
        idempotencyKey: String? = null,
    ): JsonValue {
        val url = requireHttps(config.baseUrl).newBuilder()
            .encodedPath("/api/v1/action/$action").build()

        val multipart = MultipartBody.Builder().setType(MultipartBody.FORM)
        fields.forEach { multipart.addFormDataPart(it.first, it.second) }
        files.forEach { file ->
            // Sanitized so a quote or newline can't break the part header.
            val safeName = file.filename
                .replace("\"", "'").replace("\r", " ").replace("\n", " ")
            multipart.addFormDataPart(
                file.field, safeName,
                file.data.toRequestBody(file.mimeType.toMediaTypeOrNull()),
            )
        }

        val builder = Request.Builder()
            .url(url)
            .header("Accept", "application/json")
            .header("client-app", config.clientApp)
            .header("client-version", config.clientVersion)
            .header("Idempotency-Key", idempotencyKey ?: java.util.UUID.randomUUID().toString())
        if (authenticated) {
            credentials?.let {
                builder.header("public-key", it.publicKey)
                builder.header("secret-key", it.secretKey)
            }
        }
        builder.post(multipart.build())

        // Uploads can be several MB — allow more headroom than a JSON call.
        return execute(builder.build(), authenticated, uploadHttp)
    }

    private suspend fun execute(request: Request, authenticated: Boolean, client: OkHttpClient, maxBytes: Long? = null): JsonValue {
        val (status, text) = try {
            withContext(Dispatchers.IO) {
                client.newCall(request).execute().use { response ->
                    response.code to readBody(response, maxBytes)
                }
            }
        } catch (e: JoineryApiError.TooLarge) {
            throw e
        } catch (e: Exception) {
            throw JoineryApiError.Network(e)
        }

        val json = try {
            JsonValue.parse(text)
        } catch (e: Exception) {
            throw JoineryApiError.Malformed
        }

        if (status >= 400) throw mapError(status, json, authenticated)
        return json
    }

    /**
     * GET a URL's raw bytes — a signed download link, which authorizes itself,
     * so no key headers and no cookies ride along. A relative URL resolves
     * against the deployment. The server's own refusal pages are HTML, so an
     * HTML answer is a failure, never bytes.
     */
    suspend fun fetchBytes(url: String): ByteArray {
        val absolute = if (url.startsWith("http://") || url.startsWith("https://")) url
        else config.baseUrl.trimEnd('/') + "/" + url.trimStart('/')
        val request = Request.Builder().url(requireHttps(absolute))
            .header("client-app", config.clientApp)
            .header("client-version", config.clientVersion)
            .get().build()
        return try {
            withContext(Dispatchers.IO) {
                uploadHttp.newCall(request).execute().use { response ->
                    val type = response.header("Content-Type") ?: ""
                    if (!response.isSuccessful || type.contains("text/html", ignoreCase = true)) {
                        throw JoineryApiError.Server("NotFound", "This file could not be fetched.", response.code)
                    }
                    response.body?.bytes() ?: ByteArray(0)
                }
            }
        } catch (e: JoineryApiError) {
            throw e
        } catch (e: Exception) {
            throw JoineryApiError.Network(e)
        }
    }

    /**
     * POST raw JSON to the person's own AI endpoint. No Joinery header or key
     * rides along. The URL must be on [registeredOrigin] (the account's
     * `device_ai_origin`): the one place plain http is allowed, because the
     * server registers an http origin only on a private network.
     * Returns (status, body).
     */
    suspend fun postExternal(
        url: String,
        registeredOrigin: String,
        body: ByteArray,
        headers: Map<String, String>,
        timeoutSeconds: Long = 120,
    ): Pair<Int, String> {
        val target = requireRegisteredOrigin(url, registeredOrigin)
        val builder = Request.Builder().url(target).post(body.toRequestBody(jsonMediaType))
        headers.forEach { (k, v) -> builder.header(k, v) }
        // One hop only: a redirect is an answer (the caller treats 3xx as an
        // error), never a second request — it would carry the opened message to
        // a host nobody registered, possibly over plain http.
        val client = http.newBuilder()
            .followRedirects(false)
            .followSslRedirects(false)
            .readTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .callTimeout(timeoutSeconds, TimeUnit.SECONDS)
            .build()
        return try {
            withContext(Dispatchers.IO) {
                client.newCall(builder.build()).execute().use { it.code to (it.body?.string() ?: "") }
            }
        } catch (e: Exception) {
            throw JoineryApiError.Network(e)
        }
    }

    /** The body as text, refusing to hold more than [maxBytes] of it. */
    private fun readBody(response: okhttp3.Response, maxBytes: Long?): String {
        val body = response.body ?: return ""
        if (maxBytes == null) return body.string()
        if (body.contentLength() > maxBytes) throw JoineryApiError.TooLarge(maxBytes)
        val source = body.source()
        if (source.request(maxBytes + 1)) throw JoineryApiError.TooLarge(maxBytes)
        return source.buffer.readUtf8()
    }

    private val uploadHttp: OkHttpClient by lazy {
        http.newBuilder()
            .writeTimeout(120, TimeUnit.SECONDS)
            .readTimeout(120, TimeUnit.SECONDS)
            .callTimeout(120, TimeUnit.SECONDS)
            .build()
    }

    /**
     * GET a form definition: `/api/v1/form/{action}`. Sessionless forms
     * (password resets, register) pass `authenticated = false`.
     */
    suspend fun formDefinition(
        action: String,
        query: List<Pair<String, String>> = emptyList(),
        authenticated: Boolean = true,
    ): FormDefinition {
        val envelope = request("GET", "/api/v1/form/$action", query = query, authenticated = authenticated)
        return FormDefinition.from(envelope["data"]) ?: throw JoineryApiError.Malformed
    }

    /**
     * Submit an action: `POST /api/v1/action/{action}`. Mutating, so an
     * idempotency key is generated automatically unless one is supplied.
     */
    suspend fun submitAction(
        action: String,
        body: JsonValue,
        authenticated: Boolean = true,
        idempotencyKey: String? = null,
        maxResponseBytes: Long? = null,
    ): JsonValue = request(
        "POST", "/api/v1/action/$action",
        body = body,
        authenticated = authenticated,
        idempotencyKey = idempotencyKey ?: java.util.UUID.randomUUID().toString(),
        maxResponseBytes = maxResponseBytes,
    )

    // MARK: Error mapping

    /** Internal (not private) so unit tests can exercise the mapping table. */
    internal fun mapError(status: Int, envelope: JsonValue, authenticated: Boolean): JoineryApiError {
        val errortype = envelope["errortype"]?.stringValue ?: ""
        val message = envelope["error"]?.stringValue ?: "Request failed."

        if (status == 426 || errortype == "UpgradeRequired") {
            // SecurityError 426 is HTTPS-only enforcement; a shipped app is
            // always HTTPS, so any 426 in practice is the upgrade gate.
            if (errortype != "SecurityError") {
                upgradeRequiredHandler?.invoke(message)
                return JoineryApiError.UpgradeRequired(message)
            }
        }
        if (errortype == "RateLimitError") return JoineryApiError.RateLimited(message)
        if (errortype == "AuthenticationError") {
            if (authenticated && status == 401) sessionInvalidatedHandler?.invoke()
            return JoineryApiError.Authentication(message, status)
        }
        if (status == 422) {
            // ValidationError carries a field-keyed map; other 422s (model save
            // failures surface as ActionError) carry only the message.
            val fields = LinkedHashMap<String, String>()
            envelope["validation_errors"]?.objectValue?.forEach { (key, value) ->
                fields[key] = value.stringValue ?: ""
            }
            return JoineryApiError.Validation(message, fields)
        }
        return JoineryApiError.Server(errortype, message, status)
    }

    private fun emptyBodyIfNeeded(method: String) =
        if (method == "POST" || method == "PUT" || method == "PATCH" || method == "DELETE")
            ByteArray(0).toRequestBody(jsonMediaType)
        else null

    companion object {
        /**
         * Every request carrying a Joinery credential or mail bytes goes over
         * https, whatever the platform allows (the app permits cleartext only
         * for the AI endpoint on the person's network): the deployment, signed
         * part and draft links alike. Anything else is refused before a byte leaves.
         */
        fun requireHttps(url: String): okhttp3.HttpUrl {
            val parsed = try { url.toHttpUrl() } catch (e: Exception) { throw JoineryApiError.InsecureUrl(url) }
            if (!parsed.isHttps) throw JoineryApiError.InsecureUrl(url)
            return parsed
        }

        /** [url] must share [registeredOrigin]'s scheme, host and port exactly. */
        fun requireRegisteredOrigin(url: String, registeredOrigin: String): okhttp3.HttpUrl {
            val parsed = try { url.toHttpUrl() } catch (e: Exception) { throw JoineryApiError.InsecureUrl(url) }
            val origin = try { registeredOrigin.trimEnd('/').toHttpUrl() } catch (e: Exception) { throw JoineryApiError.InsecureUrl(url) }
            if (parsed.scheme != origin.scheme || parsed.host != origin.host || parsed.port != origin.port) {
                throw JoineryApiError.InsecureUrl(url)
            }
            return parsed
        }

        fun defaultHttpClient(): OkHttpClient = OkHttpClient.Builder()
            .connectTimeout(30, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .callTimeout(30, TimeUnit.SECONDS)
            .build()
    }
}
