package com.getjoinery.dnsfilter

import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.util.concurrent.TimeUnit

/**
 * Forwards captured DNS queries to the device's resolver over DNS-over-HTTPS
 * (RFC 8484): `POST {doh_url}` with `Content-Type: application/dns-message` and
 * the raw DNS query as the body; the resolver applies this device's policy
 * server-side (identified by the UID baked into the URL) and returns the DNS
 * answer as the response body. Standard mode is exactly this — the app moves
 * the packets, the server decides.
 *
 * Called synchronously from the VpnService pump thread (each query already runs
 * on its own worker), so the calls block; the client carries tight timeouts so
 * a stalled resolver can't wedge a worker.
 */
class DohClient(private val dohUrl: String) {
    private val http: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(5, TimeUnit.SECONDS)
        .readTimeout(5, TimeUnit.SECONDS)
        .callTimeout(6, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    /** Resolve one DNS query, returning the raw DNS answer, or null on any
     *  network/HTTP failure (the pump drops the query; the client retries). */
    fun resolve(query: ByteArray): ByteArray? {
        return try {
            val request = Request.Builder()
                .url(dohUrl)
                .header("Accept", DNS_MESSAGE)
                .post(query.toRequestBody(DNS_MESSAGE.toMediaType()))
                .build()
            http.newCall(request).execute().use { response ->
                if (!response.isSuccessful) return null
                response.body?.bytes()
            }
        } catch (e: Exception) {
            null
        }
    }

    private companion object {
        const val DNS_MESSAGE = "application/dns-message"
    }
}
