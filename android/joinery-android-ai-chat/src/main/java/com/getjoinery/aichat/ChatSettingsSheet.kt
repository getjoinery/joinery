package com.getjoinery.aichat

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties

/**
 * Per-chat settings: model, capability toggles, reasoning effort, and
 * sampling / instructions. Picker and toggle changes apply immediately
 * (persisted for an existing chat, seeded onto a new chat's first send); the
 * free-text fields commit on Done. The same controls and validator the web
 * status strip uses.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ChatSettingsSheet(store: ChatThreadStore, onDismiss: () -> Unit) {
    // Free-text fields commit on Done rather than each keystroke.
    var temperature by remember { mutableStateOf("") }
    var topP by remember { mutableStateOf("") }
    var maxTokens by remember { mutableStateOf("") }
    var instructions by remember { mutableStateOf("") }
    var synced by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        store.loadMeta()
        if (!synced) {
            temperature = store.controls.temperature
            topP = store.controls.topP
            maxTokens = store.controls.maxTokens
            instructions = store.controls.instructions
            synced = true
        }
    }

    fun commitText() {
        if (temperature != store.controls.temperature) {
            store.setControl("temperature", temperature) { it.copy(temperature = temperature) }
        }
        if (topP != store.controls.topP) {
            store.setControl("top_p", topP) { it.copy(topP = topP) }
        }
        if (maxTokens != store.controls.maxTokens) {
            store.setControl("max_tokens", maxTokens) { it.copy(maxTokens = maxTokens) }
        }
        if (instructions != store.controls.instructions) {
            store.setControl("instructions", instructions) { it.copy(instructions = instructions) }
        }
    }

    Dialog(
        onDismissRequest = { commitText(); onDismiss() },
        properties = DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Surface(Modifier.fillMaxSize()) {
            Scaffold(topBar = {
                TopAppBar(
                    title = { Text("Chat settings") },
                    navigationIcon = {
                        IconButton(onClick = { commitText(); onDismiss() }) {
                            Icon(Icons.Outlined.Close, contentDescription = "Close")
                        }
                    },
                    actions = {
                        TextButton(
                            onClick = { commitText(); onDismiss() },
                            modifier = Modifier.testTag("chat_settings_done"),
                        ) { Text("Done") }
                    },
                )
            }) { padding ->
                val meta = store.meta
                if (meta == null) {
                    Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(Modifier.testTag("chat_settings_loading"))
                    }
                } else {
                    Column(
                        Modifier
                            .fillMaxSize()
                            .padding(padding)
                            .verticalScroll(rememberScrollState())
                            .padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        SettingsDropdown(
                            label = "Model",
                            options = meta.models.map { it.id to it.label },
                            selected = store.controls.model.ifEmpty { meta.defaults.model },
                            tag = "chat_set_model",
                        ) { value ->
                            store.setControl("model", value) { it.copy(model = value) }
                        }
                        if (!meta.isPrivate(store.controls.model)) {
                            Text(
                                "This model isn't private — avoid sending sensitive personal data.",
                                style = MaterialTheme.typography.labelMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        HorizontalDivider()
                        SettingsSwitch(
                            label = "Data access",
                            checked = store.controls.dataAccess,
                            tag = "chat_set_data_access",
                        ) { on ->
                            store.setControl("data_access", if (on) "1" else "0") { it.copy(dataAccess = on) }
                        }
                        SettingsSwitch(
                            label = "Web search",
                            checked = store.controls.webSearch,
                            enabled = meta.webSearchAvailable,
                            tag = "chat_set_web_search",
                        ) { on ->
                            store.setControl("web_search", if (on) "1" else "0") { it.copy(webSearch = on) }
                        }
                        Text(
                            "Data access lets the assistant read your data and, with your confirmation, make changes.",
                            style = MaterialTheme.typography.labelMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        HorizontalDivider()
                        SettingsDropdown(
                            label = "Thinking",
                            options = listOf("off", "low", "medium", "high").map {
                                it to it.replaceFirstChar { c -> c.uppercase() }
                            },
                            selected = store.controls.thinkingLevel,
                            tag = "chat_set_thinking",
                        ) { value ->
                            store.setControl("thinking_level", value) { it.copy(thinkingLevel = value) }
                        }
                        HorizontalDivider()
                        NumberField("Temperature", temperature, meta.defaults.temperature) { temperature = it }
                        NumberField("Top-p", topP, meta.defaults.topP) { topP = it }
                        NumberField("Max tokens", maxTokens, meta.defaults.maxTokens) { maxTokens = it }
                        Text(
                            "Leave blank to use the default.",
                            style = MaterialTheme.typography.labelMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        HorizontalDivider()
                        OutlinedTextField(
                            value = instructions,
                            onValueChange = { instructions = it },
                            label = { Text("Custom instructions (optional)") },
                            minLines = 3,
                            maxLines = 8,
                            modifier = Modifier.fillMaxWidth().testTag("chat_set_instructions"),
                        )
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SettingsDropdown(
    label: String,
    options: List<Pair<String, String>>,
    selected: String,
    tag: String,
    onSelect: (String) -> Unit,
) {
    var open by remember { mutableStateOf(false) }
    val current = options.firstOrNull { it.first == selected }?.second ?: selected

    ExposedDropdownMenuBox(expanded = open, onExpandedChange = { open = it }) {
        OutlinedTextField(
            value = current,
            onValueChange = {},
            readOnly = true,
            label = { Text(label) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = open) },
            modifier = Modifier.fillMaxWidth().menuAnchor().testTag(tag),
        )
        ExposedDropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            options.forEach { (value, text) ->
                DropdownMenuItem(
                    text = { Text(text) },
                    onClick = {
                        open = false
                        onSelect(value)
                    },
                )
            }
        }
    }
}

@Composable
private fun SettingsSwitch(
    label: String,
    checked: Boolean,
    enabled: Boolean = true,
    tag: String,
    onChange: (Boolean) -> Unit,
) {
    Row(
        Modifier.fillMaxWidth().testTag(tag),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, modifier = Modifier.weight(1f))
        Switch(checked = checked, onCheckedChange = onChange, enabled = enabled)
    }
}

@Composable
private fun NumberField(
    label: String,
    value: String,
    placeholder: String,
    onChange: (String) -> Unit,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label) },
        placeholder = { Text(placeholder.ifEmpty { "default" }) },
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
        modifier = Modifier.fillMaxWidth(),
    )
}
