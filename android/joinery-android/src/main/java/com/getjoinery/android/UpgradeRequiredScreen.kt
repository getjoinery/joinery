package com.getjoinery.android

import android.content.Intent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.KeyboardArrowUp
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri

/**
 * Blocking screen for HTTP 426 UpgradeRequired — this build is below the
 * server's minimum for its `client_app`. Nothing in the app is reachable until
 * the user updates (the gate applies to every endpoint, login included).
 */
@Composable
fun UpgradeRequiredScreen(config: JoineryConfig, message: String) {
    val context = LocalContext.current
    Column(
        Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(
            Icons.Filled.KeyboardArrowUp,
            contentDescription = null,
            tint = config.accentColor,
            modifier = Modifier.padding(bottom = 12.dp),
        )
        Text(
            "Update Required",
            style = MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.Bold,
            modifier = Modifier.testTag("upgrade_title"),
        )
        Text(
            message.ifEmpty {
                "This version of ${config.appName} is no longer supported. Please update to continue."
            },
            textAlign = TextAlign.Center,
            color = MaterialTheme.colorScheme.outline,
            modifier = Modifier.padding(vertical = 16.dp).testTag("upgrade_message"),
        )
        config.playStoreUrl?.let { url ->
            Button(
                onClick = { context.startActivity(Intent(Intent.ACTION_VIEW, url.toUri())) },
                modifier = Modifier.fillMaxWidth().testTag("upgrade_store_button"),
            ) { Text("Update in the Play Store") }
        }
    }
}
