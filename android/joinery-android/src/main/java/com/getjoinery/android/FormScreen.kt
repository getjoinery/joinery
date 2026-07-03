package com.getjoinery.android

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowDropDown
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

/**
 * Generic server-driven form screen: fetches `GET /api/v1/form/{action}`,
 * renders the definition with the shared field renderer, submits to
 * `POST /api/v1/action/{action}`, and maps field errors back onto controls.
 * Every account form in every Joinery app is this one screen.
 */
@Composable
fun FormScreen(
    client: ApiClient,
    action: String,
    modifier: Modifier = Modifier,
    query: List<Pair<String, String>> = emptyList(),
    authenticated: Boolean = true,
    onSuccess: (JsonValue) -> Unit = {},
) {
    var phase by remember(action) { mutableStateOf<FormPhase>(FormPhase.Loading) }

    suspend fun load() {
        phase = FormPhase.Loading
        try {
            val definition = client.formDefinition(action, query, authenticated)
            phase = if (definition.isRenderable) FormPhase.Ready(FormState(definition)) else FormPhase.Unsupported
        } catch (e: JoineryApiError) {
            phase = FormPhase.Failed(e.displayMessage)
        } catch (e: Exception) {
            phase = FormPhase.Failed("Could not load the form.")
        }
    }

    androidx.compose.runtime.LaunchedEffect(action) { load() }
    val scope = rememberCoroutineScope()

    when (val current = phase) {
        is FormPhase.Loading ->
            Column(modifier.fillMaxWidth().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                CircularProgressIndicator(Modifier.testTag("form_loading"))
            }
        is FormPhase.Failed ->
            Column(modifier.fillMaxWidth().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(current.message, Modifier.testTag("form_load_error"))
                TextButton(onClick = { scope.launch { load() } }) { Text("Try Again") }
            }
        is FormPhase.Unsupported ->
            Column(modifier.fillMaxWidth().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(
                    "This form needs a newer version of the app. Please update, or use the website.",
                    Modifier.testTag("form_unsupported"),
                )
            }
        is FormPhase.Ready ->
            FormBody(current.state, client, authenticated, onSuccess, modifier)
    }
}

private sealed class FormPhase {
    object Loading : FormPhase()
    data class Failed(val message: String) : FormPhase()
    object Unsupported : FormPhase()
    data class Ready(val state: FormState) : FormPhase()
}

/** The rendered form: shared by FormScreen and the password-reset flow. */
@Composable
fun FormBody(
    state: FormState,
    client: ApiClient,
    authenticated: Boolean,
    onSuccess: (JsonValue) -> Unit,
    modifier: Modifier = Modifier,
) {
    var submitting by remember { mutableStateOf(false) }
    var successMessage by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    fun submit() {
        state.formError = null
        successMessage = null
        if (!state.validateForSubmit()) return
        submitting = true
        scope.launch {
            try {
                val envelope = client.submitAction(
                    actionName(state.definition.submitTo, state.definition.name),
                    state.submissionBody(),
                    authenticated,
                )
                state.fieldErrors.clear()
                val msg = envelope["success_message"]?.stringValue
                successMessage = if (msg.isNullOrEmpty()) "Saved." else msg
                onSuccess(envelope)
            } catch (e: JoineryApiError) {
                state.apply(e)
            } catch (e: Exception) {
                state.formError = "Something went wrong. Please try again."
            } finally {
                submitting = false
            }
        }
    }

    Column(
        modifier
            .fillMaxWidth()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        state.formError?.let {
            Text(it, color = MaterialTheme.colorScheme.error, modifier = Modifier.testTag("form_error"))
        }
        successMessage?.let {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                Text(it, modifier = Modifier.testTag("form_success"))
            }
        }
        for (field in state.definition.fields) {
            if (!state.isVisible(field)) continue
            FormFieldRow(field, state)
        }
        Button(
            onClick = { submit() },
            enabled = !submitting,
            modifier = Modifier.fillMaxWidth().testTag("form_submit"),
        ) {
            if (submitting) CircularProgressIndicator(Modifier.padding(2.dp)) else Text(state.definition.submitLabel)
        }
    }
}

/** `submit_to` is `/api/v1/action/{action}` (possibly plugin-namespaced); strip
 *  the prefix for submitAction, which re-adds it. */
private fun actionName(submitTo: String, fallback: String): String {
    val prefix = "/api/v1/action/"
    return if (submitTo.startsWith(prefix)) submitTo.substring(prefix.length) else fallback
}

