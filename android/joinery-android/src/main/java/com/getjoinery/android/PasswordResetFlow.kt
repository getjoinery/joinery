package com.getjoinery.android

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri

/**
 * Fully native forgot-password: step 1 requests the reset email
 * (`password_reset_1`), the user copies the reset code from that email, and step
 * 2 (`password_reset_2`, code round-tripped via the form's query context) sets
 * the new password. Both steps are server-driven forms.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PasswordResetFlow(client: ApiClient, onDone: () -> Unit) {
    var step by remember { mutableStateOf<ResetStep>(ResetStep.Request) }

    val title = when (step) {
        is ResetStep.Request -> "Reset Password"
        is ResetStep.EnterCode -> "Enter Code"
        is ResetStep.NewPassword -> "New Password"
        is ResetStep.Done -> "Done"
    }

    Scaffold(topBar = {
        TopAppBar(
            title = { Text(title) },
            navigationIcon = {
                IconButton(onClick = onDone) {
                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                }
            },
        )
    }) { padding ->
        val content = Modifier.fillMaxSize().padding(padding)
        when (val current = step) {
            is ResetStep.Request ->
                Column(content) {
                    FormScreen(client, "password_reset_1", authenticated = false) { step = ResetStep.EnterCode }
                    TextButton(
                        onClick = { step = ResetStep.EnterCode },
                        modifier = Modifier.padding(horizontal = 16.dp).testTag("reset_have_code"),
                    ) { Text("I already have a reset code") }
                }
            is ResetStep.EnterCode ->
                CodeEntry(content) { code -> step = ResetStep.NewPassword(code) }
            is ResetStep.NewPassword ->
                FormScreen(
                    client,
                    "password_reset_2",
                    modifier = content,
                    query = listOf("act_code" to current.code),
                    authenticated = false,
                ) { step = ResetStep.Done }
            is ResetStep.Done ->
                Column(
                    content.padding(24.dp),
                    verticalArrangement = Arrangement.spacedBy(16.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                    Text(
                        "Your password has been reset. Sign in with your new password.",
                        modifier = Modifier.testTag("reset_done"),
                    )
                    Button(onClick = onDone, modifier = Modifier.testTag("reset_back_to_login")) {
                        Text("Back to Sign In")
                    }
                }
        }
    }
}

private sealed class ResetStep {
    object Request : ResetStep()
    object EnterCode : ResetStep()
    data class NewPassword(val code: String) : ResetStep()
    object Done : ResetStep()
}

/** The reset email links to the website with a one-time code; natively the user
 *  pastes that code here to continue. */
@Composable
private fun CodeEntry(modifier: Modifier, onContinue: (String) -> Unit) {
    var code by remember { mutableStateOf("") }
    Column(modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
        Text(
            "We emailed you a reset link. Enter the code from that email (the part after “code=” in the link, or paste the whole link).",
            style = MaterialTheme.typography.bodyMedium,
        )
        OutlinedTextField(
            value = code,
            onValueChange = { code = it },
            label = { Text("Reset code") },
            singleLine = true,
            modifier = Modifier.fillMaxWidth().testTag("reset_code"),
        )
        Button(
            onClick = { onContinue(extractCode(code)) },
            enabled = code.trim().isNotEmpty(),
            modifier = Modifier.testTag("reset_code_continue"),
        ) { Text("Continue") }
    }
}

/** Accept either the bare code or a pasted reset URL containing
 *  `act_code=…` / `code=…`. */
private fun extractCode(input: String): String {
    val trimmed = input.trim()
    return try {
        val uri = trimmed.toUri()
        uri.getQueryParameter("act_code")?.takeIf { it.isNotEmpty() }
            ?: uri.getQueryParameter("code")?.takeIf { it.isNotEmpty() }
            ?: trimmed
    } catch (e: Exception) {
        trimmed
    }
}
