package com.getjoinery.android

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class FormStateTest {

    private fun makeDefinition(fieldsJson: String, name: String = "test"): FormDefinition {
        val json = """
            {"schema_version": 1,
             "form": {"name": "$name", "submit_to": "/api/v1/action/$name", "submit_label": "Go"},
             "fields": $fieldsJson}
        """.trimIndent()
        return FormDefinition.from(JsonValue.parse(json))!!
    }

    // MARK: Visibility

    @Test
    fun dropVisibilityRules() {
        val def = makeDefinition(
            """
            [
              {"type": "drop", "name": "kind", "label": "Kind", "value": "a",
               "options": {"a": "A", "b": "B"},
               "visibility_rules": {
                 "a": {"show": ["field_a"], "hide": ["field_b"]},
                 "b": {"show": ["field_b"], "hide": ["field_a"]}
               }},
              {"type": "text", "name": "field_a", "label": "For A"},
              {"type": "text", "name": "field_b", "label": "For B"}
            ]
            """.trimIndent(),
        )
        val state = FormState(def)
        assertFalse(state.hiddenFields.contains("field_a"))
        assertTrue(state.hiddenFields.contains("field_b"))

        state.values["kind"] = "b"
        state.evaluateVisibility()
        assertTrue(state.hiddenFields.contains("field_a"))
        assertFalse(state.hiddenFields.contains("field_b"))
    }

    @Test
    fun checkboxVisibilityKeysOnState() {
        val def = makeDefinition(
            """
            [
              {"type": "checkbox", "name": "repeats", "label": "Repeats", "checked_value": "1",
               "visibility_rules": {
                 "checked": {"show": ["frequency"]},
                 "unchecked": {"hide": ["frequency"]}
               }},
              {"type": "text", "name": "frequency", "label": "Frequency"}
            ]
            """.trimIndent(),
        )
        val state = FormState(def)
        assertTrue(state.hiddenFields.contains("frequency"))

        state.values["repeats"] = "1"
        state.evaluateVisibility()
        assertFalse(state.hiddenFields.contains("frequency"))
    }

    @Test
    fun hiddenByRuleStillSubmits() {
        val def = makeDefinition(
            """
            [
              {"type": "drop", "name": "kind", "label": "Kind", "value": "a",
               "options": {"a": "A", "b": "B"},
               "visibility_rules": {"a": {"hide": ["extra"]}}},
              {"type": "text", "name": "extra", "label": "Extra", "value": "kept"}
            ]
            """.trimIndent(),
        )
        val state = FormState(def)
        assertTrue(state.hiddenFields.contains("extra"))
        // display:none on the web still posts — native matches.
        assertEquals("kept", state.submissionBody()["extra"]?.stringValue)
    }

    // MARK: Submission body

    @Test
    fun checkboxSubmitOmittedWhenUnchecked() {
        val def = makeDefinition(
            """
            [
              {"type": "checkbox", "name": "privacy", "label": "P", "checked_value": "1"},
              {"type": "checkbox", "name": "setcookie", "label": "S", "checked_value": "1", "is_checked": true}
            ]
            """.trimIndent(),
        )
        val body = FormState(def).submissionBody()
        assertNull("unchecked checkbox must be omitted, like a browser POST", body["privacy"])
        assertEquals("1", body["setcookie"]?.stringValue)
    }

    @Test
    fun checkboxListSubmitsArray() {
        val def = makeDefinition(
            """
            [
              {"type": "checkbox_list", "name": "subs", "label": "Subs",
               "options": {"1": "News", "2": "Events", "3": "Offers"},
               "checked": ["2"]}
            ]
            """.trimIndent(),
        )
        val state = FormState(def)
        assertEquals(listOf("2"), state.listValues["subs"])
        state.listValues["subs"] = listOf("2", "3")
        val body = state.submissionBody()
        assertEquals(listOf("2", "3"), body["subs"]?.arrayValue?.mapNotNull { it.stringValue })
    }

    @Test
    fun hiddenFieldRoundTrips() {
        val def = makeDefinition(
            """
            [
              {"type": "hidden", "name": "act_code", "value": "CODE42"},
              {"type": "password", "name": "usr_password", "label": "New"}
            ]
            """.trimIndent(),
        )
        val state = FormState(def)
        state.values["usr_password"] = "hunter22"
        val body = state.submissionBody()
        assertEquals("CODE42", body["act_code"]?.stringValue)
        assertEquals("hunter22", body["usr_password"]?.stringValue)
    }

    @Test
    fun datetimeSubmitParts() {
        val def = makeDefinition(
            """
            [
              {"type": "datetime", "name": "evt_start", "label": "Starts",
               "value": "2026-07-04 14:30:00",
               "submit_parts": {"date": "evt_start_dateinput", "hour": "evt_start_timeinput_hour",
                                "minute": "evt_start_timeinput_minute", "ampm": "evt_start_timeinput_ampm"}}
            ]
            """.trimIndent(),
        )
        val body = FormState(def).submissionBody()
        assertEquals("2026-07-04", body["evt_start_dateinput"]?.stringValue)
        assertEquals("2", body["evt_start_timeinput_hour"]?.stringValue)
        assertEquals("30", body["evt_start_timeinput_minute"]?.stringValue)
        assertEquals("PM", body["evt_start_timeinput_ampm"]?.stringValue)
    }

    // MARK: Client-side validation + server error mapping

    @Test
    fun requiredValidationBlocksSubmit() {
        val def = makeDefinition(
            """
            [
              {"type": "text", "name": "usr_email", "label": "Email", "required": true},
              {"type": "text", "name": "optional_bit", "label": "Optional"}
            ]
            """.trimIndent(),
        )
        val state = FormState(def)
        assertFalse(state.validateForSubmit())
        assertNotNull(state.fieldErrors["usr_email"])
        state.values["usr_email"] = "a@b.com"
        assertTrue(state.validateForSubmit())
    }

    @Test
    fun hiddenRequiredFieldNotValidated() {
        val def = makeDefinition(
            """
            [
              {"type": "drop", "name": "kind", "label": "Kind", "value": "a",
               "options": {"a": "A"}, "visibility_rules": {"a": {"hide": ["gone"]}}},
              {"type": "text", "name": "gone", "label": "Gone", "required": true}
            ]
            """.trimIndent(),
        )
        assertTrue("rule-hidden required fields must not block submit", FormState(def).validateForSubmit())
    }

    @Test
    fun serverErrorsMapOntoFields() {
        val def = makeDefinition("""[{"type": "text", "name": "usr_email", "label": "Email"}]""")
        val state = FormState(def)
        state.apply(JoineryApiError.Validation("Fix the form", mapOf("usr_email" to "Bad address")))
        assertEquals("Bad address", state.fieldErrors["usr_email"])
        assertNull(state.formError)

        state.apply(JoineryApiError.Validation("Top-level only", emptyMap()))
        assertEquals("Top-level only", state.formError)
    }
}