/**
 * One field row. Test tags equal field names so instrumentation tests and
 * screenshots address controls stably.
 */
@Composable
private fun FormFieldRow(field: FormField, state: FormState) {
    val enabled = !field.readonly && !field.disabled
    val error = state.fieldErrors[field.name]

    Column(verticalArrangement = Arrangement.spacedBy(4.dp), modifier = Modifier.fillMaxWidth()) {
        when (field.type) {
            is FormFieldType.Text, is FormFieldType.Number -> {
                OutlinedTextField(
                    value = state.values[field.name] ?: "",
                    onValueChange = { state.values[field.name] = it },
                    label = { Text(field.label) },
                    placeholder = { if (field.placeholder.isNotEmpty()) Text(field.placeholder) },
                    singleLine = true,
                    isError = error != null,
                    enabled = enabled,
                    keyboardOptions = KeyboardOptions(keyboardType = keyboardType(field)),
                    modifier = Modifier.fillMaxWidth().testTag(field.name),
                )
            }
            is FormFieldType.Password -> {
                OutlinedTextField(
                    value = state.values[field.name] ?: "",
                    onValueChange = { state.values[field.name] = it },
                    label = { Text(field.label) },
                    singleLine = true,
                    isError = error != null,
                    enabled = enabled,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                    modifier = Modifier.fillMaxWidth().testTag(field.name),
                )
            }
            is FormFieldType.Textarea -> {
                OutlinedTextField(
                    value = state.values[field.name] ?: "",
                    onValueChange = { state.values[field.name] = it },
                    label = { Text(field.label) },
                    isError = error != null,
                    enabled = enabled,
                    minLines = 3,
                    modifier = Modifier.fillMaxWidth().testTag(field.name),
                )
            }
            is FormFieldType.Drop -> {
                var expanded by remember { mutableStateOf(false) }
                val current = state.values[field.name] ?: ""
                val shown = field.options.firstOrNull { it.value == current }?.label
                    ?: field.emptyOption ?: ""
                Box {
                    OutlinedTextField(
                        value = shown,
                        onValueChange = {},
                        readOnly = true,
                        enabled = false,
                        label = { Text(field.label) },
                        trailingIcon = { Icon(Icons.Filled.ArrowDropDown, contentDescription = null) },
                        modifier = Modifier.fillMaxWidth().testTag(field.name).clickable(enabled) { expanded = true },
                    )
                    DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
                        field.emptyOption?.let { empty ->
                            DropdownMenuItem(text = { Text(empty) }, onClick = {
                                state.values[field.name] = ""; expanded = false; state.evaluateVisibility()
                            })
                        }
                        for (option in field.options) {
                            DropdownMenuItem(text = { Text(option.label) }, onClick = {
                                state.values[field.name] = option.value; expanded = false; state.evaluateVisibility()
                            })
                        }
                    }
                }
            }
            is FormFieldType.Checkbox -> {
                val checked = (state.values[field.name] ?: "").isNotEmpty()
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().testTag(field.name)) {
                    Checkbox(checked = checked, enabled = enabled, onCheckedChange = {
                        state.values[field.name] = if (it) field.checkedValue else ""
                        state.evaluateVisibility()
                    })
                    Text(field.label)
                }
            }
            is FormFieldType.Radio -> {
                Text(field.label, style = MaterialTheme.typography.labelLarge)
                Column(Modifier.testTag(field.name)) {
                    for (option in field.options) {
                        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().clickable(enabled) {
                            state.values[field.name] = option.value; state.evaluateVisibility()
                        }) {
                            RadioButton(selected = state.values[field.name] == option.value, enabled = enabled, onClick = {
                                state.values[field.name] = option.value; state.evaluateVisibility()
                            })
                            Text(option.label)
                        }
                    }
                }
            }
            is FormFieldType.CheckboxList -> {
                if (field.label.isNotEmpty()) Text(field.label, style = MaterialTheme.typography.labelLarge)
                if (field.listType == "radio") {
                    Column(Modifier.testTag(field.name)) {
                        for (option in field.options) {
                            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().clickable(enabled) {
                                state.values[field.name] = option.value; state.evaluateVisibility()
                            }) {
                                RadioButton(selected = state.values[field.name] == option.value, enabled = enabled, onClick = {
                                    state.values[field.name] = option.value; state.evaluateVisibility()
                                })
                                Text(option.label)
                            }
                        }
                    }
                } else {
                    Column {
                        for (option in field.options) {
                            val selected = state.listValues[field.name] ?: emptyList()
                            val optionEnabled = enabled && !field.disabledValues.contains(option.value)
                            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().testTag("${field.name}_${option.value}")) {
                                Checkbox(checked = selected.contains(option.value), enabled = optionEnabled, onCheckedChange = { on ->
                                    val current = ArrayList(state.listValues[field.name] ?: emptyList())
                                    if (on) { if (!current.contains(option.value)) current.add(option.value) }
                                    else current.remove(option.value)
                                    state.listValues[field.name] = current
                                })
                                Text(option.label)
                            }
                        }
                    }
                }
            }
            is FormFieldType.Date -> DatePickerField(field, state, enabled)
            is FormFieldType.Time -> TimePickerField(field, state, enabled)
            is FormFieldType.Datetime -> DatetimePickerField(field, state, enabled)
            is FormFieldType.Hidden -> {}
            is FormFieldType.Unknown -> {
                // FormDefinition.isRenderable gates whole forms; reaching here
                // means the caller skipped the gate — stay honest.
                Text("Unsupported field: ${field.name}", color = MaterialTheme.colorScheme.outline)
            }
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error, modifier = Modifier.testTag("${field.name}_error")) }
        if (field.helptext.isNotEmpty()) {
            Text(field.helptext, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.outline)
        }
    }
}

