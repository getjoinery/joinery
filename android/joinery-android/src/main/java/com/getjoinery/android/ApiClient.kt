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
    ): JsonValue {
        val urlBuilder = config.baseUrl.toHttpUrl().newBuilder().encodedPath(path)
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

        return execute(builder.build(), authenticated, http)
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
        val url = config.baseUrl.toHttpUrl().newBuilder()
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

    private suspend fun execute(request: Request, authenticated: Boolean, client: OkHttpClient): JsonValue {
        val (status, text) = try {
            withContext(Dispatchers.IO) {
                client.newCall(request).execute().use { response ->
                    response.code to (response.body?.string() ?: "")
                }
            }
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
    ): JsonValue = request(
        "POST", "/api/v1/action/$action",
        body = body,
        authenticated = authenticated,
        idempotencyKey = idempotencyKey ?: java.util.UUID.randomUUID().toString(),
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
        fun defaultHttpClient(): OkHttpClient = OkHttpClient.Builder()
            .connectTimeout(30, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .callTimeout(30, TimeUnit.SECONDS)
            .build()
    }
}
