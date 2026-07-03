package com.getjoinery.android

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ErrorMappingTest {

    private fun client() = ApiClient(
        JoineryConfig(baseUrl = "https://example.test", clientApp = "test-app", clientVersion = "0.0.1", appName = "Test"),
    )

    private fun env(json: String) = JsonValue.parse(json)

    @Test
    fun upgradeRequiredMapsAndFiresHandler() {
        val c = client()
        var received = ""
        c.upgradeRequiredHandler = { received = it }
        val error = c.mapError(
            426,
            env("""{"api_version":"1.0","errortype":"UpgradeRequired","error":"Please update","data":{}}"""),
            authenticated = false,
        )
        assertTrue(error is JoineryApiError.UpgradeRequired)
        assertEquals("Please update", (error as JoineryApiError.UpgradeRequired).text)
        assertEquals("Please update", received)
    }

    @Test
    fun httpsSecurity426IsNotUpgrade() {
        val error = client().mapError(
            426,
            env("""{"api_version":"1.0","errortype":"SecurityError","error":"HTTPS required","data":{}}"""),
            authenticated = false,
        )
        assertFalse(error is JoineryApiError.UpgradeRequired)
    }

    @Test
    fun rateLimitMaps() {
        val error = client().mapError(
            429,
            env("""{"api_version":"1.0","errortype":"RateLimitError","error":"Too many attempts","data":{}}"""),
            authenticated = false,
        )
        assertTrue(error is JoineryApiError.RateLimited)
        assertEquals("Too many attempts", (error as JoineryApiError.RateLimited).text)
    }

    @Test
    fun login401DoesNotInvalidateSession() {
        val c = client()
        var invalidated = false
        c.sessionInvalidatedHandler = { invalidated = true }
        val error = c.mapError(
            401,
            env("""{"api_version":"1.0","errortype":"AuthenticationError","error":"Invalid credentials","data":{}}"""),
            authenticated = false,
        )
        assertTrue(error is JoineryApiError.Authentication)
        assertFalse("an unauthenticated 401 (login) must not tear down the session", invalidated)
    }

    @Test
    fun authenticated401FiresSessionInvalidated() {
        val c = client()
        var fired = false
        c.sessionInvalidatedHandler = { fired = true }
        c.mapError(
            401,
            env("""{"api_version":"1.0","errortype":"AuthenticationError","error":"Invalid key","data":{}}"""),
            authenticated = true,
        )
        assertTrue(fired)
    }

    @Test
    fun validation422WithFieldMap() {
        val error = client().mapError(
            422,
            env("""{"api_version":"1.0","errortype":"ValidationError","error":"Fix the form","data":{},"validation_errors":{"usr_email":"Bad address"}}"""),
            authenticated = true,
        )
        assertTrue(error is JoineryApiError.Validation)
        error as JoineryApiError.Validation
        assertEquals("Fix the form", error.text)
        assertEquals("Bad address", error.fields["usr_email"])
    }

    @Test
    fun actionError422WithEmptyArrayData() {
        // Real capture: model-save failures 422 as ActionError with data:[].
        val wrapper = JsonValue.parse(fixture("validation_422.json"))
        val error = client().mapError(422, wrapper["body"]!!, authenticated = true)
        assertTrue(error is JoineryApiError.Validation)
        error as JoineryApiError.Validation
        assertTrue(error.text.contains("usr_first_name"))
        assertTrue(error.fields.isEmpty())
    }

    @Test
    fun unknownServerErrorMaps() {
        val error = client().mapError(
            500,
            env("""{"api_version":"1.0","errortype":"ActionError","error":"Boom","data":{}}"""),
            authenticated = true,
        )
        assertTrue(error is JoineryApiError.Server)
        error as JoineryApiError.Server
        assertEquals("ActionError", error.errortype)
        assertEquals(500, error.status)
    }
}