@Composable
private fun DatePickerField(field: FormField, state: FormState, enabled: Boolean) {
    val context = LocalContext.current
    val current = state.values[field.name] ?: ""
    OutlinedTextField(
        value = current,
        onValueChange = {},
        readOnly = true,
        enabled = false,
        label = { Text(field.label) },
        modifier = Modifier.fillMaxWidth().testTag(field.name).clickable(enabled) {
            val cal = Calendar.getInstance()
            SimpleDateFormat("yyyy-MM-dd", Locale.US).parse(current)?.let { cal.time = it }
            DatePickerDialog(context, { _, y, m, d ->
                state.values[field.name] = "%04d-%02d-%02d".format(y, m + 1, d)
            }, cal.get(Calendar.YEAR), cal.get(Calendar.MONTH), cal.get(Calendar.DAY_OF_MONTH)).show()
        },
    )
}

@Composable
private fun TimePickerField(field: FormField, state: FormState, enabled: Boolean) {
    val context = LocalContext.current
    val current = state.values[field.name] ?: ""
    OutlinedTextField(
        value = current,
        onValueChange = {},
        readOnly = true,
        enabled = false,
        label = { Text(field.label) },
        modifier = Modifier.fillMaxWidth().testTag(field.name).clickable(enabled) {
            val cal = Calendar.getInstance()
            SimpleDateFormat("HH:mm", Locale.US).parse(current)?.let { cal.time = it }
            TimePickerDialog(context, { _, h, min ->
                state.values[field.name] = "%02d:%02d".format(h, min)
            }, cal.get(Calendar.HOUR_OF_DAY), cal.get(Calendar.MINUTE), true).show()
        },
    )
}

@Composable
private fun DatetimePickerField(field: FormField, state: FormState, enabled: Boolean) {
    val context = LocalContext.current
    val millis = state.dateValues[field.name]
    val display = millis?.let { SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.US).format(it) } ?: ""
    OutlinedTextField(
        value = display,
        onValueChange = {},
        readOnly = true,
        enabled = false,
        label = { Text(field.label) },
        modifier = Modifier.fillMaxWidth().testTag(field.name).clickable(enabled) {
            val cal = Calendar.getInstance()
            millis?.let { cal.timeInMillis = it }
            DatePickerDialog(context, { _, y, m, d ->
                TimePickerDialog(context, { _, h, min ->
                    val picked = Calendar.getInstance()
                    picked.set(y, m, d, h, min, 0)
                    state.dateValues[field.name] = picked.timeInMillis
                }, cal.get(Calendar.HOUR_OF_DAY), cal.get(Calendar.MINUTE), false).show()
            }, cal.get(Calendar.YEAR), cal.get(Calendar.MONTH), cal.get(Calendar.DAY_OF_MONTH)).show()
        },
    )
}

private fun keyboardType(field: FormField): KeyboardType = when (field.inputType) {
    "email" -> KeyboardType.Email
    "url" -> KeyboardType.Uri
    "tel" -> KeyboardType.Phone
    else -> if (field.type is FormFieldType.Number) KeyboardType.Number else KeyboardType.Text
}
