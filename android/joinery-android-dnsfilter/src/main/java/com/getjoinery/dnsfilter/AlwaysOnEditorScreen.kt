package com.getjoinery.dnsfilter

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.getjoinery.android.ApiClient
import kotlinx.coroutines.launch

/**
 * The native always-on block editor: category filters (general are the free
 * floor; advanced are tier-gated), service toggles, and custom domain rules.
 * Every toggle is save-on-change through `block_filter_set`; "off" removes the
 * row (Allow = no row). The server re-enforces every gate, so locked states
 * here are presentation only.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AlwaysOnEditorScreen(
    client: ApiClient,
    account: DnsAccountSummary,
    deviceId: Int,
    blockId: Int,
    onBack: (() -> Unit)? = null,
    onHardBlockChange: (() -> Unit)? = null,
) {
    val store = remember {
        BlockEditorStore(DnsFilterApi(client), account, deviceId, blockId).apply {
            this.onHardBlockChange = onHardBlockChange
        }
    }
    val scope = rememberCoroutineScope()
    onBack?.let { BackHandler(onBack = it) }

    LaunchedEffect(Unit) { if (store.phase is DnsPhase.Loading) store.load() }

    Scaffold(topBar = { DnsTopBar("Always-On Rules", onBack) }) { padding ->
        when (val phase = store.phase) {
            is DnsPhase.Loading ->
                Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.testTag("editor_loading"))
                }
            is DnsPhase.Failed ->
                DnsRetryBox(phase.message, "editor_error", "editor_retry", Modifier.padding(padding)) {
                    scope.launch { store.load() }
                }
            is DnsPhase.Loaded ->
                Editor(store, Modifier.padding(padding), scope)
        }
    }
}

@Composable
private fun Editor(store: BlockEditorStore, modifier: Modifier, scope: kotlinx.coroutines.CoroutineScope) {
    val catalog = store.catalog
    LazyColumn(modifier.fillMaxSize().testTag("editor_list")) {
        if (catalog != null) {
            item { SectionHeader("Content Categories") }
            catalog.generalFilters.forEach { filter ->
                item { FilterRow(store, filter, gated = false, scope) }
            }
            if (catalog.advancedFiltersList.isNotEmpty()) {
                item { SectionHeader("Advanced Protection", locked = !store.account.advancedFilters) }
                catalog.advancedFiltersList.forEach { filter ->
                    item { FilterRow(store, filter, gated = !store.account.advancedFilters, scope) }
                }
            }
            catalog.serviceCategories.forEach { category ->
                val items = catalog.services[category.key]
                if (!items.isNullOrEmpty()) {
                    item { SectionHeader(category.label) }
                    items.forEach { service ->
                        item { ServiceRow(store, service, scope) }
                    }
                }
            }
        }
        item { HorizontalDivider() }
        item { CustomRulesSection(store, scope) }
    }
}

@Composable
private fun SectionHeader(text: String, locked: Boolean = false) {
    Row(
        Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = 16.dp, bottom = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Text(text, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.primary)
        if (locked) Icon(Icons.Filled.Lock, contentDescription = "Locked", tint = MaterialTheme.colorScheme.outline, modifier = Modifier.size(14.dp))
    }
}

@Composable
private fun FilterRow(store: BlockEditorStore, filter: DnsCatalogFilter, gated: Boolean, scope: kotlinx.coroutines.CoroutineScope) {
    val isBlocked = store.isFilterBlocked(filter.key)
    // A downgraded user can still turn an advanced filter OFF (remove the row),
    // just not on — mirrors the server's escape hatch.
    val disabled = store.isBusy(filter.key) || (gated && !isBlocked)
    ToggleRow(filter.label, isBlocked, disabled, "filter_${filter.key}") {
        scope.launch { store.toggleFilter(filter.key) }
    }
}

@Composable
private fun ServiceRow(store: BlockEditorStore, service: DnsCatalogService, scope: kotlinx.coroutines.CoroutineScope) {
    ToggleRow(service.label, store.isServiceBlocked(service.key), store.isBusy(service.key), "service_${service.key}") {
        scope.launch { store.toggleService(service.key) }
    }
}

@Composable
private fun ToggleRow(label: String, checked: Boolean, disabled: Boolean, tag: String, onToggle: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp).testTag(tag),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        Switch(checked = checked, onCheckedChange = { onToggle() }, enabled = !disabled)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CustomRulesSection(store: BlockEditorStore, scope: kotlinx.coroutines.CoroutineScope) {
    SectionHeader("Custom Domain Rules", locked = !store.account.customRules)
    if (!store.account.customRules) {
        Row(
            Modifier.fillMaxWidth().padding(16.dp).testTag("custom_rules_locked"),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Icon(Icons.Filled.Lock, contentDescription = null, tint = MaterialTheme.colorScheme.outline)
            Text(
                "Block or allow specific sites on Premium and Pro.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.outline,
            )
        }
        return
    }

    Column {
        store.rules.forEach { rule -> RuleRow(store, rule, scope) }
        AddRuleForm(store, scope)
    }
}

@Composable
private fun RuleRow(store: BlockEditorStore, rule: DnsDomainRule, scope: kotlinx.coroutines.CoroutineScope) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(rule.hostname, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Medium)
            Text(
                buildString {
                    append(if (rule.isBlock) "Block" else "Allow")
                    if (rule.hardBlock) append(" · Hard block")
                },
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.outline,
            )
        }
        if (rule.isBlock) {
            FilterChip(
                selected = rule.hardBlock,
                onClick = { scope.launch { store.setHardBlock(rule, !rule.hardBlock) } },
                label = { Text("Hard") },
                modifier = Modifier.testTag("rule_hardblock_${rule.ruleId}"),
            )
        }
        IconButton(
            onClick = { scope.launch { store.deleteRule(rule) } },
            modifier = Modifier.testTag("rule_delete_${rule.ruleId}"),
        ) { Icon(Icons.Filled.Delete, contentDescription = "Delete") }
    }
}

@Composable
private fun AddRuleForm(store: BlockEditorStore, scope: kotlinx.coroutines.CoroutineScope) {
    var hostname by remember { mutableStateOf("") }
    var action by remember { mutableStateOf(0) } // 0 block, 1 allow
    var hardBlock by remember { mutableStateOf(false) }

    Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedTextField(
            value = hostname,
            onValueChange = { hostname = it },
            singleLine = true,
            label = { Text("example.com") },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri, capitalization = KeyboardCapitalization.None),
            modifier = Modifier.fillMaxWidth().testTag("rule_hostname_field"),
        )
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(selected = action == 0, onClick = { action = 0 }, label = { Text("Block") })
            FilterChip(selected = action == 1, onClick = { action = 1 }, label = { Text("Allow") })
        }
        if (action == 0) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Hard block (Strict mode)", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
                Switch(checked = hardBlock, onCheckedChange = { hardBlock = it })
            }
        }
        Button(
            onClick = {
                val host = hostname
                val a = action
                val hard = a == 0 && hardBlock
                scope.launch {
                    store.addRule(host, a, hard)
                    hostname = ""
                    hardBlock = false
                }
            },
            enabled = hostname.trim().isNotEmpty(),
            modifier = Modifier.fillMaxWidth().testTag("rule_add_button"),
        ) { Text("Add rule") }
    }
}
