package com.getjoinery.android

import android.os.Build
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch

/**
 * Native login. The web login page never appears in the app — this screen is
 * the app's one credential entry point (`POST /api/v1/auth/login`).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LoginScreen(session: SessionController) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var showReset by remember { mutableStateOf(false) }
    var showRegister by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val config = session.client.config

    if (showReset) {
        PasswordResetFlow(session.client) { showReset = false }
        return
    }
    if (showRegister) {
        Scaffold(topBar = {
            TopAppBar(
                title = { Text("Register") },
                navigationIcon = {
                    IconButton(onClick = { showRegister = false }) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        }) { padding ->
            FormScreen(
                client = session.client,
                action = "register",
                authenticated = false,
                modifier = Modifier.padding(padding),
            )
        }
        return
    }

    fun signIn() {
        errorMessage = null
        busy = true
        scope.launch {
            try {
                session.login(email, password, deviceLabel = "${Build.MANUFACTURER} ${Build.MODEL}")
            } catch (e: JoineryApiError) {
                // 426 flips the session state globally; everything else shows here.
                if (e !is JoineryApiError.UpgradeRequired) errorMessage = e.displayMessage
            } catch (e: Exception) {
                errorMessage = "Could not sign in. Please try again."
            } finally {
                busy = false
            }
        }
    }

    Column(
        Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(
            config.appName,
            style = MaterialTheme.typography.headlineLarge,
            fontWeight = FontWeight.Bold,
            modifier = Modifier.padding(top = 48.dp),
        )
        Text("Sign in to continue", color = MaterialTheme.colorScheme.outline)

        OutlinedTextField(
            value = email,
            onValueChange = { email = it },
            label = { Text("Email") },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
            modifier = Modifier.fillMaxWidth().testTag("login_email"),
        )
        OutlinedTextField(
            value = password,
            onValueChange = { password = it },
            label = { Text("Password") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
            modifier = Modifier.fillMaxWidth().testTag("login_password"),
        )
        errorMessage?.let {
            Text(it, color = MaterialTheme.colorScheme.error, modifier = Modifier.testTag("login_error"))
        }
        Button(
            onClick = { signIn() },
            enabled = !busy && email.isNotEmpty() && password.isNotEmpty(),
            modifier = Modifier.fillMaxWidth().testTag("login_submit"),
        ) {
            if (busy) CircularProgressIndicator(Modifier.padding(2.dp)) else Text("Sign In")
        }
        TextButton(onClick = { showReset = true }, modifier = Modifier.testTag("login_forgot")) {
            Text("Forgot password?")
        }
        if (config.registrationEnabled) {
            TextButton(onClick = { showRegister = true }, modifier = Modifier.testTag("login_register")) {
                Text("Create an account")
            }
        }
    }
}
