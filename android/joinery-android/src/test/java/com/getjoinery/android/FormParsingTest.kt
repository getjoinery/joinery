package com.getjoinery.android

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class FormParsingTest {

    private fun definition(fixtureName: String): FormDefinition {
        val envelope = JsonValue.parse(fixture(fixtureName))
        return FormDefinition.from(envelope["data"]) ?: error("definition failed to parse")
    }

    @Test
    fun registerFormParses() {
        val def = definition("form_register.json")
        assertEquals("register", def.name)
        assertEquals("/api/v1/action/register", def.submitTo)
        assertEquals("Register Now", def.submitLabel)
        assertTrue(def.isRenderable)

        assertEquals(
            listOf("usr_first_name", "usr_last_name", "usr_nickname", "usr_email",
                "password", "usr_timezone", "privacy", "newsletter", "setcookie"),
            def.fields.map { it.name },
        )

        val email = def.fields.first { it.name == "usr_email" }
        assertEquals(FormFieldType.Text, email.type)
        assertEquals("email", email.inputType)
        assertTrue(email.required)
        assertEquals(64, email.maxlength)

        val setcookie = def.fields.first { it.name == "setcookie" }
        assertEquals(FormFieldType.Checkbox, setcookie.type)
        assertTrue(setcookie.isChecked)
        assertEquals("1", setcookie.checkedValue)

        assertFalse(def.fields.first { it.name == "privacy" }.isChecked)
    }

    @Test
    fun timezoneOptionsKeepServerOrder() {
        val tz = definition("form_register.json").fields.first { it.name == "usr_timezone" }
        assertEquals(FormFieldType.Drop, tz.type)
        assertTrue(tz.options.size > 100)
        // Server emits Africa/Abidjan first; unordered decoding would scramble.
        assertEquals("Africa/Abidjan", tz.options.first().value)
    }

    @Test
    fun passwordReset2CarriesHiddenCode() {
        val def = definition("form_password_reset_2.json")
        val hidden = def.fields.first { it.name == "act_code" }
        assertEquals(FormFieldType.Hidden, hidden.type)
        assertEquals("SAMPLECODE123", hidden.value?.stringValue)
        assertEquals(2, def.fields.count { it.type == FormFieldType.Password })
    }

    @Test
    fun contactPreferencesCheckboxList() {
        val def = definition("form_contact_preferences.json")
        val list = def.fields.first { it.name == "new_list_subscribes" }
        assertEquals(FormFieldType.CheckboxList, list.type)
        assertTrue(list.options.isNotEmpty())
        assertTrue(def.isRenderable)
    }

    @Test
    fun unknownFieldTypeMakesFormUnrenderable() {
        val json = """
            {"schema_version": 1,
             "form": {"name": "x", "submit_to": "/api/v1/action/x", "submit_label": "Go"},
             "fields": [
                {"type": "text", "name": "a", "label": "A"},
                {"type": "hologram", "name": "b", "label": "B"}
             ]}
        """.trimIndent()
        assertFalse(FormDefinition.from(JsonValue.parse(json))!!.isRenderable)
    }

    @Test
    fun newerSchemaVersionMakesFormUnrenderable() {
        val json = """
            {"schema_version": 2,
             "form": {"name": "x", "submit_to": "/api/v1/action/x", "submit_label": "Go"},
             "fields": [{"type": "text", "name": "a", "label": "A"}]}
        """.trimIndent()
        assertFalse(FormDefinition.from(JsonValue.parse(json))!!.isRenderable)
    }

    @Test
    fun loginAndSessionSummaries() {
        val login = JsonValue.parse(fixture("login.json"))
        val result = LoginResult.from(login["data"])
        assertNotNull(result)
        assertEquals("appdev.phase2@inbox.dev.getjoinery.com", result?.user?.email)
        assertNull(result?.user?.tier)
        assertNotNull(result?.expiresTime)

        val session = JsonValue.parse(fixture("session.json"))
        val user = UserSummary.from(session["data"])
        assertEquals("AppDev PhaseTwo", user?.displayName)
        assertEquals(0, user?.permission)
    }

    @Test
    fun timestampParsing() {
        val date = JoineryTimestamp.parse("2027-07-02 21:06:35")
        assertNotNull(date)
        assertEquals("2027-07-02 21:06:35", JoineryTimestamp.format(date!!))
        assertNull(JoineryTimestamp.parse(null))
        assertNull(JoineryTimestamp.parse(""))
    }
}
